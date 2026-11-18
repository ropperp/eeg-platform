<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

/**
 * Prüft die von Keycloak signierten Tokens (ID-Token, Logout-Token): Signatur RS256 gegen die
 * öffentlichen Schlüssel des Realms (JWKS), Aussteller, Zielgruppe und Gültigkeit.
 * Nur RS256: andere Verfahren, "none" und Schlüssel ohne passende kid werden abgelehnt.
 */
final class Jwt
{
    /**
     * @param array<int, array<string,mixed>> $jwks Schlüssel aus /protocol/openid-connect/certs ("keys")
     * @return array<string,mixed> Claims
     */
    public static function verify(string $jwt, array $jwks, string $issuer, string $audience, ?int $now = null, int $leeway = 60): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Token hat kein JWT-Format.');
        }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode(self::b64d($h64), true);
        $claims = json_decode(self::b64d($p64), true);
        if (!is_array($header) || !is_array($claims)) {
            throw new \RuntimeException('Token nicht lesbar.');
        }
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new \RuntimeException('Token-Verfahren nicht erlaubt.');
        }
        $key = null;
        foreach ($jwks as $k) {
            if (($k['kid'] ?? null) === ($header['kid'] ?? '') && ($k['kty'] ?? '') === 'RSA' && ($k['use'] ?? 'sig') === 'sig') {
                $key = $k;
                break;
            }
        }
        if ($key === null || empty($key['n']) || empty($key['e'])) {
            throw new \RuntimeException('Signaturschlüssel unbekannt.');
        }
        $ok = openssl_verify($h64 . '.' . $p64, self::b64d($s64), self::rsaPem((string) $key['n'], (string) $key['e']), OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new \RuntimeException('Signatur ungültig.');
        }
        $now ??= time();
        if (($claims['iss'] ?? '') !== $issuer) {
            throw new \RuntimeException('Aussteller falsch.');
        }
        $aud = $claims['aud'] ?? [];
        if (!in_array($audience, is_array($aud) ? $aud : [$aud], true)) {
            throw new \RuntimeException('Zielgruppe falsch.');
        }
        if (isset($claims['exp']) && (int) $claims['exp'] < $now - $leeway) {
            throw new \RuntimeException('Token abgelaufen.');
        }
        if (isset($claims['iat']) && (int) $claims['iat'] > $now + $leeway) {
            throw new \RuntimeException('Token aus der Zukunft.');
        }
        return $claims;
    }

    public static function b64d(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($r === false) {
            throw new \RuntimeException('Base64 ungültig.');
        }
        return $r;
    }

    public static function b64e(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Öffentlichen RSA-Schlüssel aus Modulus n und Exponent e (Base64URL) als PEM bauen. */
    public static function rsaPem(string $n, string $e): string
    {
        $int = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\0");
            if ($bytes === '' || ord($bytes[0]) > 0x7f) {
                $bytes = "\0" . $bytes;
            }
            return "\x02" . self::len(strlen($bytes)) . $bytes;
        };
        $rsaKey = $int(self::b64d($n)) . $int(self::b64d($e));
        $rsaKey = "\x30" . self::len(strlen($rsaKey)) . $rsaKey;
        $bitString = "\x03" . self::len(strlen($rsaKey) + 1) . "\0" . $rsaKey;
        $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption, NULL
        $spki = "\x30" . self::len(strlen($algo . $bitString)) . $algo . $bitString;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $b = ltrim(pack('N', $len), "\0");
        return chr(0x80 | strlen($b)) . $b;
    }
}
