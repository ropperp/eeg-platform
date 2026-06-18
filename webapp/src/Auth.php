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
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
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

        // Rollen aller Communities laden
        $roles = DB::fetchAll(
            'SELECT ur.community_id, ur.role, c.name AS community_name, c.slug AS community_slug
             FROM user_roles ur
             JOIN communities c ON c.id = ur.community_id
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
        session_destroy();
        setcookie('eeg_session', '', time() - 3600, '/');
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
        if (!$active || $active['role'] !== $role) {
            if ($role !== 'platform_admin') {
                header('HTTP/1.1 403 Forbidden');
                echo 'Kein Zugriff';
                exit;
            }
        }
    }

    public static function isPlatformAdmin(): bool
    {
        foreach ($_SESSION['roles'] ?? [] as $r) {
            if ($r['role'] === 'platform_admin') return true;
        }
        return false;
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
    public static function activeRole(): ?array { return $_SESSION['active_role'] ?? null; }

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

    /** Passwort-Reset: Token erzeugen und in DB speichern */
    public static function createResetToken(string $email): ?string
    {
        $user = DB::fetchOne('SELECT id FROM users WHERE email = ? AND active = true', [$email]);
        if (!$user) return null;

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 Stunde

        DB::execute(
            'UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?',
            [$token, $expires, $user['id']]
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
