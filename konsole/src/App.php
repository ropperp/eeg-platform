<?php

declare(strict_types=1);

namespace Konsole;

/**
 * Steuerzentrale (admin.<DOMAIN>): Status und Neustart der Plattform-Container, Logs, Wartungsmodus.
 * Läuft als eigener Container ohne Abhängigkeit von Webapp, Datenbank, Redis oder Traefik.
 * Anmeldung über Keycloak (gemeinsame Sitzung mit dem Portal, nur Rolle platform_admin, Klasse Sso) oder
 * als Notzugang per Passkey eines Platform-Admins (dieselben Passkeys wie im Portal).
 *
 * Auf allen anderen Hosts (Traefik leitet sie im Wartungsmodus hierher) zeigt sie nur die
 * Wartungsseite (HTTP 500, siehe maintenancePage()).
 */
final class App
{
    private const SESSION_TTL = 1800;
    private const CHALLENGE_TTL = 180;

    private Tokens $sessions;
    private Tokens $challenges;
    private Tokens $oidcStates;

    public function __construct(
        private readonly Config $config,
        private readonly PasskeyStore $passkeys,
        private readonly Docker $docker,
        private readonly Maintenance $maintenance,
        private readonly AuditLog $audit,
        private readonly ?Sso $sso = null,
    ) {
        $this->sessions = new Tokens($config->key('session'));
        $this->challenges = new Tokens($config->key('challenge'));
        $this->oidcStates = new Tokens($config->key('oidc'));
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $post   $_POST
     * @param array<string, string> $cookies $_COOKIE
     */
    public function handle(array $server, array $post, array $cookies, string $body): Response
    {
        $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');
        $path = (string) (parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($server['HTTP_HOST'] ?? '')) ?? '');

        // Für jeden Host: Gesundheitscheck, Wartungstext (für die Wartungsseite am nginx-Proxy), Assets.
        if ($path === '/health') {
            return Response::text('OK');
        }
        if ($path === '/wartung.json' && $method === 'GET') {
            return $this->maintenanceJson((string) ($server['HTTP_ORIGIN'] ?? ''));
        }
        if ($method === 'GET' && in_array($path, ['/konsole.js', '/konsole.css', '/logo-white.png', '/logo.png', '/favicon.png'], true)) {
            return Response::asset(__DIR__ . '/../public' . $path);
        }
        // Keycloak meldet intern (http://konsole:8090), dass eine gemeinsame Sitzung beendet wurde.
        if ($path === '/kc/backchannel-logout' && $method === 'POST') {
            return $this->backchannelLogout((string) ($post['logout_token'] ?? ''));
        }
        if ($host !== $this->config->adminHost) {
            return $this->maintenancePage();
        }

        $session = $this->session($cookies);
        $ip = $this->clientIp($server);

        if ($path === '/anmelden/sso' && $method === 'GET') {
            return $this->ssoStart();
        }
        if ($path === '/auth/callback' && $method === 'GET') {
            parse_str((string) ($server['QUERY_STRING'] ?? ''), $q);
            return $this->ssoCallback($q, $cookies, $ip);
        }

        if ($path === '/login/options' && $method === 'POST') {
            return $this->loginOptions();
        }
        if ($path === '/login/verify' && $method === 'POST') {
            return $this->loginVerify($body, $cookies, $ip);
        }
        if ($session === null) {
            // Formulare aus einem veralteten Tab (Sitzung abgelaufen oder überall abgemeldet): lesbare Seite
            // statt JSON. Abmelden ist dann schon erledigt.
            if ($method === 'POST' && $path === '/abmelden') {
                return Response::redirect('/?abgemeldet=1')->cookie($this->cookieName('s'), '', -1);
            }
            if ($method === 'POST' && in_array($path, ['/neustart', '/wartung'], true)) {
                return Response::redirect('/?abgelaufen=1');
            }
            if ($method !== 'GET') {
                return Response::json(['error' => 'Nicht angemeldet'], 401);
            }
            if ($path !== '/') {
                return Response::redirect('/');
            }
            parse_str((string) ($server['QUERY_STRING'] ?? ''), $q);
            // Gemeinsame Anmeldung: direkt zu Keycloak (wer im Portal angemeldet ist, ist sofort drin).
            // Nicht nach dem Abmelden, nicht bei ?passkey (Notzugang) und nicht, wenn Keycloak weg ist.
            $ssoUp = $this->sso?->enabled() === true && $this->sso->reachable();
            if ($ssoUp && !isset($q['abgemeldet']) && !isset($q['passkey']) && !isset($q['abgelaufen'])) {
                return $this->ssoStart();
            }
            return Response::html($this->render('login', [
                'sso' => $ssoUp,
                'ssoDown' => $this->sso?->enabled() === true && !$ssoUp,
                'abgemeldet' => isset($q['abgemeldet']),
                'abgelaufen' => isset($q['abgelaufen']),
                'fehler' => '',
            ]));
        }
        if ($method === 'POST' && !hash_equals($this->csrf($session), (string) ($post['csrf'] ?? ''))) {
            return Response::text('Sicherheits-Token ungültig. Bitte Seite neu laden.', 403);
        }

        $response = match (true) {
            $method === 'GET' && $path === '/' => $this->dashboard($session, (string) ($server['QUERY_STRING'] ?? '')),
            $method === 'GET' && $path === '/logs' => $this->logs($session, self::queryParam($server, 'dienst')),
            $method === 'POST' && $path === '/neustart' => $this->restart($session, (string) ($post['dienst'] ?? ''), $ip),
            $method === 'POST' && $path === '/wartung' => $this->setMaintenance($session, $post, $ip),
            $method === 'POST' && $path === '/abmelden' => $this->logout($session, $ip),
            default => Response::text('Nicht gefunden', 404),
        };
        // Sitzung bei jeder Aktivität verlängern (gleitend 30 Minuten), außer beim Abmelden.
        if ($path !== '/abmelden') {
            $keep = $session;
            unset($keep['exp']);
            $response->cookie($this->cookieName('s'), $this->sessions->issue($keep, self::SESSION_TTL), self::SESSION_TTL);
        }
        return $response;
    }

    // ─── Anmeldung ──────────────────────────────────────────────────────────

    private function loginOptions(): Response
    {
        $challenge = \WebAuthn::b64uEncode(random_bytes(32));
        return Response::json([
            'challenge'        => $challenge,
            'rpId'             => $this->rpId(),
            'timeout'          => 120000,
            'userVerification' => 'required',
        ])->cookie($this->cookieName('c'), $this->challenges->issue(['c' => $challenge], self::CHALLENGE_TTL), self::CHALLENGE_TTL);
    }

    /** @param array<string, string> $cookies */
    private function loginVerify(string $body, array $cookies, string $ip): Response
    {
        $fail = function (string $why) use ($ip): Response {
            $this->audit->write('-', 'anmeldung_fehlgeschlagen', $why, $ip);
            return Response::json(['error' => 'Passkey wurde nicht akzeptiert. Nur Platform-Admins mit Passkey haben Zugang.'], 401)
                ->cookie($this->cookieName('c'), '', -1);
        };
        $ch = $this->challenges->read((string) ($cookies[$this->cookieName('c')] ?? ''));
        $d = json_decode($body, true);
        $r = is_array($d) ? ($d['response'] ?? null) : null;
        if ($ch === null || !is_array($r) || !is_string($d['id'] ?? null)) {
            return $fail('Challenge fehlt/abgelaufen');
        }
        $key = $this->passkeys->all()[$d['id']] ?? null;
        if ($key === null) {
            return $fail('unbekannter Passkey');
        }
        try {
            $userHandle = !empty($r['userHandle']) ? \WebAuthn::b64uDecode((string) $r['userHandle']) : null;
            if ($userHandle !== null && $userHandle !== $key['user_id']) {
                throw new \RuntimeException('userHandle passt nicht');
            }
            $count = \WebAuthn::verifyAssertion(
                \WebAuthn::b64uDecode((string) ($r['clientDataJSON'] ?? '')),
                \WebAuthn::b64uDecode((string) ($r['authenticatorData'] ?? '')),
                \WebAuthn::b64uDecode((string) ($r['signature'] ?? '')),
                $key['pem'], $key['count'], (string) $ch['c'], $this->rpId()
            );
        } catch (\RuntimeException $e) {
            return $fail($e->getMessage());
        }
        $this->passkeys->updateCount($d['id'], $count);
        $this->audit->write($key['name'], 'anmeldung', '', $ip);
        return Response::json(['ok' => true])
            ->cookie($this->cookieName('c'), '', -1)
            ->cookie($this->cookieName('s'), $this->sessions->issue(['uid' => $key['user_id'], 'name' => $key['name'], 'sid' => bin2hex(random_bytes(16))], self::SESSION_TTL), self::SESSION_TTL);
    }

    /** @param array<string, mixed> $s */
    private function logout(array $s, string $ip): Response
    {
        $this->audit->write((string) $s['name'], 'abmeldung', '', $ip);
        // Über Keycloak angemeldet: dort abmelden, das beendet auch die Portal-Sitzung (Backchannel).
        // Mit Passkey angemeldet: nur hier abmelden. /?abgemeldet=1, damit die Startseite nicht sofort wieder
        // über die (noch bestehende) Keycloak-Sitzung des Portals anmeldet.
        $target = $this->sso !== null && !empty($s['kc']) ? $this->sso->endSessionUrl(is_string($s['idt'] ?? null) ? $s['idt'] : null) : '/?abgemeldet=1';
        return Response::redirect($target)->cookie($this->cookieName('s'), '', -1);
    }

    // ─── Anmeldung über Keycloak ────────────────────────────────────────────

    private function ssoStart(): Response
    {
        if ($this->sso === null || !$this->sso->enabled()) {
            return Response::redirect('/?passkey=1');
        }
        $state = [];
        $url = $this->sso->authorizationUrl($state);
        return Response::redirect($url)->cookie($this->cookieName('o'), $this->oidcStates->issue($state, 600), 600);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $cookies
     */
    private function ssoCallback(array $query, array $cookies, string $ip): Response
    {
        $loginPage = fn(string $msg): Response => Response::html($this->render('login', [
            'sso' => true, 'ssoDown' => false, 'abgemeldet' => false, 'fehler' => $msg,
        ]), 403)->cookie($this->cookieName('o'), '', -1);
        if ($this->sso === null || !$this->sso->enabled()) {
            return Response::redirect('/');
        }
        try {
            $state = $this->oidcStates->read((string) ($cookies[$this->cookieName('o')] ?? ''));
            $u = $this->sso->callback($state, $query);
        } catch (NotAdminException $e) {
            $this->audit->write('-', 'anmeldung_fehlgeschlagen', 'keine Rolle platform_admin', $ip);
            return $loginPage($e->getMessage());
        } catch (\Throwable $e) {
            $this->audit->write('-', 'anmeldung_fehlgeschlagen', 'Keycloak: ' . $e->getMessage(), $ip);
            return $loginPage('Anmeldung über Keycloak fehlgeschlagen. Bitte noch einmal versuchen oder den Passkey nutzen.');
        }
        $this->audit->write($u['name'], 'anmeldung', 'Keycloak', $ip);
        $session = ['uid' => 'kc:' . $u['sub'], 'name' => $u['name'], 'sid' => $u['sid'] !== '' ? $u['sid'] : bin2hex(random_bytes(16)),
                    'kc' => 1, 'idt' => $u['id_token']];
        return Response::redirect('/')
            ->cookie($this->cookieName('o'), '', -1)
            ->cookie($this->cookieName('s'), $this->sessions->issue($session, self::SESSION_TTL), self::SESSION_TTL);
    }

    private function backchannelLogout(string $token): Response
    {
        if ($this->sso === null || !$this->sso->enabled()) {
            return Response::text('', 404);
        }
        try {
            $sid = $this->sso->logoutSid($token);
        } catch (\Throwable $e) {
            error_log('[konsole] Backchannel: ' . $e->getMessage());
            return Response::text('invalid logout token', 400);
        }
        $this->sso->revoke($sid);
        return Response::text('')->header('Cache-Control', 'no-store');
    }

    // ─── Seiten und Aktionen ────────────────────────────────────────────────

    /** @param array<string, mixed> $s */
    private function dashboard(array $s, string $query): Response
    {
        parse_str($query, $q);
        try {
            $services = $this->docker->list();
            $dockerError = '';
        } catch (\Throwable $e) {
            $services = [];
            $dockerError = $e->getMessage();
        }
        return Response::html($this->render('dashboard', [
            'name'        => (string) $s['name'],
            'csrf'        => $this->csrf($s),
            'services'    => $services,
            'dockerError' => $dockerError,
            'wartung'     => $this->maintenance->state(),
            'audit'       => $this->audit->recent(15),
            'ok'          => is_string($q['ok'] ?? null) ? mb_substr($q['ok'], 0, 200) : '',
            'fehler'      => is_string($q['fehler'] ?? null) ? mb_substr($q['fehler'], 0, 200) : '',
        ]));
    }

    /** @param array<string, mixed> $s */
    private function logs(array $s, string $name): Response
    {
        if (!$this->docker->isAllowed($name)) {
            return Response::text('Unbekannter Dienst', 404);
        }
        try {
            $text = $this->docker->logs($name, 150);
        } catch (\Throwable $e) {
            $text = 'Logs nicht lesbar: ' . $e->getMessage();
        }
        return Response::html($this->render('logs', ['name' => $name, 'text' => $text, 'user' => (string) $s['name'], 'csrf' => $this->csrf($s)]));
    }

    /** @param array<string, mixed> $s */
    private function restart(array $s, string $name, string $ip): Response
    {
        if (!$this->docker->isAllowed($name)) {
            return Response::redirect('/?fehler=' . rawurlencode('Unbekannter Dienst.'));
        }
        try {
            $this->docker->restart($name);
        } catch (\Throwable $e) {
            $this->audit->write((string) $s['name'], 'neustart_fehlgeschlagen', $name, $ip);
            return Response::redirect('/?fehler=' . rawurlencode('Neustart von ' . $name . ' fehlgeschlagen: ' . $e->getMessage()));
        }
        $this->audit->write((string) $s['name'], 'neustart', $name, $ip);
        return Response::redirect('/?ok=' . rawurlencode($name . ' wird neu gestartet.'));
    }

    /**
     * @param array<string, mixed> $s
     * @param array<string, mixed> $post
     */
    private function setMaintenance(array $s, array $post, string $ip): Response
    {
        try {
            if (($post['aktion'] ?? '') === 'ein') {
                $this->maintenance->enable((string) ($post['text'] ?? ''), (string) ($post['bis'] ?? ''));
                $this->audit->write((string) $s['name'], 'wartung_ein', (string) ($post['bis'] ?? ''), $ip);
                return Response::redirect('/?ok=' . rawurlencode('Wartungsmodus ist aktiv. Die Seite zeigt jetzt den Wartungshinweis.'));
            }
            $this->maintenance->disable();
            $this->audit->write((string) $s['name'], 'wartung_aus', '', $ip);
            return Response::redirect('/?ok=' . rawurlencode('Wartungsmodus beendet. Die Seite ist wieder erreichbar.'));
        } catch (\Throwable $e) {
            return Response::redirect('/?fehler=' . rawurlencode($e->getMessage()));
        }
    }

    // ─── Wartungsseite (öffentlich) ─────────────────────────────────────────

    /**
     * Status 500 statt 503: der nginx-Proxy fängt 502/503/504 ab und zeigt dann seine eigene
     * Ausfall-Seite ("Kurzschluss") -- die geplante Wartung soll aber DIESE Seite mit Text und Ende
     * zeigen. Für Suchmaschinen gilt jeder 5xx-Status gleich als "vorübergehend nicht verfügbar".
     */
    private function maintenancePage(): Response
    {
        $state = $this->maintenance->state();
        return Response::html($this->render('wartung', ['wartung' => $state]), 500)
            ->header('Retry-After', (string) $this->maintenance->retryAfter())
            ->header('Cache-Control', 'no-store');
    }

    private function maintenanceJson(string $origin): Response
    {
        $s = $this->maintenance->state();
        $r = Response::json(['active' => $s['active'], 'message' => $s['message'], 'until' => $s['until']]);
        // Die Wartungsseite am nginx-Proxy (andere Subdomain) lädt den Text per fetch().
        $allowed = array_map(fn($h) => 'https://' . $h, $this->config->publicHosts());
        if (in_array($origin, $allowed, true)) {
            $r->header('Access-Control-Allow-Origin', $origin)->header('Vary', 'Origin');
        }
        return $r;
    }

    // ─── Hilfen ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $cookies
     * @return array<string, mixed>|null
     */
    private function session(array $cookies): ?array
    {
        $s = $this->sessions->read((string) ($cookies[$this->cookieName('s')] ?? ''));
        if ($s === null || !is_string($s['uid'] ?? null) || !is_string($s['sid'] ?? null)) {
            return null;
        }
        // Über Keycloak angemeldet und die gemeinsame Sitzung wurde beendet (z. B. Abmelden im Portal).
        if (!empty($s['kc']) && $this->sso !== null && $this->sso->isRevoked($s['sid'])) {
            return null;
        }
        return $s;
    }

    /** @param array<string, mixed> $s */
    public function csrf(array $s): string
    {
        return bin2hex(hash_hmac('sha256', (string) $s['sid'], $this->config->key('csrf'), true));
    }

    private function rpId(): string
    {
        return \WebAuthn::rpIdForHost($this->config->adminHost, $this->config->domain);
    }

    /** __Host-Präfix: Cookie nur über HTTPS, nur für genau diesen Host (lokale Tests: ohne). */
    private function cookieName(string $kind): string
    {
        return ($this->config->dev ? 'konsole_' : '__Host-konsole_') . $kind;
    }

    /** @param array<string, mixed> $server */
    private static function queryParam(array $server, string $name): string
    {
        parse_str((string) ($server['QUERY_STRING'] ?? ''), $q);
        return is_string($q[$name] ?? null) ? $q[$name] : '';
    }

    /** @param array<string, mixed> $server */
    private function clientIp(array $server): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($remote === $this->config->trustedProxy && $xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            return (string) end($parts);
        }
        return $remote;
    }

    /** @param array<string, mixed> $vars */
    private function render(string $view, array $vars): string
    {
        $h = fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/views/' . $view . '.php';
        return (string) ob_get_clean();
    }
}
