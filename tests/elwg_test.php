<?php

declare(strict_types=1);

// ElWG-Umstellung (Version 0.10.0): Vertragsvorlage je Vertrag, Rechnungskasten nach § 69 Abs 3,
// Netzgebühr-Text, Rechtstexte.

require_once __DIR__ . '/../webapp/src/content/marketing_pages.php';

test('Vertragsvorlage: unterschrieben ohne festgehaltene Fassung = Altfassung ElWOG 2010', function () {
    assertSame('bezugsvereinbarung_elwog2010', contractTemplateName('bezug', ['contract_bezug_status' => 'signed']));
    assertSame('einspeisevereinbarung_elwog2010', contractTemplateName('einspeisung', ['contract_einspeisung_status' => 'signed', 'contract_einspeisung_template' => null]));
});

test('Vertragsvorlage: unterschrieben mit festgehaltener Fassung und noch nicht unterschrieben = aktuelle Vorlage', function () {
    assertSame('bezugsvereinbarung', contractTemplateName('bezug', ['contract_bezug_status' => 'signed', 'contract_bezug_template' => CONTRACT_TEMPLATE_VERSION]));
    foreach (['none', 'created'] as $status) {
        assertSame('bezugsvereinbarung', contractTemplateName('bezug', ['contract_bezug_status' => $status]));
        assertSame('einspeisevereinbarung', contractTemplateName('einspeisung', ['contract_einspeisung_status' => $status]));
    }
});

test('Vertragsvorlagen: eingefrorene Altfassungen existieren und nennen noch das ElWOG 2010', function () {
    foreach (['bezugsvereinbarung', 'einspeisevereinbarung'] as $n) {
        $alt = file_get_contents(__DIR__ . "/../latex-service/templates/{$n}_elwog2010.tex");
        $neu = file_get_contents(__DIR__ . "/../latex-service/templates/{$n}.tex");
        assertTrue(str_contains($alt, 'ElWOG 2010'), "$n: Altfassung muss ElWOG 2010 nennen");
        assertFalse(str_contains($neu, 'ElWOG'), "$n: neue Fassung darf kein ElWOG mehr nennen");
        assertTrue(str_contains($neu, 'ElWG'), "$n: neue Fassung muss das ElWG nennen");
    }
});

test('Rechnung § 69 Abs 3: Kasten mit Zählpunkten, Energiewerten und Vertragspartner, LaTeX-Zeichen escaped', function () {
    $tex = invoiceSupplierBlockLatex(
        ['eeg_name' => 'Muster & Co EEG', 'eeg_address' => 'Weg 1, 9020 Ort', 'eeg_contact_email' => 'info@example.invalid', 'eeg_contact_phone' => ''],
        [['zaehlpunkt_nr' => 'AT0030000000000000000000000000001', 'kwh' => 250.5]],
        [['zaehlpunkt_nr' => 'AT0030000000000000000000000000002', 'kwh' => 85]]
    );
    assertContains('Verwendete Energiewerte', $tex);
    assertContains('AT0030000000000000000000000000001 & Bezug & 250,50', $tex);
    assertContains('AT0030000000000000000000000000002 & Einspeisung & 85,00', $tex);
    assertContains('Muster \\& Co EEG', $tex);
    assertContains('info@example.invalid', $tex);
    assertFalse(str_contains($tex, 'Telefon'), 'ohne Telefon keine Telefonangabe');
});

test('Rechnungsvorlage: Platzhalter für den Kasten ist vorhanden', function () {
    assertTrue(str_contains(file_get_contents(__DIR__ . '/../latex-service/templates/rechnung.tex'), '<<<RAW_LIEFERANTENBLOCK>>>'));
});

test('Netzgebühr-Hinweis: ohne gepflegten Wert keine Zahl und keine Fußnote', function () {
    $h = marketingNetzHinweis();
    assertFalse(str_contains($h['satz'], '%'));
    assertSame('', $h['fussnote']);
});

test('Rechtstexte: nicht mehr "gemeinnützig", AGB mit Verbraucherklausel, Statuten-Hinweis, Impressum-Verweis', function () {
    foreach (['webapp/src/content/marketing_pages.php', 'webapp/public/llms.txt'] as $f) {
        assertFalse(stripos((string)file_get_contents(__DIR__ . '/../' . $f), 'gemeinnützig') !== false, "$f: gemeinnützig");
    }
    $dir = __DIR__ . '/../webapp/src/views/legal_defaults/rc108175/';
    assertContains('§ 6 Abs 1 Z 9 KSchG', (string)file_get_contents($dir . 'agb.php'));
    assertContains('§ 189 Abs 17 ElWG', (string)file_get_contents($dir . 'statuten.php'));
    assertContains('§ 79 EAG i. V. m. §§ 66 ff ElWG', (string)file_get_contents($dir . 'impressum.php'));
});
