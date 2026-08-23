<?php

declare(strict_types=1);

/**
 * Authentifizierung für die App-Programmierschnittstelle (/api/v1/login, /api/v1/dashboard,
 * /api/v1/invoices, ...) -- getrennt von webapp/src/Auth.php (Browser-Session-Cookies) und von
 * member_api_keys (langlebige, selbst erzeugte Schlüssel für Skripte/Smart-Home, siehe
 * /api/v1/me, /api/v1/live). Für eine mobile App braucht es kurzlebige, automatisch erneuerbare
 * Zugriffstoken statt eines Cookies oder eines dauerhaften Schlüssels.
 *
 * Zwei Token-Arten:
 * - Zugriffstoken (Access-Token): selbst-signiert (HMAC-SHA256 mit aus APP_SECRET abgeleitetem
 *   Schlüssel), 15 Minuten gültig, KEIN DB-Zugriff zum Prüfen nötig (schnell, für jeden Request).
 *   Bewusst kein fertiges JWT-Paket -- gleiches "abhängigkeitsfrei"-Prinzip wie bei TOTP
 *   (functions.php): nur HMAC + JSON, kein Composer-Paket nötig.
 * - Refresh-Token: zufällig, in app_sessions gehasht gespeichert (wie member_api_keys.key_hash),
 *   30 Tage gültig, wird bei jeder Erneuerung ROTIERT (altes wird sofort ungültig, ein neues
 *   ersetzt es) -- Standard-Schutz gegen Diebstahl: wird ein gestohlenes Token vor dem echten
 *   Gerät benutzt, schlägt die nächste Erneuerung des echten Geräts fehl und macht den
 *   Diebstahl bemerkbar, statt dass beide Seiten unbemerkt parallel gültige Token behalten.
 *
 * "Tickets" (issueTicket()/verifyTicket()) überbrücken die Zwischenschritte eines mehrstufigen
 * Logins (Passwort -> 2FA -> ggf. Community-Auswahl bei Mehrfach-Mitgliedschaft), OHNE eine
 * serverseitige Session zu brauchen -- eine App hält normalerweise keine Cookie-Jar-Session wie
 * ein Browser. Jeder Tickettyp hat ein eigenes "typ"-Feld UND einen eigenen HMAC-Schlüssel
 * (via key()-Label), damit ein 2FA-Ticket nicht versehentlich (oder böswillig) als
 * Community-Auswahl-Ticket durchgeht.
 */
class AppApiAuth
{
    private const ACCESS_TOKEN_TTL = 900;       // 15 Minuten
    private const REFRESH_TOKEN_TTL_DAYS = 30;
    private const TICKET_TTL = 300;             // 5 Minuten -- Zeit zwischen den Login-Schritten

    private static function key(string $purpose): string
    {
        return hash('sha256', (getenv('APP_SECRET') ?: '') . '|' . $purpose, true);
    }

    private static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $encoded): string|false
    {
        $padded = str_pad($encoded, strlen($encoded) + (4 - strlen($encoded) % 4) % 4, '=');
        return base64_decode(strtr($padded, '-_', '+/'), true);
    }

    private static function signToken(string $purpose, array $claims): string
    {
        $payload = self::b64urlEncode((string)json_encode($claims));
        $sig = self::b64urlEncode(hash_hmac('sha256', $payload, self::key($purpose), true));
        return $payload . '.' . $sig;
    }

    /** Prüft Signatur + Ablauf, liefert die dekodierten Claims oder null. */
    private static function verifySignedToken(string $purpose, string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        [$payload, $sig] = $parts;
        $expectedSig = self::b64urlEncode(hash_hmac('sha256', $payload, self::key($purpose), true));
        if (!hash_equals($expectedSig, $sig)) return null;
        $raw = self::b64urlDecode($payload);
        if ($raw === false) return null;
        $claims = json_decode($raw, true);
        if (!is_array($claims) || empty($claims['exp']) || (int)$claims['exp'] < time()) return null;
        return $claims;
    }

    // ─── Zugriffstoken ──────────────────────────────────────────────────────
    /**
     * $role ist 'member', 'manager' oder 'admin' (seit migrate_20260902.sql -- Platform-Admins
     * bekommen beim Login weiterhin zusätzlich ein normales 'manager'-Token für ihre eigene(n)
     * EEG(s), siehe resolveAppRoleOptions(), aber jetzt daneben auch die Möglichkeit, sich
     * separat als 'admin' einzuloggen für die plattformweiten Admin-Funktionen).
     * $memberId ist bei role='manager'/'admin' meist leer (kein eigener Mitgliedsdatensatz
     * nötig). $communityId ist bei role='admin' NULL -- Admin-Funktionen sind bewusst NICHT an
     * eine einzelne EEG gebunden (wirken plattformweit), betroffene Endpunkte bekommen die
     * jeweilige Ziel-Community (falls nötig, z.B. bei einer bestimmten EEG bearbeiten) explizit
     * aus der Request-URL, nicht aus dem Token.
     */
    public static function issueAccessToken(?string $communityId, string $role, ?string $memberId = null, ?string $userId = null): string
    {
        return self::signToken('access', [
            'cid'  => $communityId,
            'role' => $role,
            'mid'  => $memberId,
            'uid'  => $userId,
            'exp'  => time() + self::ACCESS_TOKEN_TTL,
        ]);
    }

    public static function accessTokenTtl(): int
    {
        return self::ACCESS_TOKEN_TTL;
    }

    /** Gibt ['member_id'=>.., 'community_id'=>.., 'role'=>.., 'user_id'=>..] zurück, oder null bei ungültig/abgelaufen. */
    public static function verifyAccessToken(string $token): ?array
    {
        $claims = self::verifySignedToken('access', $token);
        if (!$claims || empty($claims['role'])) return null;
        return [
            'member_id'    => $claims['mid'] ?? null,
            'community_id' => isset($claims['cid']) ? (string)$claims['cid'] : null,
            'role'         => (string)$claims['role'],
            'user_id'      => $claims['uid'] ?? null,
        ];
    }

    /**
     * Liest den Access-Token aus dem Authorization-Header, prüft ihn und liefert
     * ['member_id'=>.., 'community_id'=>.., 'role'=>..] zurück. Bei Fehler wird direkt eine
     * 401-JSON-Antwort gesendet und null zurückgegeben -- Aufrufer macht dann nur
     * `if (!$ctx) return;` (DRY: ohne diese zentrale Funktion hätte jeder der Daten-Endpunkte
     * dieselbe Prüfung dupliziert, siehe ursprünglich /api/v1/me und /api/v1/live).
     *
     * $allowDemoWrite=true erlaubt POST auch für Demo-Logins (users.is_demo, siehe
     * migrate_20260905.sql) -- nur für ungefährliche Aktionen wie den Rollenwechsel gedacht.
     * Alle anderen POST-Endpunkte (die überwiegende Mehrheit) sind für Demo-Logins hier zentral
     * gesperrt, damit ein Demo-Zugang (Präsentation/Diplomarbeit-Review) auch über die App
     * garantiert read-only bleibt -- gleiches Prinzip wie der Router.php-Schutz im Web-Portal,
     * nur an anderer Stelle, weil der Nutzer im Web schon vor dem Routing (Session) bekannt ist,
     * hier aber erst nach der Token-Prüfung.
     */
    public static function requireAppAuth(bool $allowDemoWrite = false): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(401);
            echo json_encode(['error' => 'Fehlender oder ungültiger Authorization-Header. Erwartet: "Bearer <Access-Token>".']);
            return null;
        }
        $ctx = self::verifyAccessToken(trim($m[1]));
        if (!$ctx) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(401);
            echo json_encode(['error' => 'Zugriffstoken ungültig oder abgelaufen. Bitte mit dem Refresh-Token erneuern (/api/v1/token/refresh).']);
            return null;
        }
        // is_demo IMMER ermitteln (nicht nur bei POST) -- Datei-Download-Routen (GET) brauchen
        // dieselbe Information, um für den Demo-Zugang zusätzlich zum reinen Schreibschutz auch
        // jeden Dateitransfer zu blocken (siehe denyDemoFileDownload() in index.php, Patrick,
        // 23.08.2026: "Die Dateien dürfen nie, in gar keinem Fall [...] heruntergeladen werden").
        $ctx['is_demo'] = (bool)(DB::fetchOne('SELECT is_demo FROM users WHERE id = ?', [$ctx['user_id']])['is_demo'] ?? false);
        if (!$allowDemoWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $ctx['is_demo']) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode(['error' => 'Demo-Zugang: nur Lesezugriff möglich.']);
            return null;
        }
        return $ctx;
    }

    /**
     * Wie requireAppAuth(), verlangt zusätzlich role='manager' -- für die Obmann-Endpunkte
     * (Mitglieder anlegen/bearbeiten, Dateien hochladen). Ein Mitglied-Token wird hier mit 403
     * abgelehnt, nicht 401 (Token an sich ist gültig, reicht nur nicht für diese Aktion).
     */
    public static function requireManagerAuth(): ?array
    {
        $ctx = self::requireAppAuth();
        if (!$ctx) return null;
        if ($ctx['role'] !== 'manager') {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode(['error' => 'Diese Aktion erfordert einen Obmann-Zugang.']);
            return null;
        }
        return $ctx;
    }

    /**
     * Wie requireAppAuth(), verlangt zusätzlich role='admin' -- für die plattformweiten
     * Admin-Funktionen (EEG-Verwaltung, Nutzer & Rollen, Aktivitätslog, Mail-/MQTT-
     * Einstellungen, Backups). Ein Mitglied- oder Obmann-Token wird hier mit 403 abgelehnt.
     */
    public static function requireAdminAuth(): ?array
    {
        $ctx = self::requireAppAuth();
        if (!$ctx) return null;
        if ($ctx['role'] !== 'admin') {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode(['error' => 'Diese Aktion erfordert einen Plattform-Admin-Zugang.']);
            return null;
        }
        return $ctx;
    }

    // ─── Login-Zwischenschritte (2FA, Community-Auswahl) ─────────────────────
    public static function issueTicket(string $typ, array $claims): string
    {
        return self::signToken('ticket:' . $typ, array_merge($claims, [
            'typ' => $typ,
            'exp' => time() + self::TICKET_TTL,
        ]));
    }

    public static function verifyTicket(string $typ, string $ticket): ?array
    {
        $claims = self::verifySignedToken('ticket:' . $typ, $ticket);
        if (!$claims || ($claims['typ'] ?? null) !== $typ) return null;
        return $claims;
    }

    // ─── Refresh-Token (DB-gestützt, widerrufbar, rotierend) ─────────────────
    public static function issueRefreshToken(?string $communityId, string $role, ?string $memberId, ?string $userId, ?string $deviceLabel = null): string
    {
        $raw = bin2hex(random_bytes(32));
        DB::execute(
            "INSERT INTO app_sessions (member_id, community_id, role, user_id, refresh_token_hash, device_label, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, now() + make_interval(days => ?))",
            [$memberId, $communityId, $role, $userId, hash('sha256', $raw), $deviceLabel, self::REFRESH_TOKEN_TTL_DAYS]
        );
        return $raw;
    }

    /**
     * Prüft ein Refresh-Token und rotiert es (altes wird sofort ungültig, ein neues ersetzt es
     * in derselben Zeile) -- Aufrufer MUSS das neue Refresh-Token an den Client ausliefern,
     * sonst verliert das Gerät nach dem nächsten Ablauf des Access-Tokens den Zugriff. Gibt null
     * zurück, wenn das Token unbekannt, bereits widerrufen/rotiert oder abgelaufen ist (Client
     * muss sich dann neu anmelden).
     */
    public static function verifyAndRotateRefreshToken(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);
        $session = DB::fetchOne(
            "SELECT * FROM app_sessions WHERE refresh_token_hash = ? AND revoked_at IS NULL AND expires_at > now()",
            [$hash]
        );
        if (!$session) return null;

        $newRaw = bin2hex(random_bytes(32));
        DB::execute(
            "UPDATE app_sessions SET refresh_token_hash = ?, expires_at = now() + make_interval(days => ?), last_used_at = now() WHERE id = ?",
            [hash('sha256', $newRaw), self::REFRESH_TOKEN_TTL_DAYS, $session['id']]
        );

        return [
            'member_id'     => $session['member_id'],
            'community_id'  => $session['community_id'],
            'role'          => $session['role'],
            'user_id'       => $session['user_id'],
            'refresh_token' => $newRaw,
        ];
    }

    /** Meldet ein einzelnes Gerät ab (Logout). Kein Fehler, wenn das Token bereits ungültig war. */
    public static function revokeRefreshToken(string $rawToken): void
    {
        DB::execute(
            "UPDATE app_sessions SET revoked_at = now() WHERE refresh_token_hash = ? AND revoked_at IS NULL",
            [hash('sha256', $rawToken)]
        );
    }
}
