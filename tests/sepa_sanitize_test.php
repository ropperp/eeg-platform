<?php

declare(strict_types=1);

// sepaSanitize() -- bereinigt SEPA-XML-Textfelder auf den erlaubten Zeichensatz
// (a-z A-Z 0-9 / - ? : ( ) . , ' + und Leerzeichen), siehe Vorfall 18.10.2026: George/Sparkasse
// lehnte eine komplette Gutschriften-Datei wegen eines Unterstrichs in der EndToEndId ab.

test('sepaSanitize: Unterstrich wird zu Bindestrich', function () {
    assertSame('RE-260007-RC108175', sepaSanitize('RE-260007_RC108175', 35, true));
});

test('sepaSanitize: deutsche Umlaute werden ausgeschrieben, nicht nur entfernt', function () {
    assertSame('Hoefferer', sepaSanitize('Höfferer'));
    assertSame('Sued-West', sepaSanitize('Süd-West'));
    assertSame('Muenchen', sepaSanitize('München'));
});

test('sepaSanitize: scharfes ß wird zu ss', function () {
    assertSame('Strasse', sepaSanitize('Straße'));
});

test('sepaSanitize: weitere diakritische Zeichen werden transliteriert', function () {
    assertSame('Francois', sepaSanitize('François'));
    assertSame('Nunez', sepaSanitize('Nuñez'));
});

test('sepaSanitize: Überlänge wird gekürzt statt zu scheitern', function () {
    $lang = str_repeat('A', 200);
    assertSame(35, strlen(sepaSanitize($lang, 35)));
    assertSame(70, strlen(sepaSanitize($lang, 70)));
});

test('sepaSanitize: führende/abschließende Leerzeichen entfernt', function () {
    assertSame('Max Muster', sepaSanitize('  Max Muster  '));
});

test('sepaSanitize: Id-Feld -- kein führender/abschließender Schrägstrich', function () {
    assertSame('RE-1', sepaSanitize('/RE-1/', 35, true));
});

test('sepaSanitize: Id-Feld -- doppelter Schrägstrich wird zu einfachem', function () {
    assertSame('a/b', sepaSanitize('a//b', 35, true));
});

test('sepaSanitize: Namensfeld behält einen einzelnen Schrägstrich (nicht Id-Feld)', function () {
    assertSame('a/b', sepaSanitize('a/b', 70, false));
});

test('sepaSanitize: nicht erlaubte Sonderzeichen werden durch Leerzeichen ersetzt', function () {
    assertSame('Mueller Co', sepaSanitize('Müller&Co'));
});

// Ende-zu-Ende: EndToEndId/MndtId/Namen im tatsächlichen pain.008-/pain.001-XML.

test('pain.008: Unterstrich in EndToEndId erscheint im XML als Bindestrich', function () {
    $creditor = ['name' => 'EEG Test', 'iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW', 'creditor_id' => 'AT12ZZZ00000000001'];
    $txns = [
        ['end_to_end_id' => 'RE-260007_RC108175', 'amount' => 10.00, 'mandate_ref' => 'S00000F2026A10003',
         'mandate_date' => '2026-01-01', 'debtor_name' => 'Höfferer', 'debtor_iban' => 'AT023200000000005678',
         'debtor_bic' => '', 'remittance' => 'Rechnung RE-260007_RC108175'],
    ];
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '08', 'RCUR', 'MSG-SAN1');
    assertContains('<EndToEndId>RE-260007-RC108175</EndToEndId>', $xml);
    assertContains('<Nm>Hoefferer</Nm>', $xml);
    assertFalse(str_contains($xml, '_'), 'Kein Unterstrich darf im XML vorkommen');
    $prev = libxml_use_internal_errors(true);
    $ok = simplexml_load_string($xml) !== false;
    libxml_use_internal_errors($prev);
    assertTrue($ok, 'XML sollte trotz Sonderzeichen in den Rohdaten parsebar sein');
});

test('pain.001: Unterstrich/Umlaute in Namen und EndToEndId werden bereinigt', function () {
    $payer = ['name' => 'EEG Test', 'iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW'];
    $txns = [
        ['end_to_end_id' => 'RE-260003_RC108175', 'amount' => 28.70, 'creditor_name' => 'Süd-West Lorenz',
         'creditor_iban' => 'AT023200000000005678', 'creditor_bic' => '', 'remittance' => 'Gutschrift RE-260003_RC108175'],
    ];
    $xml = sepaPain001Xml($payer, $txns, '2026-08-01', 'MSG-SAN2');
    assertContains('<EndToEndId>RE-260003-RC108175</EndToEndId>', $xml);
    assertContains('<Nm>Sued-West Lorenz</Nm>', $xml);
    assertFalse(str_contains($xml, '_'), 'Kein Unterstrich darf im XML vorkommen');
});

test('Regex-Check: alle String-Inhalte im pain.008-XML entsprechen dem SEPA-Zeichensatz', function () {
    $creditor = ['name' => 'EEG Süd-West', 'iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW', 'creditor_id' => 'AT12ZZZ00000000001'];
    $txns = [
        ['end_to_end_id' => 'RE-260007_RC108175', 'amount' => 10.00, 'mandate_ref' => 'S00000F2026A10003',
         'mandate_date' => '2026-01-01', 'debtor_name' => 'Höfferer & Müller', 'debtor_iban' => 'AT023200000000005678',
         'debtor_bic' => '', 'remittance' => 'Rechnung_RE-260007_RC108175'],
    ];
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '08', 'RCUR', 'MSG-SAN3');
    preg_match_all('/<(EndToEndId|MsgId|PmtInfId|MndtId|Nm|Ustrd)>([^<]*)<\//', $xml, $m);
    assertTrue(count($m[2]) > 0, 'Sollte mindestens ein zu prüfendes Feld gefunden haben');
    foreach ($m[2] as $value) {
        assertSame(1, preg_match('/^[a-zA-Z0-9\/\-?:().,\'+ ]*$/', $value), "Feldwert '$value' enthält unerlaubte SEPA-Zeichen");
    }
});
