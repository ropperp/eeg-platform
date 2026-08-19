<?php

declare(strict_types=1);

// Push::buildJwt()/derSignatureToRaw()/b64url() sind private (kein öffentliches API nötig
// außerhalb der Klasse selbst) -- über Reflection direkt getestet, wie es dieser Test-Runner
// auch sonst für reine Logik ohne DB-/HTTP-Kontext tut.
function pushPrivate(string $method, array $args)
{
    $ref = new ReflectionMethod(Push::class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

function fakeApnsKey(): array
{
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($res, $pem);
    $pub = openssl_pkey_get_details($res)['key'];
    return [$pem, $pub];
}

test('buildJwt() liefert 3 Base64url-Teile mit korrektem Header/Claims', function () {
    [$pem, ] = fakeApnsKey();
    $cfg = ['team_id' => 'TEAM123456', 'key_id' => 'KEY7890AB', 'private_key_enc' => encryptSecret($pem)];
    $jwt = pushPrivate('buildJwt', [$cfg]);

    $parts = explode('.', $jwt);
    assertSame(3, count($parts), 'JWT muss aus 3 Teilen bestehen (Header.Claims.Signatur)');

    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    assertSame('ES256', $header['alg']);
    assertSame('KEY7890AB', $header['kid']);

    $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    assertSame('TEAM123456', $claims['iss']);
    assertTrue(is_int($claims['iat']));
});

test('buildJwt() erzeugt eine mit dem öffentlichen Schlüssel verifizierbare Signatur', function () {
    [$pem, $pub] = fakeApnsKey();
    $cfg = ['team_id' => 'TEAM1', 'key_id' => 'KEY1', 'private_key_enc' => encryptSecret($pem)];
    $jwt = pushPrivate('buildJwt', [$cfg]);

    [$headerB64, $claimsB64, $sigB64] = explode('.', $jwt);
    $raw = base64_decode(strtr($sigB64, '-_', '+/'));
    assertSame(64, strlen($raw), 'ES256-Signatur muss 64 Byte (R||S, je 32 Byte) lang sein');

    // Raw R||S zurück in DER umwandeln, um mit openssl_verify() gegenzuprüfen.
    $r = ltrim(substr($raw, 0, 32), "\x00");
    $s = ltrim(substr($raw, 32, 32), "\x00");
    if (ord($r[0]) & 0x80) $r = "\x00" . $r;
    if (ord($s[0]) & 0x80) $s = "\x00" . $s;
    $rEnc = "\x02" . chr(strlen($r)) . $r;
    $sEnc = "\x02" . chr(strlen($s)) . $s;
    $der = "\x30" . chr(strlen($rEnc . $sEnc)) . $rEnc . $sEnc;

    $verify = openssl_verify($headerB64 . '.' . $claimsB64, $der, $pub, OPENSSL_ALGO_SHA256);
    assertSame(1, $verify, 'Signatur muss mit dem öffentlichen Schlüssel verifizierbar sein');
});

test('buildJwt() erzeugt bei wiederholtem Aufruf unterschiedliche Signaturen (ECDSA-Nonce)', function () {
    [$pem, ] = fakeApnsKey();
    $cfg = ['team_id' => 'TEAM1', 'key_id' => 'KEY1', 'private_key_enc' => encryptSecret($pem)];
    $a = pushPrivate('buildJwt', [$cfg]);
    $b = pushPrivate('buildJwt', [$cfg]);
    // iat kann bei sehr schneller Ausführung gleich sein, die Signatur selbst ist wegen des
    // ECDSA-Zufallsnonce trotzdem so gut wie nie identisch.
    assertFalse(explode('.', $a)[2] === explode('.', $b)[2]);
});
