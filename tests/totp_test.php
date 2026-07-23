<?php

declare(strict_types=1);

// TOTP (RFC 6238). Referenz-Secret "12345678901234567890" (ASCII) in Base32.
$secret = base32Encode('12345678901234567890');   // GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ

test('Base32 Encode/Decode ist verlustfrei', function () {
    assertSame('12345678901234567890', base32Decode(base32Encode('12345678901234567890')));
});

test('RFC-6238-Testvektor T=59 -> 287082', function () use ($secret) {
    assertSame('287082', totpCodeAt($secret, 59));
});
test('RFC-6238-Testvektor T=1111111109 -> 081804', function () use ($secret) {
    assertSame('081804', totpCodeAt($secret, 1111111109));
});
test('RFC-6238-Testvektor T=1234567890 -> 005924', function () use ($secret) {
    assertSame('005924', totpCodeAt($secret, 1234567890));
});

test('totpVerify akzeptiert den aktuellen Code', function () use ($secret) {
    $now = 1234567890;
    assertTrue(totpVerify($secret, totpCodeAt($secret, $now), $now));
});
test('totpVerify akzeptiert Code aus dem Nachbarfenster (Uhr-Toleranz)', function () use ($secret) {
    $now = 1234567890;
    assertTrue(totpVerify($secret, totpCodeAt($secret, $now - 30), $now));
});
test('totpVerify lehnt falschen Code ab', function () use ($secret) {
    assertFalse(totpVerify($secret, '000000', 1234567890));
});
test('totpVerify lehnt Nicht-6-stellige Eingabe ab', function () use ($secret) {
    assertFalse(totpVerify($secret, '12345', 1234567890));
});
test('Provisioning-URI enthält Secret und Issuer', function () use ($secret) {
    $uri = totpProvisioningUri($secret, 'obmann@example.at', 'Strom für alle');
    assertContains('otpauth://totp/', $uri);
    assertContains('secret=' . $secret, $uri);
    assertContains('digits=6', $uri);
});
