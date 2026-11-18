<?php

declare(strict_types=1);

// Prüfung der Keycloak-Tokens (App\Infrastructure\Auth\Jwt) und Fingerabdrücke des Abgleichs
// (KeycloakSync::desiredState), docs/adr/0007-keycloak.md.

use App\Infrastructure\Auth\Jwt;
use App\Infrastructure\Auth\KeycloakSync;

/** @return array{0: OpenSSLAsymmetricKey, 1: array<string,string>} privater Schlüssel und passender JWK */
function kcTestKey(string $kid = 'k1'): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $d = openssl_pkey_get_details($key)['rsa'];
    return [$key, ['kid' => $kid, 'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'n' => Jwt::b64e($d['n']), 'e' => Jwt::b64e($d['e'])]];
}

function kcTestToken(OpenSSLAsymmetricKey $key, array $claims, array $header = ['alg' => 'RS256', 'kid' => 'k1']): string
{
    $input = Jwt::b64e(json_encode($header)) . '.' . Jwt::b64e(json_encode($claims));
    openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);
    return $input . '.' . Jwt::b64e($sig);
}

function kcRejects(callable $fn): bool
{
    try { $fn(); return false; } catch (\RuntimeException) { return true; }
}

test('Keycloak-Token: gültiges ID-Token wird angenommen', function () {
    [$key, $jwk] = kcTestKey();
    $claims = ['iss' => 'https://auth.x/realms/r', 'aud' => 'portal', 'sub' => 'u1', 'exp' => time() + 60, 'iat' => time()];
    assertSame('u1', Jwt::verify(kcTestToken($key, $claims), [$jwk], 'https://auth.x/realms/r', 'portal')['sub']);
    // aud als Liste (z. B. Logout-Token)
    $claims['aud'] = ['account', 'portal'];
    assertSame('u1', Jwt::verify(kcTestToken($key, $claims), [$jwk], 'https://auth.x/realms/r', 'portal')['sub']);
});

test('Keycloak-Token: Fälschungen und falsche Angaben werden abgelehnt', function () {
    [$key, $jwk] = kcTestKey();
    [$otherKey] = kcTestKey();
    $ok = ['iss' => 'https://auth.x/realms/r', 'aud' => 'portal', 'sub' => 'u1', 'exp' => time() + 60, 'iat' => time()];
    $v = fn(string $t) => Jwt::verify($t, [$jwk], 'https://auth.x/realms/r', 'portal');
    assertTrue(kcRejects(fn() => $v(kcTestToken($otherKey, $ok))), 'fremder Schlüssel');
    assertTrue(kcRejects(fn() => $v(kcTestToken($key, ['iss' => 'https://boese/realms/r'] + $ok))), 'Aussteller');
    assertTrue(kcRejects(fn() => $v(kcTestToken($key, ['aud' => 'konsole'] + $ok))), 'Zielgruppe');
    assertTrue(kcRejects(fn() => $v(kcTestToken($key, ['exp' => time() - 3600] + $ok))), 'abgelaufen');
    assertTrue(kcRejects(fn() => $v(kcTestToken($key, $ok, ['alg' => 'HS256', 'kid' => 'k1']))), 'anderes Verfahren');
    assertTrue(kcRejects(fn() => $v(kcTestToken($key, $ok, ['alg' => 'RS256', 'kid' => 'unbekannt']))), 'unbekannte kid');
    $t = explode('.', kcTestToken($key, $ok));
    $t[1] = Jwt::b64e(json_encode(['sub' => 'admin'] + $ok));
    assertTrue(kcRejects(fn() => $v(implode('.', $t))), 'veränderte Claims');
    assertTrue(kcRejects(fn() => $v(Jwt::b64e('{"alg":"none"}') . '.' . Jwt::b64e(json_encode($ok)) . '.')), 'alg none');
    assertTrue(kcRejects(fn() => $v('kein.token')), 'kein JWT');
});

test('Keycloak-Abgleich: Fingerabdrücke ändern sich nur mit den Daten', function () {
    $u = ['email' => 'A@x.at', 'first_name' => 'A', 'last_name' => 'B', 'active' => 't', 'password_hash' => 'h1',
          'totp_enabled' => 'f', 'totp_secret' => null, 'is_platform_admin' => false];
    $s = KeycloakSync::desiredState($u);
    assertSame($s, KeycloakSync::desiredState(['email' => ' a@x.at '] + $u), 'E-Mail-Schreibweise egal');
    assertTrue($s['password'] !== KeycloakSync::desiredState(['password_hash' => 'h2'] + $u)['password']);
    assertTrue($s['profile'] !== KeycloakSync::desiredState(['active' => 'f'] + $u)['profile']);
    assertTrue($s['admin'] !== KeycloakSync::desiredState(['is_platform_admin' => 't'] + $u)['admin']);
    $secret = totpGenerateSecret();
    $withTotp = KeycloakSync::desiredState(['totp_enabled' => 't', 'totp_secret' => $secret] + $u);
    assertTrue($s['totp'] !== $withTotp['totp']);
    // Neu verschlüsselt (anderes Chiffrat, gleiches Secret): kein erneuter Abgleich
    assertSame($withTotp['totp'], KeycloakSync::desiredState(['totp_enabled' => 't', 'totp_secret' => encryptSecret($secret)] + $u)['totp']);
});
