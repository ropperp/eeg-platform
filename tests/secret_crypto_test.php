<?php

declare(strict_types=1);

// Gespeicherte Zugangsdaten verschlüsseln (functions.php: encryptSecret()/decryptSecret()), 15.11.2026.

function withAppSecret(string $secret, callable $fn)
{
    $old = getenv('APP_SECRET');
    putenv('APP_SECRET=' . $secret);
    try { return $fn(); } finally { putenv($old === false ? 'APP_SECRET' : 'APP_SECRET=' . $old); }
}

test('encryptSecret/decryptSecret: Rundlauf im neuen Format v2 (AES-256-GCM)', function () {
    withAppSecret('geheimnis-eins', function () {
        $c = encryptSecret('WLAN-Passwort äöü 123');
        assertTrue(str_starts_with($c, 'v2:'));
        assertSame('WLAN-Passwort äöü 123', decryptSecret($c));
        assertSame('', decryptSecret(''));
    });
});

test('encryptSecret: zufällige Nonce -- derselbe Klartext ergibt jedes Mal anderen Ciphertext', function () {
    withAppSecret('geheimnis-eins', function () {
        assertTrue(encryptSecret('gleich') !== encryptSecret('gleich'));
        assertFalse(str_contains(encryptSecret('KlartextImText'), 'KlartextImText'));
    });
});

test('decryptSecret: ältere Werte (AES-256-CBC ohne Präfix) bleiben lesbar', function () {
    withAppSecret('geheimnis-eins', function () {
        $alt = encryptSecretLegacyCbc('altes-secret');
        assertFalse(str_starts_with($alt, 'v2:'));
        assertSame('altes-secret', decryptSecret($alt));
    });
});

test('decryptSecret: verfälschter Ciphertext wird erkannt (leer statt Unsinn)', function () {
    withAppSecret('geheimnis-eins', function () {
        $c = encryptSecret('wichtig');
        $raw = base64_decode(substr($c, 3));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);        // ein Bit im Ciphertext kippen
        assertSame('', decryptSecret('v2:' . base64_encode($raw)));
        $raw2 = base64_decode(substr($c, 3));
        $raw2[14] = chr(ord($raw2[14]) ^ 1);                                  // ein Bit im Tag kippen
        assertSame('', decryptSecret('v2:' . base64_encode($raw2)));
        assertSame('', decryptSecret('v2:kein-base64!'));
        assertSame('', decryptSecret('v2:' . base64_encode('zu kurz')));
    });
});

test('decryptSecret: mit anderem APP_SECRET nicht entschlüsselbar', function () {
    $c = withAppSecret('geheimnis-eins', fn() => encryptSecret('nur mit Schlüssel eins'));
    assertSame('', withAppSecret('geheimnis-zwei', fn() => decryptSecret($c)));
    assertSame('nur mit Schlüssel eins', withAppSecret('geheimnis-eins', fn() => decryptSecret($c)));
});

test('totpSecretFromStorage: Klartext-Base32, altes CBC und neues v2 werden alle gelesen', function () {
    withAppSecret('geheimnis-eins', function () {
        assertSame('JBSWY3DPEHPK3PXP', totpSecretFromStorage('JBSWY3DPEHPK3PXP'));
        assertSame('JBSWY3DPEHPK3PXP', totpSecretFromStorage(encryptSecretLegacyCbc('JBSWY3DPEHPK3PXP')));
        assertSame('JBSWY3DPEHPK3PXP', totpSecretFromStorage(encryptSecret('JBSWY3DPEHPK3PXP')));
    });
});
