<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Übersetzt die in der Plattform gespeicherten Anmeldedaten in das Credential-Format von Keycloak,
 * damit beim Umzug niemand ein neues Passwort setzen oder 2FA neu einrichten muss
 * (docs/adr/0007-keycloak.md).
 *
 * - Argon2id ($argon2id$v=19$m=…,t=…,p=…$salt$hash, seit 15.11.2026): kann Keycloak selbst prüfen.
 * - bcrypt ($2y$…, alle älteren Konten): prüft die eigene Erweiterung "bcrypt-legacy"
 *   (keycloak/providers). Keycloak hasht nach der ersten erfolgreichen Anmeldung still auf Argon2 um.
 * - TOTP: das Base32-Secret wird unverändert übernommen (secretEncoding BASE32).
 *
 * Reine Funktionen ohne Netz und DB, damit sie sich im Unit-Test prüfen lassen.
 */
final class KeycloakCredentialMapper
{
    /** @return array<string,mixed>|null Credential-Darstellung für die Admin-API, null = unbekanntes Format */
    public static function password(string $phpHash): ?array
    {
        if (str_starts_with($phpHash, '$argon2')) {
            return self::argon2($phpHash);
        }
        if (preg_match('/^\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}$/', $phpHash)) {
            return self::credential(
                ['hashIterations' => -1, 'algorithm' => 'bcrypt-legacy', 'additionalParameters' => new \stdClass()],
                ['value' => $phpHash, 'salt' => '', 'additionalParameters' => new \stdClass()]
            );
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private static function argon2(string $phpHash): ?array
    {
        // $argon2id$v=19$m=65536,t=3,p=1$<salt b64, ohne Padding>$<hash b64, ohne Padding>
        $parts = explode('$', $phpHash);
        if (count($parts) !== 6 || !preg_match('/^argon2(id|i|d)$/', $parts[1], $type)) {
            return null;
        }
        if (!preg_match('/^v=(\d+)$/', $parts[2], $v) || !preg_match('/^m=(\d+),t=(\d+),p=(\d+)$/', $parts[3], $p)) {
            return null;
        }
        $salt = base64_decode(self::pad($parts[4]), true);
        $hash = base64_decode(self::pad($parts[5]), true);
        if ($salt === false || $hash === false || $salt === '' || $hash === '') {
            return null;
        }
        return self::credential(
            [
                'hashIterations' => (int) $p[2],
                'algorithm' => 'argon2',
                'additionalParameters' => [
                    'type' => [$type[1]],
                    'version' => [$v[1] === '19' ? '1.3' : '1.0'],
                    'hashLength' => [(string) strlen($hash)],
                    'memory' => [$p[1]],
                    'parallelism' => [$p[3]],
                ],
            ],
            ['value' => base64_encode($hash), 'salt' => base64_encode($salt), 'additionalParameters' => new \stdClass()]
        );
    }

    /** @return array<string,mixed>|null */
    public static function totp(string $secretBase32, string $label = 'Authenticator-App'): ?array
    {
        $secret = strtoupper(rtrim($secretBase32, '='));
        if (!preg_match('/^[A-Z2-7]{16,}$/', $secret)) {
            return null;
        }
        return self::credential(
            ['subType' => 'totp', 'digits' => 6, 'counter' => 0, 'period' => 30, 'algorithm' => 'HmacSHA1', 'secretEncoding' => 'BASE32'],
            ['value' => $secret],
            'otp',
            $label
        );
    }

    /**
     * @param array<string,mixed> $credentialData
     * @param array<string,mixed> $secretData
     * @return array<string,mixed>
     */
    private static function credential(array $credentialData, array $secretData, string $type = 'password', ?string $label = null): array
    {
        $rep = [
            'type' => $type,
            'credentialData' => json_encode($credentialData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'secretData' => json_encode($secretData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
        if ($label !== null) {
            $rep['userLabel'] = $label;
        }
        return $rep;
    }

    private static function pad(string $b64): string
    {
        return $b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4);
    }
}
