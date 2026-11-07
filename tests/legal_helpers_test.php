<?php

declare(strict_types=1);

// ─── Zeitzone: Anzeige immer Europe/Vienna (DB speichert UTC) ───────────────────────────────
test('fmtVienna: UTC-Zeitstempel aus Postgres -> Wiener Winterzeit (+1 h)', fn() =>
    assertSame('05.11.2026 20:59', fmtVienna('2026-11-05 19:59:00.123456+00')));
test('fmtVienna: UTC-Zeitstempel -> Wiener Sommerzeit (+2 h)', fn() =>
    assertSame('20.10.2026 19:59', fmtVienna('2026-10-20 17:59:00+00')));
test('fmtVienna: Wert mit fremder Zeitzone wird korrekt umgerechnet', fn() =>
    assertSame('05.11.2026 20:59', fmtVienna('2026-11-05T21:59:00+02:00')));
test('fmtVienna: Wert OHNE Zeitzone gilt als UTC, nicht als Ortszeit', fn() =>
    assertSame('05.11.2026 20:59', fmtVienna('2026-11-05 19:59:00')));
test('fmtVienna: unabhängig von date.timezone der PHP-Installation', function () {
    $prev = date_default_timezone_get();
    date_default_timezone_set('America/New_York');
    try {
        assertSame('05.11.2026 20:59', fmtVienna('2026-11-05 19:59:00+00'));
    } finally {
        date_default_timezone_set($prev);
    }
});
test('fmtVienna: leer/ungültig -> Platzhalter, eigenes Format', function () {
    assertSame('--', fmtVienna(null));
    assertSame('—', fmtVienna('', 'd.m.Y', '—'));
    assertSame('--', fmtVienna('kein datum'));
    assertSame('05.11.2026', fmtVienna('2026-11-05 19:59:00+00', 'd.m.Y'));
});

// ─── Echte Client-IP hinter nginx-Proxy -> Traefik -> webapp ────────────────────────────────
$ipTrusted = ['127.0.0.1', '::1', '172.16.0.0/12', '10.0.0.144'];

test('clientIp: vom Proxy gesetzter X-Forwarded-For liefert die echte Client-IP', fn() =>
    assertSame('203.0.113.7', clientIp(
        ['REMOTE_ADDR' => '172.19.0.3', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.144'], $ipTrusted)));
test('clientIp: vom Client gefälschter erster XFF-Eintrag wird ignoriert (von rechts gelesen)', fn() =>
    assertSame('203.0.113.7', clientIp(
        ['REMOTE_ADDR' => '172.19.0.3', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.7, 10.0.0.144'], $ipTrusted)));
test('clientIp: Header einer NICHT vertrauenswürdigen Verbindung werden komplett ignoriert', fn() =>
    assertSame('198.51.100.9', clientIp(
        ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_REAL_IP' => '1.2.3.4'], $ipTrusted)));
test('clientIp: ohne XFF Rückfall auf X-Real-IP', fn() =>
    assertSame('203.0.113.7', clientIp(['REMOTE_ADDR' => '172.19.0.3', 'HTTP_X_REAL_IP' => '203.0.113.7'], $ipTrusted)));
test('clientIp: ohne jeden Header bleibt REMOTE_ADDR (z.B. lokale Entwicklung)', fn() =>
    assertSame('172.19.0.3', clientIp(['REMOTE_ADDR' => '172.19.0.3'], $ipTrusted)));
test('clientIp: Mitglied im selben LAN (10.0.0.x, nicht der Proxy) wird korrekt erkannt', fn() =>
    assertSame('10.0.0.57', clientIp(
        ['REMOTE_ADDR' => '172.19.0.3', 'HTTP_X_FORWARDED_FOR' => '10.0.0.57, 10.0.0.144'], $ipTrusted)));
test('clientIp: IPv6-Client', fn() =>
    assertSame('2001:db8::1', clientIp(
        ['REMOTE_ADDR' => '172.19.0.3', 'HTTP_X_FORWARDED_FOR' => '2001:db8::1, 10.0.0.144'], $ipTrusted)));
test('clientIp: ungültiger XFF-Eintrag bricht die Auswertung ab statt Müll zu speichern', fn() =>
    assertSame('203.0.113.7', clientIp(
        ['REMOTE_ADDR' => '172.19.0.3', 'HTTP_X_FORWARDED_FOR' => '<script>, 10.0.0.144', 'HTTP_X_REAL_IP' => '203.0.113.7'], $ipTrusted)));
test('clientIp: kein REMOTE_ADDR -> "unknown"', fn() => assertSame('unknown', clientIp([], $ipTrusted)));

test('ipInCidrs: Netze und Einzel-IPs', function () {
    assertTrue(ipInCidrs('172.19.0.3', ['172.16.0.0/12']));
    assertFalse(ipInCidrs('172.32.0.1', ['172.16.0.0/12']));
    assertTrue(ipInCidrs('10.0.0.144', ['10.0.0.144']));
    assertFalse(ipInCidrs('10.0.0.145', ['10.0.0.144']));
    assertTrue(ipInCidrs('::1', ['::1']));
    assertFalse(ipInCidrs('::1', ['127.0.0.1']), 'IPv6 nie gegen IPv4-Netz');
    assertFalse(ipInCidrs('kaputt', ['0.0.0.0/0']));
});

// ─── P1-Schlüssel ───────────────────────────────────────────────────────────────────────────
test('normalizeP1Key: 32 Hex-Zeichen, Leerzeichen/Trenner und Kleinschreibung werden normalisiert', function () {
    assertSame('0A1B2C3D4E5F60718293A4B5C6D7E8F9', normalizeP1Key('0a1b2c3d4e5f60718293a4b5c6d7e8f9'));
    assertSame('0A1B2C3D4E5F60718293A4B5C6D7E8F9', normalizeP1Key('0A1B 2C3D 4E5F 6071 8293 A4B5 C6D7 E8F9'));
    assertSame('0A1B2C3D4E5F60718293A4B5C6D7E8F9', normalizeP1Key('0A:1B:2C:3D:4E:5F:60:71:82:93:A4:B5:C6:D7:E8:F9'));
});
test('normalizeP1Key: falsche Länge oder Nicht-Hex -> null', function () {
    assertSame(null, normalizeP1Key(str_repeat('A', 31)));
    assertSame(null, normalizeP1Key(str_repeat('A', 33)));
    assertSame(null, normalizeP1Key(str_repeat('G', 32)));
    assertSame(null, normalizeP1Key(''));
    assertSame(null, normalizeP1Key(null));
});

test('Sammelliste P1: CSV mit BOM, Semikolon, Kopfzeile und Datum TT.MM.JJJJ', function () {
    $csv = p1SammellisteCsv([[
        'last_name' => 'Muster', 'first_name' => 'Max', 'address' => 'Teststraße 1', 'zip' => '9560',
        'city' => 'Feldkirchen', 'zaehlpunkt_nr' => 'AT0070000000000000000000000000001', 'meter_code' => '1234567890123',
        'beitrittsdatum' => '2026-07-14', 'vollmacht_p1' => 'ja',
    ]]);
    assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
    $lines = explode("\n", trim(substr($csv, 3)));
    assertSame('Nachname;Vorname;Straße;PLZ;Ort;Zählpunktnummer;Zählernummer;"Beitrittsdatum (Vollmacht)";"Vollmacht umfasst P1"', $lines[0]);
    assertSame('Muster;Max;"Teststraße 1";9560;Feldkirchen;AT0070000000000000000000000000001;1234567890123;14.07.2026;ja', $lines[1]);
});
test('Sammelliste P1: Formel-Injection in Excel wird entschärft', function () {
    $csv = p1SammellisteCsv([['last_name' => '=HYPERLINK("x")', 'first_name' => '@SUMME(A1)']]);
    assertContains("'=HYPERLINK", $csv);
    assertContains("'@SUMME", $csv);
});

test('extractOrtFromAddress: Ort ohne PLZ aus der letzten Adresszeile', function () {
    assertSame('St. Nikolai', extractOrtFromAddress('Eichenweg 2, 9560 St. Nikolai'));
    assertSame('Wien', extractOrtFromAddress('1010 Wien'));
    assertSame('', extractOrtFromAddress(null));
});

// ─── Sicherung /admin/templates (ZIP mit Datumsordner) ──────────────────────────────────────
test('buildBackupZip: Dateien + LIESMICH im Datumsordner, fehlende Dateien übersprungen', function () {
    $dir = sys_get_temp_dir() . '/backuptest_' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir . '/rechnung.tex', '\\documentclass{article}');
    file_put_contents($dir . '/infoblatt.pdf', '%PDF-1.5');
    $zipPath = $dir . '/out.zip';
    try {
        $count = buildBackupZip($zipPath, 'stromfueralle-sicherung-2026-11-08', [
            'rechnung.tex'  => $dir . '/rechnung.tex',
            'infoblatt.pdf' => $dir . '/infoblatt.pdf',
            'fehlt.png'     => $dir . '/gibt-es-nicht.png',
        ], "Inhalt\r\n");
        assertSame(2, $count);
        $zip = new ZipArchive();
        assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
        sort($names);
        assertSame([
            'stromfueralle-sicherung-2026-11-08/LIESMICH.txt',
            'stromfueralle-sicherung-2026-11-08/infoblatt.pdf',
            'stromfueralle-sicherung-2026-11-08/rechnung.tex',
        ], $names);
        assertSame('\\documentclass{article}', $zip->getFromName('stromfueralle-sicherung-2026-11-08/rechnung.tex'));
        $zip->close();
    } finally {
        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }
});
