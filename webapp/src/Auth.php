<?php

declare(strict_types=1);

/**
 * Authentifizierung und Session-Management.
 * Sessions sind Redis-backed (php.ini: session.save_handler = redis).
 * Passwort-Hash: bcrypt via password_hash(PASSWORD_BCRYPT).
 */
class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('eeg_session');

            // Cookie-Domain explizit auf .stromfueralle.at setzen, damit sich Hauptdomain und
            // portal.stromfueralle.at (beide dieselbe App/DB) dieselbe Session teilen -- ohne
            // das bleibt eine auf einer Domain begonnene Session der jeweils anderen Domain
            // unbekannt (eigener Cookie pro exaktem Host ist PHPs Default), was nach einem
            // Domain-Wechsel wie ein ungewolltes Ausloggen wirkt. Für lokale Tests/andere
            // Domains (Host ohne "stromfueralle.at") bleibt der Default (nur exakter Host).
            $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
            $cookieDomain = str_ends_with($host, 'stromfueralle.at') ? '.stromfueralle.at' : '';

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => $cookieDomain,
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function login(string $email, string $password): bool
    {
        $user = DB::fetchOne(
            'SELECT id, password_hash, first_name, last_name, active FROM users WHERE email = ?',
            [strtolower(trim($email))]
        );

        if (!$user || !$user['active'] || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Rollen laden — LEFT JOIN damit platform_admin (community_id = NULL) nicht fehlt
        $roles = DB::fetchAll(
            'SELECT ur.community_id, ur.role,
                    c.name AS community_name,
                    c.slug AS community_slug,
                    COALESCE(LOWER(c.marktpartner_id), c.slug) AS community_mqtt_id
             FROM user_roles ur
             LEFT JOIN communities c ON c.id = ur.community_id
             WHERE ur.user_id = ?',
            [$user['id']]
        );

        $_SESSION['user_id']     = $user['id'];
        $_SESSION['user_name']   = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_email']  = $email;
        $_SESSION['roles']       = $roles;
        $_SESSION['active_role'] = self::pickDefaultRole($roles);

        // Last-Login aktualisieren
        DB::execute('UPDATE users SET last_login_at = now() WHERE id = ?', [$user['id']]);

        session_regenerate_id(true);
        return true;
    }

    public static function logout(): void
    {
        // Cookie mit denselben Parametern löschen, mit denen er gesetzt wurde (inkl. Domain) --
        // sonst bleibt bei geteilter .stromfueralle.at-Domain ein Cookie auf eine bereits
        // zerstörte Session-ID zurück, den der Browser nie wieder loswird.
        $params = session_get_cookie_params();
        session_destroy();
        setcookie('eeg_session', '', [
            'expires'  => time() - 3600,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /portal/login');
            exit;
        }
    }

    public static function requireRole(string $role): void
    {
        self::requireLogin();
        $active = $_SESSION['active_role'] ?? null;
        // platform_admin darf alles
        if (self::isPlatformAdmin()) return;
        if (!$active || $active['role'] !== $role) {
            http_response_code(403);
            echo 'Kein Zugriff';
            exit;
        }
    }

    /**
     * Prüft NUR die aktuell aktive Rolle, nicht alle jemals zugewiesenen Rollen.
     * Wer platform_admin ist, aber gerade auf eine Manager-Rolle umgeschaltet hat,
     * soll währenddessen keinen Admin-Zugriff haben (erst nach Zurückwechseln).
     */
    public static function isPlatformAdmin(): bool
    {
        return ($_SESSION['active_role']['role'] ?? null) === 'platform_admin';
    }

    public static function isManager(): bool
    {
        $active = $_SESSION['active_role'] ?? null;
        return $active && in_array($active['role'], ['manager', 'platform_admin']);
    }

    public static function userId(): ?string { return $_SESSION['user_id'] ?? null; }
    public static function userName(): string { return $_SESSION['user_name'] ?? ''; }
    public static function activeCommunityId(): ?string { return $_SESSION['active_role']['community_id'] ?? null; }
    public static function activeCommunitySlug(): ?string { return $_SESSION['active_role']['community_slug'] ?? null; }
    public static function activeCommunityMqttId(): ?string { return $_SESSION['active_role']['community_mqtt_id'] ?? null; }
    public static function activeRole(): ?array { return $_SESSION['active_role'] ?? null; }

    /**
     * Lädt die Rollen des eingeloggten Users aus der DB neu in die Session. Nötig, wenn im
     * Admin-Bereich (ggf. an der eigenen laufenden Session) Rollen hinzugefügt/entfernt werden
     * -- sonst zeigt das Rollen-Dropdown weiterhin den Stand vom Login-Zeitpunkt, selbst wenn
     * eine Rolle längst gelöscht wurde. Springt auf eine verbleibende Rolle um, falls die
     * gerade aktive dabei weggefallen ist.
     */
    public static function refreshRoles(): void
    {
        if (!self::check()) { return; }
        $roles = DB::fetchAll(
            'SELECT ur.community_id, ur.role,
                    c.name AS community_name,
                    c.slug AS community_slug,
                    COALESCE(LOWER(c.marktpartner_id), c.slug) AS community_mqtt_id
             FROM user_roles ur
             LEFT JOIN communities c ON c.id = ur.community_id
             WHERE ur.user_id = ?',
            [self::userId()]
        );
        $_SESSION['roles'] = $roles;

        $active = $_SESSION['active_role'] ?? null;
        $stillValid = $active && !empty(array_filter(
            $roles,
            fn($r) => $r['community_id'] === $active['community_id'] && $r['role'] === $active['role']
        ));
        if (!$stillValid) {
            $_SESSION['active_role'] = self::pickDefaultRole($roles);
        }
    }

    /** Wechselt aktive Community/Rolle */
    public static function switchRole(string $communityId, string $role): bool
    {
        foreach ($_SESSION['roles'] ?? [] as $r) {
            if ($r['community_id'] === $communityId && $r['role'] === $role) {
                $_SESSION['active_role'] = $r;
                return true;
            }
        }
        return false;
    }

    /** Wählt sinnvolle Standardrolle: platform_admin > manager > member */
    private static function pickDefaultRole(array $roles): ?array
    {
        $priority = ['platform_admin' => 0, 'manager' => 1, 'member' => 2];
        usort($roles, fn($a, $b) => ($priority[$a['role']] ?? 9) <=> ($priority[$b['role']] ?? 9));
        return $roles[0] ?? null;
    }

    /** Passwort-Reset: Token erzeugen und in DB speichern. Standard-Gültigkeit 1 Stunde
     *  (Selbstbedienung "Passwort vergessen"); der Manager-ausgelöste Reset am Mitglied
     *  übergibt bewusst ein kürzeres Zeitfenster. */
    public static function createResetToken(string $email, int $ttlSeconds = 3600): ?string
    {
        $user = DB::fetchOne('SELECT id FROM users WHERE email = ? AND active = true', [$email]);
        if (!$user) return null;

        $token = bin2hex(random_bytes(32));

        // Ablaufzeit bewusst in der DB berechnen (now() + Intervall) statt in PHP: die Prüfung
        // beim Einlösen nutzt ebenfalls Postgres-now(), und eine abweichende PHP-/DB-Zeitzone
        // könnte den Link sonst scheinbar sofort ungültig machen oder unnötig lange gültig lassen.
        DB::execute(
            "UPDATE users SET reset_token = ?, reset_token_expires = now() + (? * interval '1 second') WHERE id = ?",
            [$token, $ttlSeconds, $user['id']]
        );
        return $token;
    }

    public static function resetPassword(string $token, string $newPassword): bool
    {
        $user = DB::fetchOne(
            'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > now()',
            [$token]
        );
        if (!$user) return false;

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::execute(
            'UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?',
            [$hash, $user['id']]
        );
        return true;
    }
}
