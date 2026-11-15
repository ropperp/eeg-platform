<?php

declare(strict_types=1);

// Passkey-Anmeldung (webapp/src/WebAuthn.php), 17.11.2026 -- mit einem per openssl nachgebauten
// Authenticator (ES256 und RS256), der Registrierung und Anmeldung so erzeugt wie ein echtes Gerät.

function wa_cbor($v): string
{
    $head = function (int $major, int $n): string {
        if ($n < 24) return chr(($major << 5) | $n);
        if ($n < 256) return chr(($major << 5) | 24) . chr($n);
        if ($n < 65536) return chr(($major << 5) | 25) . pack('n', $n);
        return chr(($major << 5) | 26) . pack('N', $n);
    };
    if (is_int($v)) return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
    if (is_string($v)) return $head(2, strlen($v)) . $v;           // Byte-String
    if (is_array($v) && isset($v['__text'])) return $head(3, strlen($v['__text'])) . $v['__text'];
    if (is_array($v) && isset($v['__map'])) {
        $o = $head(5, count($v['__map']));
        foreach ($v['__map'] as [$k, $val]) $o .= wa_cbor($k) . wa_cbor($val);
        return $o;
    }
    throw new Exception('nicht unterstützt');
}
function wa_text(string $s): array { return ['__text' => $s]; }
function wa_map(array $pairs): array { return ['__map' => $pairs]; }

function wa_new_key(string $type): array
{
    $cfg = $type === 'rsa'
        ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
        : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
    $key = openssl_pkey_new($cfg);
    $d = openssl_pkey_get_details($key);
    $cose = $type === 'rsa'
        ? wa_map([[1, 3], [3, -257], [-1, $d['rsa']['n']], [-2, $d['rsa']['e']]])
        : wa_map([[1, 2], [3, -7], [-1, 1], [-2, str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)], [-3, str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)]]);
    return ['key' => $key, 'cose' => $cose];
}

function wa_clientdata(string $type, string $challenge, string $origin = 'https://portal.stromfueralle.at'): string
{
    return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);
}

function wa_register(array $k, string $challenge, string $rpId = 'stromfueralle.at', int $flags = 0x45, string $credId = null, string $origin = 'https://portal.stromfueralle.at'): array
{
    $credId ??= random_bytes(32);
    $authData = hash('sha256', $rpId, true) . chr($flags) . pack('N', 0)
        . str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . wa_cbor($k['cose']);
    $att = wa_cbor(wa_map([[wa_text('fmt'), wa_text('none')], [wa_text('attStmt'), wa_map([])], [wa_text('authData'), $authData]]));
    return ['clientDataJSON' => wa_clientdata('webauthn.create', $challenge, $origin), 'attestationObject' => $att, 'credId' => $credId];
}

function wa_assert(array $k, string $challenge, int $count, string $rpId = 'stromfueralle.at', int $flags = 0x05, string $origin = 'https://portal.stromfueralle.at'): array
{
    $cd = wa_clientdata('webauthn.get', $challenge, $origin);
    $authData = hash('sha256', $rpId, true) . chr($flags) . pack('N', $count);
    openssl_sign($authData . hash('sha256', $cd, true), $sig, $k['key'], OPENSSL_ALGO_SHA256);
    return ['clientDataJSON' => $cd, 'authenticatorData' => $authData, 'signature' => $sig];
}

function wa_throws(callable $fn, string $contains = ''): void
{
    try { $fn(); } catch (\RuntimeException $e) {
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            throw new Exception('falsche Fehlermeldung: ' . $e->getMessage());
        }
        return;
    }
    throw new Exception('erwartete eine RuntimeException' . ($contains ? " ({$contains})" : ''));
}

test('WebAuthn: Base64URL Rundlauf und Ablehnung von Müll', function () {
    $raw = random_bytes(33);
    assertSame($raw, WebAuthn::b64uDecode(WebAuthn::b64uEncode($raw)));
    assertFalse(str_contains(WebAuthn::b64uEncode("\xfb\xff\xfe"), '+'));
    wa_throws(fn() => WebAuthn::b64uDecode('***'));
});

test('WebAuthn: rpId und Herkunft', function () {
    assertSame('stromfueralle.at', WebAuthn::rpIdForHost('portal.stromfueralle.at', 'stromfueralle.at'));
    assertSame('stromfueralle.at', WebAuthn::rpIdForHost('stromfueralle.at:443', 'stromfueralle.at'));
    assertSame('localhost', WebAuthn::rpIdForHost('localhost:8790', 'stromfueralle.at'));
    assertSame('webapp.mechtronix.at', WebAuthn::rpIdForHost('webapp.mechtronix.at', 'stromfueralle.at'));
    assertSame('stromfueralle.at', WebAuthn::rpIdForHost('evilstromfueralle.at', 'x') === 'evilstromfueralle.at' ? 'stromfueralle.at' : 'x');
    assertTrue(WebAuthn::originAllowed('https://portal.stromfueralle.at', 'stromfueralle.at'));
    assertTrue(WebAuthn::originAllowed('https://stromfueralle.at', 'stromfueralle.at'));
    assertTrue(WebAuthn::originAllowed('http://localhost:8790', 'localhost'));
    assertFalse(WebAuthn::originAllowed('http://portal.stromfueralle.at', 'stromfueralle.at'), 'http nur für localhost');
    assertFalse(WebAuthn::originAllowed('https://evilstromfueralle.at', 'stromfueralle.at'));
    assertFalse(WebAuthn::originAllowed('https://stromfueralle.at.evil.com', 'stromfueralle.at'));
    assertFalse(WebAuthn::originAllowed('https://evil.com/portal.stromfueralle.at', 'stromfueralle.at'));
});

test('WebAuthn: CBOR-Leser (Zahlen, Strings, Maps, Fehler)', function () {
    $o = 0;
    assertSame(['a' => 1, -3 => 'xy'], WebAuthn::cborDecode(wa_cbor(wa_map([[wa_text('a'), 1], [-3, 'xy']])), $o));
    $o = 0; assertSame(-7, WebAuthn::cborDecode(wa_cbor(-7), $o));
    $o = 0; assertSame(70000, WebAuthn::cborDecode(wa_cbor(70000), $o));
    wa_throws(function () { $o = 0; WebAuthn::cborDecode("\x58\x40abc", $o); }, 'kurz');
    wa_throws(function () { $o = 0; WebAuthn::cborDecode('', $o); });
});

foreach (['ec' => 'ES256', 'rsa' => 'RS256'] as $type => $label) {
    test("WebAuthn $label: Registrierung und Anmeldung im Erfolgsfall", function () use ($type) {
        $k = wa_new_key($type);
        $ch = WebAuthn::b64uEncode(random_bytes(32));
        $r = wa_register($k, $ch);
        $res = WebAuthn::verifyRegistration($r['clientDataJSON'], $r['attestationObject'], $ch, 'stromfueralle.at');
        assertSame($r['credId'], $res['credentialId']);
        assertTrue(str_contains($res['publicKeyPem'], 'BEGIN PUBLIC KEY'));

        $ch2 = WebAuthn::b64uEncode(random_bytes(32));
        $a = wa_assert($k, $ch2, 5);
        $n = WebAuthn::verifyAssertion($a['clientDataJSON'], $a['authenticatorData'], $a['signature'], $res['publicKeyPem'], 0, $ch2, 'stromfueralle.at');
        assertSame(5, $n);
    });
}

test('WebAuthn Registrierung: falsche Challenge, rpId, Herkunft, fehlende Flags werden abgelehnt', function () {
    $k = wa_new_key('ec');
    $ch = 'abc';
    $ok = wa_register($k, $ch);
    wa_throws(fn() => WebAuthn::verifyRegistration($ok['clientDataJSON'], $ok['attestationObject'], 'andere', 'stromfueralle.at'), 'Challenge');
    wa_throws(fn() => WebAuthn::verifyRegistration($ok['clientDataJSON'], $ok['attestationObject'], $ch, 'portal.stromfueralle.at'), 'rpIdHash');
    $other = wa_register($k, $ch, 'andere-domain.at');
    wa_throws(fn() => WebAuthn::verifyRegistration($other['clientDataJSON'], $other['attestationObject'], $ch, 'stromfueralle.at'), 'rpIdHash');
    $evil = wa_register($k, $ch, 'stromfueralle.at', 0x45, null, 'https://evil.example');
    wa_throws(fn() => WebAuthn::verifyRegistration($evil['clientDataJSON'], $evil['attestationObject'], $ch, 'stromfueralle.at'), 'Herkunft');
    $noUv = wa_register($k, $ch, 'stromfueralle.at', 0x41);
    wa_throws(fn() => WebAuthn::verifyRegistration($noUv['clientDataJSON'], $noUv['attestationObject'], $ch, 'stromfueralle.at'), 'UV');
    $noAt = wa_register($k, $ch, 'stromfueralle.at', 0x05);
    wa_throws(fn() => WebAuthn::verifyRegistration($noAt['clientDataJSON'], $noAt['attestationObject'], $ch, 'stromfueralle.at'), 'AT');
    // Typ vertauscht: Anmelde-Antwort als Registrierung
    $cd = wa_clientdata('webauthn.get', $ch);
    wa_throws(fn() => WebAuthn::verifyRegistration($cd, $ok['attestationObject'], $ch, 'stromfueralle.at'), 'Anfragetyp');
    wa_throws(fn() => WebAuthn::verifyRegistration('kein json', $ok['attestationObject'], $ch, 'stromfueralle.at'));
    wa_throws(fn() => WebAuthn::verifyRegistration($ok['clientDataJSON'], 'müll', $ch, 'stromfueralle.at'));
});

test('WebAuthn Anmeldung: fremder Schlüssel, veränderte Daten, falsche Challenge/rpId/Flags abgelehnt', function () {
    $k = wa_new_key('ec'); $fremd = wa_new_key('ec');
    $ch = WebAuthn::b64uEncode(random_bytes(32));
    $reg = wa_register($k, $ch);
    $pem = WebAuthn::verifyRegistration($reg['clientDataJSON'], $reg['attestationObject'], $ch, 'stromfueralle.at')['publicKeyPem'];

    $v = fn(array $a, int $stored = 0, string $challenge = null, string $rp = 'stromfueralle.at') =>
        WebAuthn::verifyAssertion($a['clientDataJSON'], $a['authenticatorData'], $a['signature'], $pem, $stored, $challenge ?? $ch, $rp);

    assertSame(1, $v(wa_assert($k, $ch, 1)));
    wa_throws(fn() => $v(wa_assert($fremd, $ch, 1)), 'Signatur');
    $a = wa_assert($k, $ch, 1); $a['authenticatorData'][36] = chr(ord($a['authenticatorData'][36]) ^ 1);
    wa_throws(fn() => $v($a), 'Signatur');
    wa_throws(fn() => $v(wa_assert($k, $ch, 1), 0, 'andere'), 'Challenge');
    wa_throws(fn() => $v(wa_assert($k, $ch, 1, 'andere.at')), 'rpIdHash');
    wa_throws(fn() => $v(wa_assert($k, $ch, 1, 'stromfueralle.at', 0x01)), 'UV');
    wa_throws(fn() => $v(wa_assert($k, $ch, 1, 'stromfueralle.at', 0x04)), 'UP');
    wa_throws(fn() => $v(wa_assert($k, $ch, 1, 'stromfueralle.at', 0x05, 'https://evil.example')), 'Herkunft');
});

test('WebAuthn Anmeldung: Zähler muss steigen, außer Gerät meldet dauerhaft 0', function () {
    $k = wa_new_key('ec');
    $ch = 'c';
    $reg = wa_register($k, $ch);
    $pem = WebAuthn::verifyRegistration($reg['clientDataJSON'], $reg['attestationObject'], $ch, 'stromfueralle.at')['publicKeyPem'];
    $v = fn(int $new, int $stored) => (function () use ($k, $ch, $pem, $new, $stored) {
        $a = wa_assert($k, $ch, $new);
        return WebAuthn::verifyAssertion($a['clientDataJSON'], $a['authenticatorData'], $a['signature'], $pem, $stored, $ch, 'stromfueralle.at');
    })();
    assertSame(0, $v(0, 0), 'iCloud-Passkey: immer 0');
    assertSame(8, $v(8, 7));
    wa_throws(fn() => $v(7, 7), 'Zähler');
    wa_throws(fn() => $v(3, 7), 'Zähler');
    wa_throws(fn() => $v(0, 7), 'Zähler');
});

test('WebAuthn: Schlüssel-Umwandlung lehnt unbekannte Verfahren und kaputte Schlüssel ab', function () {
    wa_throws(fn() => WebAuthn::coseToPem([1 => 1, 3 => -8]), 'nicht unterstützt'); // OKP/EdDSA
    wa_throws(fn() => WebAuthn::coseToPem([1 => 2, 3 => -7, -1 => 2, -2 => str_repeat('a', 32), -3 => str_repeat('a', 32)]), 'P-256');
    wa_throws(fn() => WebAuthn::coseToPem([1 => 2, 3 => -7, -1 => 1, -2 => 'kurz', -3 => 'kurz']), 'EC');
});
