<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\HtmlResponse;
use App\Http\Responses\RedirectResponse;
use App\Http\Responses\Response;
use App\Infrastructure\Auth\KeycloakAdminClient;
use App\Infrastructure\Auth\KeycloakConfig;
use App\Infrastructure\Auth\KeycloakOidc;
use App\Infrastructure\Auth\OidcException;

/**
 * Portal-Anmeldung über Keycloak (AUTH_PROVIDER=keycloak, docs/adr/0007-keycloak.md).
 *
 * Nach der Anmeldung baut das Portal seine Sitzung wie bisher selbst auf (Auth::establishSession):
 * Rollen, EEG, RLS und alles Weitere bleiben unverändert. Gefunden wird der Benutzer nur über
 * users.keycloak_id (= sub), nie über die E-Mail.
 *
 * Ist Keycloak nicht erreichbar, fällt /portal/login auf die bisherige Anmeldung zurück
 * (localLoginAllowed()), damit ein Ausfall des Anmeldedienstes nicht das ganze Portal sperrt.
 */
final class KeycloakAuthController
{
    private ?bool $reachable = null;

    public function __construct(private readonly KeycloakConfig $cfg, private readonly KeycloakOidc $oidc)
    {
    }

    /** Darf die bisherige Anmeldung (Formular, 2FA, Passkey des Portals) benutzt werden? */
    public function localLoginAllowed(): bool
    {
        return !$this->cfg->loginEnabled || $this->cfg->testOnly || !$this->keycloakReachable();
    }

    /** Hinweis auf der bisherigen Login-Seite zeigen? (nur wenn Keycloak gilt, aber ausgefallen ist) */
    public function showOutageNotice(): bool
    {
        return $this->cfg->loginEnabled && !$this->cfg->testOnly;
    }

    /** GET /portal/login: zur Keycloak-Anmeldung weiterleiten. null = bisherige Login-Seite zeigen. */
    public function login(): ?Response
    {
        if (!$this->cfg->loginEnabled || ($this->cfg->testOnly && !isset($_GET['keycloak'])) || !$this->keycloakReachable()) {
            return null;
        }
        $state = [];
        $url = $this->oidc->authorizationUrl($state, 'login');
        $_SESSION['oidc'] = $state;
        return new RedirectResponse($url);
    }

    /**
     * Anwendungs-Aktion in Keycloak starten (Google verknüpfen, Passkey einrichten). Die Rückkehr kommt
     * über callback() mit kc_action_status.
     */
    public function action(string $purpose): Response
    {
        \Auth::requireLogin();
        $kcAction = match ($purpose) {
            'google' => 'idp_link:google',
            'passkey' => 'webauthn-register-passwordless',
            default => null,
        };
        if (!$this->cfg->loginEnabled || $kcAction === null) {
            return new RedirectResponse('/portal/profile');
        }
        $state = [];
        $url = $this->oidc->authorizationUrl($state, $purpose, ['kc_action' => $kcAction]);
        $_SESSION['oidc'] = $state;
        return new RedirectResponse($url);
    }

    /** @param array<string,mixed> $query */
    public function callback(array $query): Response
    {
        $saved = $_SESSION['oidc'] ?? null;
        unset($_SESSION['oidc']);
        try {
            $result = $this->oidc->handleCallback(is_array($saved) ? $saved : null, $query);
        } catch (OidcException $e) {
            if ($e->reason !== '') {
                error_log('[keycloak_login] ' . $e->reason);
            }
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[keycloak_login] ' . $e->getMessage());
            return $this->fail('Die Anmeldung konnte nicht geprüft werden. Bitte noch einmal versuchen.');
        }
        $claims = $result['claims'];
        $user = \DB::fetchOne('SELECT id, active FROM users WHERE keycloak_id = ?', [(string) $claims['sub']]);

        if ($result['purpose'] !== 'login') {
            // Rückkehr aus Google-Verknüpfung oder Passkey-Einrichtung: muss derselbe Benutzer sein.
            if (!\Auth::check() || !$user || $user['id'] !== \Auth::userId()) {
                return $this->fail('Die Anmeldung passt nicht zum angemeldeten Konto.');
            }
            $this->rememberKeycloakSession($result['id_token'], $claims);
            $status = (string) ($query['kc_action_status'] ?? '');
            $msg = match (true) {
                $status === 'success' && $result['purpose'] === 'google' => 'Google-Konto verknüpft. Sie können sich jetzt auch mit Google anmelden.',
                $status === 'success' => 'Passkey eingerichtet. Sie können sich jetzt auch mit dem Passkey anmelden.',
                $status === 'cancelled' => 'Abgebrochen, es wurde nichts geändert.',
                default => 'Das hat nicht geklappt, es wurde nichts geändert.',
            };
            $key = $status === 'success' ? 'success' : 'error';
            return new RedirectResponse('/portal/profile?' . $key . '=' . urlencode($msg));
        }

        if (!$user) {
            return $this->fail('Für dieses Konto gibt es keinen Zugang zur Plattform. Mitglied werden Sie über die Beitrittserklärung.');
        }
        if (!in_array($user['active'], [true, 't', 'true', 1, '1'], true)) {
            return $this->fail('Dieser Zugang ist gesperrt. Bitte wenden Sie sich an Ihre Energiegemeinschaft.');
        }
        \Auth::establishSession((string) $user['id']);
        $this->rememberKeycloakSession($result['id_token'], $claims);
        return new RedirectResponse('/portal/dashboard');
    }

    /** GET /portal/logout: Portal-Sitzung beenden und bei Keycloak abmelden (gilt für alle Anwendungen). */
    public function logout(): Response
    {
        $idToken = $_SESSION['kc_id_token'] ?? null;
        $sid = $_SESSION['kc_sid'] ?? null;
        \Auth::logout();
        if (is_string($sid) && $sid !== '') {
            self::redis()?->del('kc:sid:' . $sid);
        }
        if (!is_string($idToken)) {
            return new RedirectResponse('/portal/login');
        }
        return new RedirectResponse($this->oidc->endSessionUrl($idToken));
    }

    /**
     * POST /api/kc/backchannel-logout (Keycloak ruft intern auf, wenn die gemeinsame Sitzung endet, z. B.
     * Abmelden in der Steuerzentrale): die passende Portal-Sitzung löschen.
     *
     * @param array<string,mixed> $post
     */
    public function backchannelLogout(array $post): Response
    {
        try {
            $claims = $this->oidc->verifyLogoutToken((string) ($post['logout_token'] ?? ''));
        } catch (\Throwable $e) {
            error_log('[keycloak_backchannel] ' . $e->getMessage());
            return HtmlResponse::text('invalid logout token', 400);
        }
        $redis = self::redis();
        $sid = (string) ($claims['sid'] ?? '');
        if ($redis !== null && $sid !== '') {
            $phpSession = $redis->get('kc:sid:' . $sid);
            if (is_string($phpSession) && $phpSession !== '') {
                $redis->del('PHPREDIS_SESSION:' . $phpSession, 'kc:sid:' . $sid);
            }
        }
        header('Cache-Control: no-store');
        return HtmlResponse::text('', 200);
    }

    /**
     * Daten für die Karte „Anmeldung“ im Profil (nur bei AUTH_PROVIDER=keycloak).
     *
     * @return array{google_available: bool, google_linked: ?string, passkeys: list<array{id:string,label:string,created:string}>}|null
     */
    public function profileInfo(): ?array
    {
        if (!$this->cfg->loginEnabled) {
            return null;
        }
        $kcId = $this->currentKeycloakId();
        if ($kcId === null) {
            return null;
        }
        try {
            $kc = KeycloakAdminClient::fromEnv();
            $linked = null;
            foreach ($kc->federatedIdentities($kcId) as $f) {
                if (($f['identityProvider'] ?? '') === 'google') {
                    $linked = (string) ($f['userName'] ?? 'verbunden');
                }
            }
            $passkeys = [];
            foreach ($kc->credentials($kcId) as $c) {
                if (in_array($c['type'] ?? '', ['webauthn-passwordless', 'webauthn'], true)) {
                    $passkeys[] = [
                        'id' => (string) $c['id'],
                        'label' => (string) ($c['userLabel'] ?? 'Passkey'),
                        'created' => isset($c['createdDate']) ? date('d.m.Y', (int) ($c['createdDate'] / 1000)) : '',
                    ];
                }
            }
            return ['google_available' => $linked !== null || $kc->identityProviderEnabled('google'), 'google_linked' => $linked, 'passkeys' => $passkeys];
        } catch (\Throwable $e) {
            error_log('[keycloak_profile] ' . $e->getMessage());
            return null;
        }
    }

    /** POST /portal/auth/google/unlink */
    public function unlinkGoogle(): Response
    {
        \Auth::requireLogin();
        $kcId = $this->currentKeycloakId();
        if ($kcId !== null) {
            KeycloakAdminClient::fromEnv()->removeFederatedIdentity($kcId, 'google');
        }
        return new RedirectResponse('/portal/profile?success=' . urlencode('Google-Verknüpfung gelöst.') . '#anmeldung');
    }

    /**
     * POST /portal/auth/passkey/delete
     *
     * @param array<string,mixed> $post
     */
    public function deletePasskey(array $post): Response
    {
        \Auth::requireLogin();
        $kcId = $this->currentKeycloakId();
        $credId = (string) ($post['id'] ?? '');
        if ($kcId !== null && $credId !== '') {
            $kc = KeycloakAdminClient::fromEnv();
            // Nur eigene Passkeys: die ID muss in der Liste DIESES Benutzers stehen.
            foreach ($kc->credentials($kcId) as $c) {
                if ((string) $c['id'] === $credId && in_array($c['type'] ?? '', ['webauthn-passwordless', 'webauthn'], true)) {
                    $kc->deleteCredential($kcId, $credId);
                }
            }
        }
        return new RedirectResponse('/portal/profile?success=' . urlencode('Passkey entfernt.') . '#anmeldung');
    }

    private function currentKeycloakId(): ?string
    {
        $row = \DB::fetchOne('SELECT keycloak_id FROM users WHERE id = ?', [\Auth::userId()]);
        return $row && $row['keycloak_id'] ? (string) $row['keycloak_id'] : null;
    }

    /** @param array<string,mixed> $claims */
    private function rememberKeycloakSession(string $idToken, array $claims): void
    {
        $_SESSION['kc_id_token'] = $idToken;
        $sid = (string) ($claims['sid'] ?? '');
        if ($sid !== '') {
            $_SESSION['kc_sid'] = $sid;
            self::redis()?->setex('kc:sid:' . $sid, 43200, session_id());
        }
    }

    private function fail(string $message): Response
    {
        return new HtmlResponse('pages/kc_login_error', ['message' => $message], 400);
    }

    private function keycloakReachable(): bool
    {
        return $this->reachable ??= $this->oidc->reachable();
    }

    private static function redis(): ?\Redis
    {
        static $client = null;
        if ($client !== null) {
            return $client ?: null;
        }
        try {
            $r = new \Redis();
            $r->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), 1.0);
            $pw = (string) getenv('REDIS_PASSWORD');
            if ($pw !== '') {
                $r->auth($pw);
            }
            $client = $r;
        } catch (\Throwable $e) {
            error_log('[keycloak_redis] ' . $e->getMessage());
            $client = false;
        }
        return $client ?: null;
    }
}
