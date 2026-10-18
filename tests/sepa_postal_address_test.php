<?php

declare(strict_types=1);

// sepaPostalAddressXml()/splitEegAddress() -- strukturierte/hybride SEPA-Postadresse, vorsorglich
// ergänzt für die (vom EPC im September 2026 verschobene, neuer Termin noch nicht bekannte)
// Pflicht ab einem Stichtag ursprünglich 15.11.2026: eine Adresse in pain.001/pain.008 muss dann
// strukturiert oder hybrid sein (mindestens TwnNm + Ctry), sonst weist die Bank die Datei zurück.

test('sepaPostalAddressXml: vollständige Adresse -> alle vier Felder in der XSD-Reihenfolge', function () {
    $xml = sepaPostalAddressXml('Hauptstraße 5', '9020', 'Klagenfurt');
    assertSame(
        '<PstlAdr><StrtNm>Hauptstrasse 5</StrtNm><PstCd>9020</PstCd><TwnNm>Klagenfurt</TwnNm><Ctry>AT</Ctry></PstlAdr>',
        $xml
    );
});

test('sepaPostalAddressXml: leere Stadt -> kein PstlAdr-Block (keine Adresse bekannt)', function () {
    assertSame('', sepaPostalAddressXml('Hauptstraße 5', '9020', ''));
    assertSame('', sepaPostalAddressXml('', '', ''));
});

test('sepaPostalAddressXml: fehlende Straße/PLZ -> nur die bekannten Felder, weiterhin gültige Reihenfolge', function () {
    assertSame('<PstlAdr><TwnNm>Klagenfurt</TwnNm><Ctry>AT</Ctry></PstlAdr>', sepaPostalAddressXml('', '', 'Klagenfurt'));
});

test('sepaPostalAddressXml: Umlaute/Unterstrich in der Straße werden wie überall sonst bereinigt', function () {
    $xml = sepaPostalAddressXml('Höfferer_Gasse 3', '9500', 'Villach');
    assertContains('<StrtNm>Hoefferer-Gasse 3</StrtNm>', $xml);
});

test('sepaPostalAddressXml: eigenes Land überschreibt den AT-Standard', function () {
    $xml = sepaPostalAddressXml('Musterstraße 1', '12345', 'München', 'de');
    assertContains('<Ctry>DE</Ctry>', $xml);
});

test('splitEegAddress: Normalfall "Straße, PLZ Ort"', function () {
    $a = splitEegAddress('Hauptstraße 5, 9020 Klagenfurt');
    assertSame('Hauptstraße 5', $a['street']);
    assertSame('9020', $a['zip']);
    assertSame('Klagenfurt', $a['city']);
});

test('splitEegAddress: leere/fehlende Adresse -> alle Felder leer', function () {
    $a = splitEegAddress(null);
    assertSame('', $a['street']);
    assertSame('', $a['zip']);
    assertSame('', $a['city']);
});

test('splitEegAddress: unerwartetes Format ohne erkennbare PLZ -> Rest komplett als Ort', function () {
    $a = splitEegAddress('Hauptstraße 5, Klagenfurt');
    assertSame('Hauptstraße 5', $a['street']);
    assertSame('', $a['zip']);
    assertSame('Klagenfurt', $a['city']);
});

// Ende-zu-Ende: PstlAdr erscheint tatsächlich im erzeugten pain.008-/pain.001-XML.

test('pain.008: PstlAdr erscheint bei Dbtr, Cdtr UND InitgPty, wenn Adressdaten vorhanden sind', function () {
    $creditor = [
        'name' => 'EEG Test', 'iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW',
        'creditor_id' => 'AT12ZZZ00000000001', 'street' => 'Marktplatz 1', 'zip' => '9500', 'city' => 'Villach',
    ];
    $txns = [[
        'end_to_end_id' => 'RE-1', 'amount' => 10.00, 'mandate_ref' => 'M-1', 'mandate_date' => '2026-01-01',
        'debtor_name' => 'Max Muster', 'debtor_iban' => 'AT023200000000005678', 'debtor_bic' => '',
        'remittance' => 'Rechnung RE-1', 'debtor_street' => 'Dorfstraße 2', 'debtor_zip' => '9020', 'debtor_city' => 'Klagenfurt',
    ]];
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '08', 'RCUR', 'MSG-ADR1');
    assertSame(2, substr_count($xml, '<TwnNm>Villach</TwnNm>')); // InitgPty + Cdtr
    assertContains('<TwnNm>Klagenfurt</TwnNm>', $xml); // Dbtr
    assertContains('<Dbtr><Nm>Max Muster</Nm><PstlAdr>', $xml); // PstlAdr direkt nach Nm, innerhalb Dbtr
});

test('pain.008: ohne Adressdaten bleibt PstlAdr komplett weg (bestehende Aufrufer ohne street/zip/city)', function () {
    $creditor = ['name' => 'EEG Test', 'iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW', 'creditor_id' => 'AT12ZZZ00000000001'];
    $txns = [[
        'end_to_end_id' => 'RE-1', 'amount' => 10.00, 'mandate_ref' => 'M-1', 'mandate_date' => '2026-01-01',
        'debtor_name' => 'Max Muster', 'debtor_iban' => 'AT023200000000005678', 'debtor_bic' => '', 'remittance' => 'Rechnung RE-1',
    ]];
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '08', 'RCUR', 'MSG-ADR2');
    assertFalse(str_contains($xml, 'PstlAdr'));
});

test('pain.001: PstlAdr erscheint bei Dbtr, Cdtr UND InitgPty, wenn Adressdaten vorhanden sind', function () {
    $payer = ['name' => 'EEG Test', 'iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW', 'street' => 'Marktplatz 1', 'zip' => '9500', 'city' => 'Villach'];
    $txns = [[
        'end_to_end_id' => 'RE-2', 'amount' => 20.00, 'creditor_name' => 'Erika Muster',
        'creditor_iban' => 'AT023200000000005678', 'creditor_bic' => '', 'remittance' => 'Gutschrift RE-2',
        'creditor_street' => 'Dorfstraße 2', 'creditor_zip' => '9020', 'creditor_city' => 'Klagenfurt',
    ]];
    $xml = sepaPain001Xml($payer, $txns, '2026-08-01', 'MSG-ADR3');
    assertSame(2, substr_count($xml, '<TwnNm>Villach</TwnNm>')); // InitgPty + Dbtr
    assertContains('<Cdtr><Nm>Erika Muster</Nm><PstlAdr>', $xml);
    assertContains('<TwnNm>Klagenfurt</TwnNm>', $xml);
});

test('Regex-Check: die neuen PstlAdr-Feldwerte entsprechen weiterhin dem SEPA-Zeichensatz', function () {
    $creditor = ['name' => 'EEG Test', 'iban' => 'AT611904300234573201', 'bic' => 'BKAUATWW', 'creditor_id' => 'AT12ZZZ00000000001',
                 'street' => 'Höfferer-Straße 5/3', 'zip' => '9020', 'city' => 'Klagenfurt am Wörthersee'];
    $txns = [[
        'end_to_end_id' => 'RE-3', 'amount' => 10.00, 'mandate_ref' => 'M-3', 'mandate_date' => '2026-01-01',
        'debtor_name' => 'Max Muster', 'debtor_iban' => 'AT023200000000005678', 'debtor_bic' => '', 'remittance' => 'Rechnung RE-3',
    ]];
    $xml = sepaPain008Xml($creditor, $txns, '2026-08-01', '08', 'RCUR', 'MSG-ADR4');
    preg_match_all('/<(StrtNm|PstCd|TwnNm|Ctry)>([^<]*)<\//', $xml, $m);
    assertTrue(count($m[2]) > 0, 'Sollte mindestens ein PstlAdr-Feld gefunden haben');
    foreach ($m[2] as $value) {
        assertSame(1, preg_match('/^[a-zA-Z0-9\/\-?:().,\'+ ]*$/', $value), "Feldwert '$value' enthält unerlaubte SEPA-Zeichen");
    }
});
