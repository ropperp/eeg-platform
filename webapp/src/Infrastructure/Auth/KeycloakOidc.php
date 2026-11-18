<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Anmeldung des Portals über Keycloak mit OpenID Connect (Authorization Code + PKCE),
 * docs/adr/0007-keycloak.md.
 *
 * Der Browser geht zur öffentlichen Adresse (auth.<DOMAIN>), Code-Tausch und Schlüssel laufen intern
 * (http://keycloak:8080). Das ID-Token wird trotzdem vollständig geprüft (Signatur, Aussteller,
 * Zielgruppe, Ablauf, nonce).
 */
final class KeycloakOidc
{
    public function __construct(private readonly KeycloakConfig $cfg)
    {
    }

    /**
     * Startet eine Anmeldung: legt state/nonce/PKCE-Verifier in $session ab und liefert die Adresse
     * der Keycloak-Anmeldeseite.
     *
     * @param array<string,mixed> $session z. B. $_SESSION['oidc']
     * @param array<string,string> $extra zusätzliche Parameter (kc_action, kc_idp_hint, prompt ...)
     */
    public function authorizationUrl(array &$session, string $purpose = 'login', array $extra = []): string
    {
        $state = Jwt::b64e(random_bytes(24));
        $nonce = Jwt::b64e(random_bytes(24));
        $verifier = Jwt::b64e(random_bytes(48));
        $session = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'purpose' => $purpose, 't' => time()];
        return $this->cfg->publicEndpoint('auth') . '?' . http_build_query(array_merge([
            'client_id' => $this->cfg->clientId,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'redirect_uri' => $this->cfg->redirectUri(),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => Jwt::b64e(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], $extra), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Prüft die Rückkehr von Keycloak und tauscht den Code gegen Tokens.
     *
     * @param array<string,mixed>|null $session gespeicherter Zustand aus authorizationUrl() (wird verbraucht)
     * @param array<string,mixed> $query $_GET der Rückkehr
     * @return array{claims: array<string,mixed>, id_token: string, purpose: string}
     */
    public function handleCallback(?array $session, array $query): array
    {
        if (!is_array($session) || empty($session['state']) || time() - (int) ($session['t'] ?? 0) > 900) {
            throw new OidcException('Die Anmeldung ist abgelaufen. Bitte noch einmal anmelden.');
        }
        if (!hash_equals((string) $session['state'], (string) ($query['state'] ?? ''))) {
            throw new OidcException('Die Anmeldung passt nicht zu dieser Sitzung. Bitte noch einmal anmelden.');
        }
        if (!empty($query['error'])) {
            throw new OidcException('Anmeldung abgebrochen.', (string) $query['error']);
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            throw new OidcException('Keine Anmeldung erhalten.');
        }
        $res = KeycloakHttp::request('POST', $this->cfg->internalEndpoint('token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->cfg->redirectUri(),
            'client_id' => $this->cfg->clientId,
            'client_secret' => $this->cfg->clientSecret,
            'code_verifier' => (string) $session['verifier'],
        ]));
        $json = $res['json'];
        if ($res['status'] !== 200 || !is_array($json) || empty($json['id_token'])) {
            throw new OidcException('Die Anmeldung konnte nicht abgeschlossen werden.', 'token_' . $res['status']);
        }
        $claims = Jwt::verify((string) $json['id_token'], $this->jwks(), $this->cfg->issuer(), $this->cfg->clientId);
        if (!hash_equals((string) $session['nonce'], (string) ($claims['nonce'] ?? ''))) {
            throw new OidcException('Die Anmeldung passt nicht zu dieser Sitzung. Bitte noch einmal anmelden.');
        }
        if (empty($claims['sub'])) {
            throw new OidcException('Die Anmeldung enthält keinen Benutzer.');
        }
        return ['claims' => $claims, 'id_token' => (string) $json['id_token'], 'purpose' => (string) ($session['purpose'] ?? 'login')];
    }

    /**
     * Logout-Token aus der Backchannel-Abmeldung prüfen (OpenID Connect Back-Channel Logout 1.0).
     *
     * @return array<string,mixed> Claims (sid und/oder sub)
     */
    public function verifyLogoutToken(string $token): array
    {
        $claims = Jwt::verify($token, $this->jwks(), $this->cfg->issuer(), $this->cfg->clientId);
        $events = $claims['events'] ?? [];
        if (!is_array($events) || !array_key_exists('http://schemas.openid.net/event/backchannel-logout', $events)) {
            throw new OidcException('Kein Logout-Token.');
        }
        if (isset($claims['nonce'])) {
            throw new OidcException('Logout-Token darf keine nonce enthalten.');
        }
        if (empty($claims['sid']) && empty($claims['sub'])) {
            throw new OidcException('Logout-Token ohne Sitzung.');
        }
        return $claims;
    }

    /** Adresse zum Abmelden bei Keycloak (beendet die gemeinsame Sitzung für alle Anwendungen). */
    public function endSessionUrl(?string $idToken): string
    {
        $q = ['client_id' => $this->cfg->clientId, 'post_logout_redirect_uri' => $this->cfg->postLogoutUrl];
        if ($idToken) {
            $q['id_token_hint'] = $idToken;
        }
        return $this->cfg->publicEndpoint('logout') . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /** Ist Keycloak erreichbar? Kurzes Zeitlimit, damit das Portal bei einem Ausfall schnell zurückfällt. */
    public function reachable(): bool
    {
        $ch = curl_init($this->cfg->internalUrl . '/realms/' . rawurlencode($this->cfg->realm));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3, CURLOPT_NOBODY => false]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status === 200;
    }

    /** @return array<int, array<string,mixed>> */
    private function jwks(): array
    {
        $res = KeycloakHttp::request('GET', $this->cfg->internalEndpoint('certs'), [], null);
        $keys = is_array($res['json']) ? ($res['json']['keys'] ?? null) : null;
        if ($res['status'] !== 200 || !is_array($keys)) {
            throw new OidcException('Schlüssel des Anmeldedienstes nicht abrufbar.', 'jwks_' . $res['status']);
        }
        return $keys;
    }
}
