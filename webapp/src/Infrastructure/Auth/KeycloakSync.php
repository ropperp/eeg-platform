<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Abgleich Portal -> Keycloak (docs/adr/0007-keycloak.md). Das Portal bleibt die Quelle für Profil,
 * Sperre, Passwort, 2FA und die Rolle platform_admin; Keycloak bekommt jeweils den aktuellen Stand.
 *
 * Passwörter gehen als vorhandener Hash (Argon2id direkt, bcrypt über die Erweiterung bcrypt-legacy),
 * nie im Klartext. users.keycloak_state merkt sich Fingerabdrücke des zuletzt übertragenen Stands, damit
 * nur Geändertes übertragen wird. Fehlt er (z. B. vor dem Umschalten importierte Konten), wird alles
 * einmal neu übertragen.
 *
 * Angestoßen über die Warteschlange keycloak_sync_queue (Trigger, migrate_20261121.sql): drain() nach
 * jeder Anfrage der Webapp und per Cron (bin/keycloak_sync.php).
 */
final class KeycloakSync
{
    public function __construct(private readonly KeycloakAdminClient $kc)
    {
    }

    public static function fromEnv(): ?self
    {
        return KeycloakConfig::fromEnv()->syncEnabled() ? new self(KeycloakAdminClient::fromEnv()) : null;
    }

    /**
     * Bringt ein Konto in Keycloak auf den Stand des Portals.
     *
     * @return string angelegt | verknuepft | aktualisiert | unveraendert | fehlt
     */
    public function syncUser(string $userId, bool $retried = false): string
    {
        $u = \DB::fetchOne(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.active, u.password_hash, u.totp_enabled, u.totp_secret,
                    u.keycloak_id, u.keycloak_state,
                    EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'platform_admin') AS is_platform_admin
             FROM users u WHERE u.id = ?",
            [$userId]
        );
        if (!$u) {
            return 'fehlt';
        }
        $desired = self::desiredState($u);
        $email = strtolower(trim((string) $u['email']));
        $state = is_string($u['keycloak_state']) ? (json_decode($u['keycloak_state'], true) ?: []) : [];
        $action = 'aktualisiert';
        $kcId = $u['keycloak_id'] ? (string) $u['keycloak_id'] : null;

        if ($kcId === null) {
            $existing = $this->kc->findUserByEmail($email);
            if ($existing !== null) {
                $kcId = (string) $existing['id'];
                $state = [];
                $action = 'verknuepft';
            } else {
                $credentials = [];
                $pw = KeycloakCredentialMapper::password((string) $u['password_hash']);
                if ($pw !== null) {
                    $credentials[] = $pw;
                }
                $otp = self::otpCredential($u);
                if ($otp !== null) {
                    $credentials[] = $otp;
                }
                $kcId = $this->kc->createUser(self::profile($u) + ['credentials' => $credentials]);
                if ($desired['admin'] === '1') {
                    $this->kc->assignRealmRole($kcId, 'platform_admin');
                }
                self::saveState($userId, $kcId, $desired);
                return 'angelegt';
            }
        }

        try {
            $changed = $this->apply($kcId, $u, $state, $desired);
        } catch (\RuntimeException $e) {
            // In Keycloak von Hand gelöscht? Dann Verknüpfung lösen und einmal neu anlegen.
            if (!$retried && $this->kc->getUser($kcId) === null) {
                \DB::execute('UPDATE users SET keycloak_id = NULL, keycloak_state = NULL WHERE id = ?', [$userId]);
                return $this->syncUser($userId, true);
            }
            throw $e;
        }
        self::saveState($userId, $kcId, $desired);
        return $action === 'verknuepft' ? 'verknuepft' : ($changed ? 'aktualisiert' : 'unveraendert');
    }

    /**
     * @param array<string,mixed> $u
     * @param array<string,string> $state
     * @param array<string,string> $desired
     */
    private function apply(string $kcId, array $u, array $state, array $desired): bool
    {
        $diff = static fn(string $k): bool => ($state[$k] ?? null) !== $desired[$k];
        $rep = [];
        if ($diff('profile')) {
            $rep += self::profile($u);
        }
        if ($diff('password')) {
            $pw = KeycloakCredentialMapper::password((string) $u['password_hash']);
            if ($pw !== null) {
                $rep['credentials'] = [$pw];   // ersetzt das bisherige Passwort
            }
        }
        if ($rep !== []) {
            $this->kc->updateUser($kcId, $rep);
        }
        if ($diff('totp')) {
            foreach ($this->kc->credentials($kcId) as $c) {
                if (($c['type'] ?? '') === 'otp') {
                    $this->kc->deleteCredential($kcId, (string) $c['id']);
                }
            }
            $otp = self::otpCredential($u);
            if ($otp !== null) {
                $this->kc->updateUser($kcId, ['credentials' => [$otp]]);
            }
        }
        if ($diff('admin')) {
            $desired['admin'] === '1'
                ? $this->kc->assignRealmRole($kcId, 'platform_admin')
                : $this->kc->removeRealmRole($kcId, 'platform_admin');
        }
        // Gesperrt oder Passwort geändert: offene Keycloak-Sitzungen beenden (nur wenn vorher schon
        // abgeglichen; beim ersten Abgleich wäre das ein unnötiges Abmelden aller).
        if ($state !== [] && (($diff('password')) || ($diff('profile') && !self::isActive($u)))) {
            $this->kc->logoutUser($kcId);
        }
        return $rep !== [] || $diff('totp') || $diff('admin');
    }

    public function deleteKeycloakUser(string $keycloakId): void
    {
        $this->kc->deleteUser($keycloakId);
    }

    /**
     * Arbeitet die Warteschlange ab. Jeder Eintrag wird atomar geholt (zwei Läufe gleichzeitig stören
     * sich nicht). Schlägt einer fehl, kommt er mit erhöhtem Zähler zurück (höchstens 50 Versuche).
     *
     * @return array{ok:int, fehler:int}
     */
    public function drain(int $limit = 20): array
    {
        $rows = \DB::fetchAll(
            'DELETE FROM keycloak_sync_queue
             WHERE id IN (SELECT id FROM keycloak_sync_queue ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED)
             RETURNING id, user_id, keycloak_id, op, attempts',
            [$limit]
        );
        $ok = 0;
        $fail = 0;
        $done = [];
        foreach ($rows as $i => $r) {
            $key = $r['op'] . ':' . ($r['user_id'] ?? $r['keycloak_id']);
            if (isset($done[$key])) {
                continue;   // gleiche Arbeit schon in diesem Lauf erledigt
            }
            try {
                if ($r['op'] === 'delete') {
                    $this->deleteKeycloakUser((string) $r['keycloak_id']);
                } else {
                    $this->syncUser((string) $r['user_id']);
                }
                $done[$key] = true;
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                error_log('[keycloak_sync] ' . $r['op'] . ' ' . ($r['user_id'] ?? $r['keycloak_id']) . ': ' . $e->getMessage());
                if ((int) $r['attempts'] < 50) {
                    self::requeue($r, (int) $r['attempts'] + 1, substr($e->getMessage(), 0, 500));
                }
                // Beim ersten Fehler aufhören (Keycloak vermutlich weg): die übrigen geholten Einträge
                // unverändert zurücklegen. So hängt eine Anfrage höchstens einmal im Zeitlimit.
                foreach (array_slice($rows, $i + 1) as $rest) {
                    self::requeue($rest, (int) $rest['attempts'], null);
                }
                break;
            }
        }
        return ['ok' => $ok, 'fehler' => $fail];
    }

    /** @param array<string,mixed> $r */
    private static function requeue(array $r, int $attempts, ?string $error): void
    {
        \DB::execute(
            'INSERT INTO keycloak_sync_queue (user_id, keycloak_id, op, attempts, last_error) VALUES (?, ?, ?, ?, ?)',
            [$r['user_id'], $r['keycloak_id'], $r['op'], $attempts, $error]
        );
    }

    /** Nach einer Anfrage der Webapp: Warteschlange abarbeiten, wenn Keycloak eingerichtet ist und etwas ansteht. */
    public static function afterRequest(): void
    {
        if (!KeycloakConfig::fromEnv()->syncEnabled()) {
            return;
        }
        try {
            if (!\DB::fetchOne('SELECT 1 AS x FROM keycloak_sync_queue LIMIT 1')) {
                return;
            }
            self::fromEnv()?->drain(10);
        } catch (\Throwable $e) {
            error_log('[keycloak_sync] ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $u
     * @return array<string,string>
     */
    public static function desiredState(array $u): array
    {
        $fp = static fn(string $s): string => substr(hash('sha256', $s), 0, 32);
        $otp = self::totpEnabled($u) ? totpSecretFromStorage($u['totp_secret'] ?? null) : '';
        return [
            'profile' => $fp(implode("\n", [strtolower(trim((string) $u['email'])), (string) $u['first_name'], (string) $u['last_name'], self::isActive($u) ? '1' : '0'])),
            'password' => $fp((string) $u['password_hash']),
            'totp' => $fp($otp),
            'admin' => self::truthy($u['is_platform_admin'] ?? false) ? '1' : '0',
        ];
    }

    /**
     * @param array<string,mixed> $u
     * @return array<string,mixed>
     */
    private static function profile(array $u): array
    {
        $email = strtolower(trim((string) $u['email']));
        return [
            'username' => $email,
            'email' => $email,
            'emailVerified' => true,
            'firstName' => (string) $u['first_name'],
            'lastName' => (string) $u['last_name'],
            'enabled' => self::isActive($u),
        ];
    }

    /**
     * @param array<string,mixed> $u
     * @return array<string,mixed>|null
     */
    private static function otpCredential(array $u): ?array
    {
        return self::totpEnabled($u) ? KeycloakCredentialMapper::totp(totpSecretFromStorage($u['totp_secret'] ?? null)) : null;
    }

    /** @param array<string,string> $state */
    private static function saveState(string $userId, string $kcId, array $state): void
    {
        \DB::execute(
            'UPDATE users SET keycloak_id = ?, keycloak_state = ?::jsonb, keycloak_synced_at = now() WHERE id = ?',
            [$kcId, json_encode($state, JSON_THROW_ON_ERROR), $userId]
        );
    }

    /** @param array<string,mixed> $u */
    private static function isActive(array $u): bool
    {
        return self::truthy($u['active'] ?? false);
    }

    /** @param array<string,mixed> $u */
    private static function totpEnabled(array $u): bool
    {
        return self::truthy($u['totp_enabled'] ?? false) && !empty($u['totp_secret']);
    }

    private static function truthy(mixed $v): bool
    {
        return in_array($v, [true, 't', 'true', 1, '1'], true);
    }
}
