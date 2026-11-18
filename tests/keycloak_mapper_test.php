<?php

declare(strict_types=1);

// Übernahme der Anmeldedaten nach Keycloak (App\Infrastructure\Auth\KeycloakCredentialMapper,
// docs/adr/0007-keycloak.md). Dass Keycloak die Ergebnisse wirklich annimmt, prüft
// tests/e2e/keycloak_run.sh gegen einen echten Keycloak.

use App\Infrastructure\Auth\KeycloakCredentialMapper;

test('Keycloak: Argon2id-Hash wird mit Parametern, Salt und Hash übernommen', function () {
    $hash = password_hash('x', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1]);
    $c = KeycloakCredentialMapper::password($hash);
    assertSame('password', $c['type']);
    $data = json_decode($c['credentialData'], true);
    assertSame('argon2', $data['algorithm']);
    assertSame(3, $data['hashIterations']);
    assertSame(['id'], $data['additionalParameters']['type']);
    assertSame(['1.3'], $data['additionalParameters']['version']);
    assertSame(['65536'], $data['additionalParameters']['memory']);
    assertSame(['1'], $data['additionalParameters']['parallelism']);
    assertSame(['32'], $data['additionalParameters']['hashLength']);
    $secret = json_decode($c['secretData'], true);
    $parts = explode('$', $hash);
    assertSame(rtrim($secret['salt'], '='), $parts[4], 'Salt (Base64 mit Padding) entspricht dem PHP-Salt');
    assertSame(rtrim($secret['value'], '='), $parts[5]);
});

test('Keycloak: bcrypt-Hash geht unverändert an die Erweiterung bcrypt-legacy', function () {
    $hash = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
    $c = KeycloakCredentialMapper::password($hash);
    assertSame('bcrypt-legacy', json_decode($c['credentialData'], true)['algorithm']);
    assertSame($hash, json_decode($c['secretData'], true)['value']);
});

test('Keycloak: unbekanntes oder kaputtes Hash-Format wird nicht übernommen', function () {
    assertSame(null, KeycloakCredentialMapper::password(''));
    assertSame(null, KeycloakCredentialMapper::password('klartext'));
    assertSame(null, KeycloakCredentialMapper::password('$argon2id$v=19$m=65536,t=3,p=1$$'));
    assertSame(null, KeycloakCredentialMapper::password('$2y$12$zukurz'));
    assertSame(null, KeycloakCredentialMapper::password(md5('x')));
});

test('Keycloak: TOTP-Secret wird als Base32 übernommen, Müll nicht', function () {
    $secret = totpGenerateSecret();
    $c = KeycloakCredentialMapper::totp($secret);
    assertSame('otp', $c['type']);
    $data = json_decode($c['credentialData'], true);
    assertSame('BASE32', $data['secretEncoding']);
    assertSame('totp', $data['subType']);
    assertSame(30, $data['period']);
    assertSame($secret, json_decode($c['secretData'], true)['value']);
    assertSame(null, KeycloakCredentialMapper::totp(''));
    assertSame(null, KeycloakCredentialMapper::totp('kein base32!'));
});
