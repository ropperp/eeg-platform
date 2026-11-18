<?php

declare(strict_types=1);

namespace Konsole;

use App\Infrastructure\Auth\Jwt;

/**
 * Anmeldung der Steuerzentrale über Keycloak (Client "konsole", docs/adr/0007-keycloak.md, Schritt 4).
 *
 * Wer im Portal angemeldet ist, kommt ohne erneute Eingabe hinein (gemeinsame Keycloak-Sitzung), aber
 * nur mit der Realm-Rolle platform_admin (Claim "roles" im ID-Token). Ohne Datenbank und Redis: Zustand
 * der Anmeldung in einem signierten Cookie, beendete Sitzungen (Backchannel) in /data/revoked.json.
 * Der Passkey-Notzugang (ADR 0006) bleibt für den Fall, dass Keycloak ausfällt.
 */
final class Sso
{
    public function __construct(private readonly Config $config)
    {
    }

    public function enabled(): bool
    {
        return $this->config->kcClientSecret !== '';
    }

    public function issuer(): string
    {
        return $this->config->kcPublicUrl . '/realms/' . $this->config->kcRealm;
    }

    public function redirectUri(): string
    {
        return $this->config->baseUrl . '/auth/callback';
    }

    /**
     * @param array<string,string> $state wird befüllt (state, nonce, verifier) und als Cookie gespeichert
     */
    public function authorizationUrl(array &$state): string
    {
        $state = [
            'state' => Jwt::b64e(random_bytes(24)),
            'nonce' => Jwt::b64e(random_bytes(24)),
            'verifier' => Jwt::b64e(random_bytes(48)),
        ];
        return $this->issuer() . '/protocol/openid-connect/auth?' . http_build_query([
            'client_id' => 'konsole',
            'response_type' => 'code',
            'scope' => 'openid profile',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state['state'],
            'nonce' => $state['nonce'],
            'code_challenge' => Jwt::b64e(hash('sha256', $state['verifier'], true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string,mixed>|null $state aus dem Cookie
     * @param array<string,mixed> $query
     * @return array{sub:string, name:string, sid:string, id_token:string}
     */
    public function callback(?array $state, array $query): array
    {
        if ($state === null || !hash_equals((string) ($state['state'] ?? ''), (string) ($query['state'] ?? ''))) {
            throw new \RuntimeException('Anmeldung abgelaufen oder passt nicht. Bitte noch einmal.');
        }
        if (!empty($query['error'])) {
            throw new \RuntimeException('Anmeldung abgebrochen.');
        }
        $res = $this->http('POST', $this->internal('token'), http_build_query([
            'grant_type' => 'authorization_code',
            'code' => (string) ($query['code'] ?? ''),
            'redirect_uri' => $this->redirectUri(),
            'client_id' => 'konsole',
            'client_secret' => $this->config->kcClientSecret,
            'code_verifier' => (string) $state['verifier'],
        ]));
        if ($res['status'] !== 200 || empty($res['json']['id_token'])) {
            throw new \RuntimeException('Anmeldung konnte nicht abgeschlossen werden (HTTP ' . $res['status'] . ').');
        }
        $idToken = (string) $res['json']['id_token'];
        $claims = Jwt::verify($idToken, $this->jwks(), $this->issuer(), 'konsole');
        if (!hash_equals((string) $state['nonce'], (string) ($claims['nonce'] ?? ''))) {
            throw new \RuntimeException('Anmeldung passt nicht zu dieser Sitzung.');
        }
        $roles = $claims['roles'] ?? [];
        if (!is_array($roles) || !in_array('platform_admin', $roles, true)) {
            throw new NotAdminException('Kein Zugang: Die Steuerzentrale ist nur für Platform-Admins.');
        }
        $name = trim((string) ($claims['name'] ?? '')) ?: (string) ($claims['preferred_username'] ?? 'Admin');
        return ['sub' => (string) $claims['sub'], 'name' => $name, 'sid' => (string) ($claims['sid'] ?? ''), 'id_token' => $idToken];
    }

    /** Logout-Token aus der Backchannel-Abmeldung prüfen; liefert die beendete Keycloak-Sitzung (sid). */
    public function logoutSid(string $token): string
    {
        $claims = Jwt::verify($token, $this->jwks(), $this->issuer(), 'konsole');
        $events = $claims['events'] ?? [];
        if (!is_array($events) || !array_key_exists('http://schemas.openid.net/event/backchannel-logout', $events) || isset($claims['nonce'])) {
            throw new \RuntimeException('Kein Logout-Token.');
        }
        $sid = (string) ($claims['sid'] ?? '');
        if ($sid === '') {
            throw new \RuntimeException('Logout-Token ohne Sitzung.');
        }
        return $sid;
    }

    public function endSessionUrl(?string $idToken): string
    {
        $q = ['client_id' => 'konsole', 'post_logout_redirect_uri' => $this->config->baseUrl . '/?abgemeldet=1'];
        if ($idToken) {
            $q['id_token_hint'] = $idToken;
        }
        return $this->issuer() . '/protocol/openid-connect/logout?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    /** Kurzer Test, damit die Anmeldeseite bei einem Keycloak-Ausfall sofort den Passkey anbietet. */
    public function reachable(): bool
    {
        return $this->enabled() && $this->http('GET', $this->config->kcInternalUrl . '/realms/' . rawurlencode($this->config->kcRealm), null, 3)['status'] === 200;
    }

    // ─── Beendete Sitzungen (Backchannel) ───────────────────────────────────

    public function revoke(string $sid): void
    {
        $list = $this->revokedList();
        $list[$sid] = time() + 43200;
        $tmp = $this->config->dataDir . '/revoked.json.tmp';
        file_put_contents($tmp, json_encode($list), LOCK_EX);
        rename($tmp, $this->config->dataDir . '/revoked.json');
    }

    public function isRevoked(string $sid): bool
    {
        return $sid !== '' && isset($this->revokedList()[$sid]);
    }

    /** @return array<string,int> sid => Ablauf (abgelaufene fallen beim Lesen heraus) */
    private function revokedList(): array
    {
        $raw = @file_get_contents($this->config->dataDir . '/revoked.json');
        $list = is_string($raw) ? json_decode($raw, true) : null;
        $now = time();
        return is_array($list) ? array_filter($list, fn($exp) => is_int($exp) && $exp > $now) : [];
    }

    // ─── HTTP ───────────────────────────────────────────────────────────────

    private function internal(string $endpoint): string
    {
        return $this->config->kcInternalUrl . '/realms/' . rawurlencode($this->config->kcRealm) . '/protocol/openid-connect/' . $endpoint;
    }

    /** @return array<int, array<string,mixed>> */
    private function jwks(): array
    {
        $res = $this->http('GET', $this->internal('certs'), null);
        $keys = $res['json']['keys'] ?? null;
        if ($res['status'] !== 200 || !is_array($keys)) {
            throw new \RuntimeException('Schlüssel des Anmeldedienstes nicht abrufbar.');
        }
        return $keys;
    }

    /** @return array{status:int, json:array<string,mixed>} */
    private function http(string $method, string $url, ?string $form, int $timeout = 10): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $form !== null ? ['Content-Type: application/x-www-form-urlencoded'] : [],
        ]);
        if ($form !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $form);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        return ['status' => $body === false ? 0 : $status, 'json' => is_array($json) ? $json : []];
    }
}
