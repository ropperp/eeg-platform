<?php

declare(strict_types=1);

namespace Konsole;

/**
 * Signierte, ablaufende Werte für Cookies (Sitzung, Anmelde-Challenge). Kein Server-Speicher nötig:
 * die Konsole soll ohne Datenbank und Redis funktionieren.
 */
final class Tokens
{
    public function __construct(private readonly string $key)
    {
    }

    /** @param array<string, mixed> $data */
    public function issue(array $data, int $ttlSeconds, ?int $now = null): string
    {
        $data['exp'] = ($now ?? time()) + $ttlSeconds;
        $payload = self::b64(json_encode($data, JSON_THROW_ON_ERROR));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $this->key, true));
    }

    /** @return array<string, mixed>|null */
    public function read(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $mac] = $parts;
        if (!hash_equals(self::b64(hash_hmac('sha256', $payload, $this->key, true)), $mac)) {
            return null;
        }
        $data = json_decode((string) self::unb64($payload), true);
        if (!is_array($data) || !is_int($data['exp'] ?? null) || $data['exp'] < ($now ?? time())) {
            return null;
        }
        return $data;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
