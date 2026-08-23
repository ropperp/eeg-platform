<?php

declare(strict_types=1);

// ─── IBAN-Validierung (Mod-97 / ISO 7064) ────────────────────────────────────
test('IBAN AT gültig (mit Leerzeichen)', fn() => assertTrue(validateIban('AT61 1904 3002 3457 3201')));
test('IBAN AT gültig (ohne Leerzeichen)', fn() => assertTrue(validateIban('AT611904300234573201')));
test('IBAN DE gültig', fn() => assertTrue(validateIban('DE89 3704 0044 0532 0130 00')));
test('IBAN falsche Prüfsumme', fn() => assertFalse(validateIban('AT611904300234573200')));
test('IBAN zu kurz', fn() => assertFalse(validateIban('AT61')));
test('IBAN leer', fn() => assertFalse(validateIban('')));
test('IBAN mit Buchstaben an falscher Stelle', fn() => assertFalse(validateIban('ATXX1904300234573201')));

// ─── Zählpunktnummer (AT + 31 Zeichen = 33) ──────────────────────────────────
test('Zählpunkt gültig (33 Zeichen)', fn() => assertTrue(validateZaehlpunkt('AT' . str_repeat('0', 31))));
test('Zählpunkt zu kurz', fn() => assertFalse(validateZaehlpunkt('AT' . str_repeat('0', 30))));
test('Zählpunkt ohne AT-Präfix', fn() => assertFalse(validateZaehlpunkt('DE' . str_repeat('0', 31))));
test('Zählpunkt kleingeschrieben wird normalisiert', fn() => assertTrue(validateZaehlpunkt('at' . str_repeat('a', 31))));
test('Zählpunkt mit eingestreuten Leerzeichen bleibt gültig (Leerzeichen zählen nicht mit)', function () {
    // 33 echte Zeichen, wie beim Kopieren aus einem Netzbetreiber-Portal (z.B. Kelag) in
    // 4er-Gruppen formatiert -- die Leerzeichen dürfen die Zeichen NICHT verdrängen.
    $valid = 'AT' . str_repeat('0', 31);
    $spaced = implode(' ', str_split($valid, 4));
    assertTrue(validateZaehlpunkt($spaced));
});
test('Zählpunkt mit Leerzeichen, aber zu wenig echten Zeichen, bleibt ungültig', function () {
    // Simuliert genau den gemeldeten Fehler: ein auf 33 Zeichen begrenztes Eingabefeld hat
    // beim Einfügen Leerzeichen mitgezählt, wodurch die letzten echten Ziffern abgeschnitten
    // wurden.
    $valid = 'AT' . str_repeat('0', 31);
    $spaced = implode(' ', str_split($valid, 4));
    $truncated = substr($spaced, 0, 33);
    assertFalse(validateZaehlpunkt($truncated));
});
test('normalizeZaehlpunkt() entfernt Leerzeichen und wandelt in Großbuchstaben', function () {
    $valid = 'AT' . str_repeat('0', 31);
    $spacedLower = implode(' ', str_split(strtolower($valid), 4));
    assertSame($valid, normalizeZaehlpunkt($spacedLower));
});

// ─── LaTeX-Escaping ──────────────────────────────────────────────────────────
test('texEscape maskiert % und &', fn() => assertSame('50\\% \\& mehr', texEscape('50% & mehr')));
test('texEscape maskiert Unterstrich', fn() => assertSame('a\\_b', texEscape('a_b')));
test('texEscape wandelt Gedankenstrich', fn() => assertSame('a--b', texEscape('a—b')));
test('texEscape lässt harmlosen Text unangetastet', fn() => assertSame('Max Mustermann', texEscape('Max Mustermann')));

// ─── Rechnungs-Zusatzpositionen (4-Spalten-Format) ───────────────────────────
test('Zusatzposition: 4 Zellen, Minusbetrag', function () {
    $out = rechnungExtraItemsLatex([['label' => 'Rabatt', 'quantity' => 1, 'unit' => 'Stk', 'amount_eur' => -6.0]]);
    assertSame('  Rabatt & & & $-$\\,6,00 \\\\', $out);
});
test('Zusatzposition: abweichende Menge wandert in den Text', function () {
    $out = rechnungExtraItemsLatex([['label' => 'Zähler', 'quantity' => 2, 'unit' => 'Stk', 'amount_eur' => 10.0]]);
    assertContains('Zähler (2 Stk)', $out);
    assertContains('& & & 10,00', $out);
});
test('Zusatzposition: leere Liste ergibt leeren String', fn() => assertSame('', rechnungExtraItemsLatex([])));

// ─── Rechnungs-Positionszeilen pro Zählpunkt ─────────────────────────────────
test('Positionszeile mit Zählpunkt-Sublabel', function () {
    $out = rechnungPositionenLatex(
        [['zaehlpunkt_nr' => 'AT001', 'kwh' => 250.0, 'rate_ct_kwh' => 9.8, 'amount_eur' => 24.5]],
        'Energiebezug', false
    );
    assertSame('  Energiebezug\\newline{\\footnotesize\\color{midgray}Zählpunkt: AT001} & 250,00 & 9,8000 & 24,50 \\\\', $out);
});
test('Zwei Zählpunkte werden durch feine Linie getrennt', function () {
    $out = rechnungPositionenLatex([
        ['zaehlpunkt_nr' => 'A', 'kwh' => 1, 'rate_ct_kwh' => 1, 'amount_eur' => 1],
        ['zaehlpunkt_nr' => 'B', 'kwh' => 2, 'rate_ct_kwh' => 2, 'amount_eur' => 2],
    ], 'X', false);
    assertContains('\\arrayrulecolor{rulegray}\\hline', $out);
});
test('Einspeisung: Minuszeichen, kein Sublabel wenn Zählpunkt leer', function () {
    $out = rechnungPositionenLatex(
        [['zaehlpunkt_nr' => '', 'kwh' => 85, 'rate_ct_kwh' => 7.5, 'amount_eur' => -6.38]],
        'Einspeisevergütung', true
    );
    assertContains('$-$\\,6,38', $out);
    assertFalse(str_contains($out, 'Zählpunkt:'), 'kein Zählpunkt-Sublabel bei leerer Nummer');
});
test('Leere Positionsliste ergibt leeren String', fn() => assertSame('', rechnungPositionenLatex([], 'X', false)));

// encryptSecret()/decryptSecret() -- AES-256-CBC-Verschlüsselung fürs ESP-WLAN-Passwort.
test('encryptSecret()/decryptSecret() Round-Trip liefert Original zurück', function () {
    $plain = 'Mein-WLAN-Passwort-123!';
    $enc = encryptSecret($plain);
    assertFalse($enc === $plain, 'verschlüsselter Wert darf nicht dem Klartext entsprechen');
    assertSame($plain, decryptSecret($enc));
});
test('encryptSecret() liefert bei gleichem Klartext unterschiedliche Ciphertexte (zufälliger IV)', function () {
    $a = encryptSecret('gleicher-text');
    $b = encryptSecret('gleicher-text');
    assertFalse($a === $b, 'IV muss zufällig sein, sonst gleicher Ciphertext bei gleichem Klartext');
});
test('decryptSecret() liefert leeren String bei leerem/kaputtem Wert', function () {
    assertSame('', decryptSecret(null));
    assertSame('', decryptSecret(''));
    assertSame('', decryptSecret('kein-gueltiges-base64!!!'));
});

// csrfToken()/csrfValid() -- zentraler CSRF-Schutz für alle POST-Routen (siehe Router.php).
test('csrfToken() liefert ein 64-stelliges Hex-Token', function () {
    unset($_SESSION['csrf_token']);
    $token = csrfToken();
    assertSame(64, strlen($token));
    assertTrue((bool)preg_match('/^[0-9a-f]{64}$/', $token), 'Token muss reines Hex sein');
});
test('csrfToken() liefert innerhalb derselben Session immer dasselbe Token', function () {
    unset($_SESSION['csrf_token']);
    $a = csrfToken();
    $b = csrfToken();
    assertSame($a, $b);
});
test('csrfValid() akzeptiert das korrekte Token', function () {
    unset($_SESSION['csrf_token']);
    $token = csrfToken();
    assertTrue(csrfValid($token));
});
test('csrfValid() lehnt ein falsches Token ab', function () {
    unset($_SESSION['csrf_token']);
    csrfToken();
    assertFalse(csrfValid('0000000000000000000000000000000000000000000000000000000000000000'));
});
test('csrfValid() lehnt fehlendes/leeres Token ab', function () {
    unset($_SESSION['csrf_token']);
    csrfToken();
    assertFalse(csrfValid(null));
    assertFalse(csrfValid(''));
});

// totpSecretFromStorage() -- muss sowohl alte Klartext- als auch neue verschlüsselte
// TOTP-Secrets lesen können (Rollout-Sicherheit, siehe scripts/migrate_encrypt_totp_secrets.php).
test('totpSecretFromStorage() gibt ein noch unverschlüsseltes Base32-Secret unverändert zurück', function () {
    $plain = totpGenerateSecret();
    assertSame($plain, totpSecretFromStorage($plain));
});
test('totpSecretFromStorage() entschlüsselt ein bereits verschlüsseltes Secret korrekt', function () {
    $plain = totpGenerateSecret();
    $stored = encryptSecret($plain);
    assertSame($plain, totpSecretFromStorage($stored));
});
test('totpSecretFromStorage() liefert leeren String bei leerem Wert', function () {
    assertSame('', totpSecretFromStorage(null));
    assertSame('', totpSecretFromStorage(''));
});
test('totpVerify funktioniert nach totpSecretFromStorage() für Klartext UND verschlüsselt gleichermaßen', function () {
    $plain = totpGenerateSecret();
    $code = totpCodeAt($plain, time());
    assertTrue(totpVerify(totpSecretFromStorage($plain), $code), 'Klartext-Pfad (vor Migration)');
    assertTrue(totpVerify(totpSecretFromStorage(encryptSecret($plain)), $code), 'verschlüsselter Pfad (nach Migration)');
});

// monatsLabel() -- deutscher Monatsname + Jahr fürs Mitglieder-Dashboard (Verbrauchsverlauf).
test('monatsLabel() formatiert Monat und Jahr auf Deutsch', function () {
    assertSame('Juni 2026', monatsLabel('2026-06-01 00:00:00+00'));
    assertSame('Jänner 2027', monatsLabel('2027-01-15'));
    assertSame('Dezember 2025', monatsLabel('2025-12-01'));
});

// filenameWithExtension() -- App-Downloads brauchen eine echte Dateiendung (member_files.name
// ist nur eine frei getippte Anzeige-Bezeichnung ohne Endung), siehe /api/v1/documents* und
// /api/v1/manager/members/:id/files/:fileid/download.
test('filenameWithExtension() hängt die echte Endung an, wenn sie fehlt', function () {
    assertSame('Beitrittserklärung.pdf', filenameWithExtension('Beitrittserklärung', '/storage/uploads/abc123.pdf'));
});
test('filenameWithExtension() lässt einen bereits passenden Namen unverändert', function () {
    assertSame('Vertrag.pdf', filenameWithExtension('Vertrag.pdf', '/storage/uploads/abc123.pdf'));
});
test('filenameWithExtension() erkennt die Endung unabhängig von Groß-/Kleinschreibung', function () {
    assertSame('Foto.JPG', filenameWithExtension('Foto.JPG', '/storage/uploads/abc123.jpg'));
});
test('filenameWithExtension() lässt den Namen unverändert, wenn der gespeicherte Pfad selbst keine Endung hat', function () {
    assertSame('Unbenannt', filenameWithExtension('Unbenannt', '/storage/uploads/abc123'));
});

// ─── Demo-Login PII-Maskierung (demoMask*, siehe migrate_20260905.sql) ───────────────────────
test('demoMaskKeepStart() zeigt die ersten 4 Zeichen, Rest wird durch Punkte ersetzt', function () {
    assertSame('Step•••••', demoMaskKeepStart('Stephanie'));
});
test('demoMaskKeepStart() füllt auch bei kurzen Werten auf (Länge kein Hinweis auf den echten Wert)', function () {
    assertSame('Eva•••', demoMaskKeepStart('Eva'));
});
test('demoMaskKeepEnd() zeigt nur die letzten 4 Zeichen', function () {
    assertSame('•••••••••••••••• 3201', demoMaskKeepEnd('AT611904300234573201'));
});
test('demoMaskFull() macht den Wert komplett unkenntlich, deckelt aber die angezeigte Länge', function () {
    assertSame('••••••••••', demoMaskFull(str_repeat('x', 40)));
    assertSame('•••', demoMaskFull('ab'));
});

test('demoMaskMember() maskiert personenbezogene Felder eines echten Mitglieds im Demo-Modus', function () {
    $row = [
        'first_name' => 'Stefanie', 'last_name' => 'Schwaiger', 'email' => 'stefanie@example.at',
        'phone' => '+43 660 1234567', 'geburtsdatum' => '1975-03-01', 'zip' => '9500',
        'address' => 'Musterstraße 1', 'city' => 'Villach', 'znr_bezug' => 'AT0070000000000000000000000000001',
        'photo_path' => '/storage/member.jpg', 'kundennummer' => 10042, 'is_demo' => false,
    ];
    $masked = demoMaskMember($row, true);
    assertSame('Stef••••', $masked['first_name']);
    assertSame('•••••••••', $masked['last_name']);
    assertTrue(str_ends_with($masked['phone'], '4567'), 'Telefonnummer behält die letzten 4 Stellen sichtbar');
    assertSame('••.••.••••', $masked['geburtsdatum']);
    assertSame(null, $masked['photo_path']);
    assertSame(10042, $masked['kundennummer'], 'Kundennummer ist bewusst kein maskiertes Feld');
});
test('demoMaskMember() lässt Daten unverändert, wenn nicht im Demo-Modus', function () {
    $row = ['first_name' => 'Stefanie', 'last_name' => 'Schwaiger'];
    assertSame($row, demoMaskMember($row, false));
});
test('demoMaskMember() maskiert NIE ein fiktives Demo-Mitglied selbst (is_demo=true)', function () {
    $row = ['first_name' => 'Verbraucher', 'last_name' => '1', 'is_demo' => true];
    assertSame($row, demoMaskMember($row, true));
});
test('demoMaskMember() lässt null unverändert', function () {
    assertSame(null, demoMaskMember(null, true));
});
test('demoMaskMembers() maskiert nur echte, keine fiktiven Mitglieder', function () {
    $rows = [
        ['first_name' => 'Stefanie', 'last_name' => 'Schwaiger', 'is_demo' => false],
        ['first_name' => 'Verbraucher', 'last_name' => '1', 'is_demo' => true],
    ];
    $masked = demoMaskMembers($rows, true);
    assertSame('Stef••••', $masked[0]['first_name']);
    assertSame('Verbraucher', $masked[1]['first_name']);
});

test('demoMaskMeteringPoint() maskiert Zählpunktnummer und WLAN-Daten im Demo-Modus', function () {
    $row = ['zaehlpunkt_nr' => 'AT0070000000000000000000000000001', 'wifi_ssid' => 'FritzBox123', 'type' => 'consumer'];
    $masked = demoMaskMeteringPoint($row, true);
    assertSame('••••••••••', $masked['zaehlpunkt_nr']);
    assertSame('••••••••••', $masked['wifi_ssid']);
    assertSame('consumer', $masked['type'], 'nicht-personenbezogene Felder bleiben unverändert');
});

// ─── Demo-Login: Postfach/Settings-Maskierung (Patrick, 23.08.2026) ──────────────────────────
test('demoMaskNotification() maskiert den Namen in einer Online-Beitrittserklärung-Meldung', function () {
    $row = [
        'typ' => 'beitrittserklaerung',
        'titel' => 'Neue Beitrittserklärung: Max Mustermann',
        'text' => 'Online-Beitrittserklärung wurde übermittelt und wartet auf Freigabe.',
    ];
    $masked = demoMaskNotification($row, true);
    assertSame('Neue Beitrittserklärung: ••••••••••', $masked['titel']);
    assertSame($row['text'], $masked['text'], 'Freitext ohne PII bleibt unverändert');
});
test('demoMaskNotification() maskiert die Zählernummer in einer "unbekannter Zähler"-Meldung', function () {
    $row = [
        'typ' => 'unbekannter_zaehler',
        'titel' => 'Unbekannte Zählernummer gemeldet',
        'text' => 'AT0030000000000000000000000000099: Ein Gerät sendet Daten für die Zählernummer AT0030000000000000000000000000099.',
    ];
    $masked = demoMaskNotification($row, true);
    assertSame('Unbekannte Zählernummer gemeldet', $masked['titel'], 'Titel enthält hier keine PII');
    assertTrue(str_starts_with($masked['text'], '••••••••••: '), 'Führende Zählernummer wird maskiert');
});
test('demoMaskNotification() lässt unbekannte Typen und den Nicht-Demo-Modus unverändert', function () {
    $row = ['typ' => 'zaehlernummer_geteilt', 'titel' => 'Zählernummer doppelt vergeben', 'text' => 'irrelevant'];
    assertSame($row, demoMaskNotification($row, true));
    $row2 = ['typ' => 'beitrittserklaerung', 'titel' => 'Neue Beitrittserklärung: Max Mustermann'];
    assertSame($row2, demoMaskNotification($row2, false));
});

test('demoMaskCommunitySettings() lässt ZVR-Nummer und Namen sichtbar, maskiert den Rest', function () {
    $row = [
        'name' => 'Strompool Feldkirchen', 'zvr_number' => '1778816746',
        'contact_email' => 'obmann@example.at', 'account_holder' => 'Max Musterhalter',
        'creditor_id' => 'AT12ZZZ00000000000', 'marktpartner_id' => 'RC108175',
    ];
    $masked = demoMaskCommunitySettings($row, true);
    assertSame('Strompool Feldkirchen', $masked['name']);
    assertSame('1778816746', $masked['zvr_number']);
    assertSame('••••••••••', $masked['contact_email']);
    assertSame('••••••••••', $masked['account_holder']);
    assertSame('AT12••••••••••••••', $masked['creditor_id']);
    assertSame('RC10••••', $masked['marktpartner_id']);
});
test('demoMaskCommunitySettings() lässt Daten unverändert, wenn nicht im Demo-Modus', function () {
    $row = ['name' => 'Strompool Feldkirchen', 'contact_email' => 'obmann@example.at'];
    assertSame($row, demoMaskCommunitySettings($row, false));
});

test('demoMaskSettingsUser() zeigt nur die ersten 3 Buchstaben des Vornamens', function () {
    $row = ['first_name' => 'Patrick', 'last_name' => 'Ropper', 'signature_image' => 'data:...'];
    $masked = demoMaskSettingsUser($row, true);
    assertSame('Pat••••', $masked['first_name']);
    assertSame('••••••', $masked['last_name']);
    assertSame('data:...', $masked['signature_image'], 'nicht-personenbezogene Felder bleiben unverändert');
});
test('demoMaskSettingsUser() lässt null und den Nicht-Demo-Modus unverändert', function () {
    assertSame(null, demoMaskSettingsUser(null, true));
    $row = ['first_name' => 'Patrick', 'last_name' => 'Ropper'];
    assertSame($row, demoMaskSettingsUser($row, false));
});

test('demoMaskTaxConfig() zeigt nur die ersten 3 Zeichen der UID-Nummer', function () {
    $row = ['uid_number' => 'ATU12345678', 'tax_model' => 'standard'];
    $masked = demoMaskTaxConfig($row, true);
    assertSame('ATU••••••••', $masked['uid_number']);
    assertSame('standard', $masked['tax_model']);
});
test('demoMaskTaxConfig() lässt null und den Nicht-Demo-Modus unverändert', function () {
    assertSame(null, demoMaskTaxConfig(null, true));
    $row = ['uid_number' => 'ATU12345678'];
    assertSame($row, demoMaskTaxConfig($row, false));
});

// ─── Demo-Login: Beitrittserklärungen + Aktivitätslog (Patrick, 23.08.2026) ──────────────────
test('demoMaskApplication() maskiert eigene Spaltennamen und blendet Unterschriften aus', function () {
    $row = [
        'first_name' => 'Max', 'last_name' => 'Mustermann', 'email' => 'max@example.at',
        'iban' => 'AT611904300234573201', 'bezug_zaehlpunkt' => 'AT0030000000000000000000000000001',
        'signature_image' => 'data:image/png;base64,xxx', 'sepa_signature_image' => 'data:image/png;base64,yyy',
        'stromlieferant' => 'Kelag',
    ];
    $masked = demoMaskApplication($row, true);
    assertSame('Max•••', $masked['first_name']);
    assertSame('••••••••••', $masked['iban']);
    assertSame('••••••••••', $masked['bezug_zaehlpunkt']);
    assertSame(null, $masked['signature_image']);
    assertSame(null, $masked['sepa_signature_image']);
    assertSame('Kelag', $masked['stromlieferant'], 'Stromlieferant ist keine PII');
});
test('demoMaskApplication() lässt null und den Nicht-Demo-Modus unverändert', function () {
    assertSame(null, demoMaskApplication(null, true));
    $row = ['first_name' => 'Max', 'last_name' => 'Mustermann'];
    assertSame($row, demoMaskApplication($row, false));
});
test('demoMaskApplications() maskiert eine Liste', function () {
    $rows = [['first_name' => 'Max', 'last_name' => 'Mustermann']];
    $masked = demoMaskApplications($rows, true);
    assertSame('Max•••', $masked[0]['first_name']);
});

test('demoMaskAuditLog() maskiert den/die Handelnde und blendet den Freitext aus', function () {
    $rows = [[
        'first_name' => 'Patrick', 'last_name' => 'Ropper', 'email' => 'patrick@example.at',
        'aktion' => 'dsgvo.export.manager', 'beschreibung' => 'DSGVO-Auskunft für Stefanie Schwaiger exportiert',
    ]];
    $masked = demoMaskAuditLog($rows, true);
    assertSame('Patr•••', $masked[0]['first_name']);
    assertSame('••••••', $masked[0]['last_name']);
    assertSame('Details ausgeblendet (Demo-Zugang).', $masked[0]['beschreibung']);
    assertSame('dsgvo.export.manager', $masked[0]['aktion'], 'Aktionstyp bleibt sichtbar');
});
test('demoMaskAuditLog() lässt Daten unverändert, wenn nicht im Demo-Modus', function () {
    $rows = [['first_name' => 'Patrick', 'beschreibung' => 'irrelevant']];
    assertSame($rows, demoMaskAuditLog($rows, false));
});
