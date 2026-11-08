<?php

declare(strict_types=1);

// ─── Zustimmungstexte der Beitrittserklärung (webapp/src/consent_texts.php) ─────────────────
// Einzige Quelle für Online-Formular, Online-PDF, Offline-Formular und Volltext-Archiv.

$ctCommunity = [
    'name'            => 'EEG Strompool Feldkirchen Süd-West',
    'marktpartner_id' => 'RC108175',
    'zvr_number'      => '1778816746',
    'address'         => 'Eichenweg 2, 9560 St. Nikolai',
    'creditor_id'     => 'AT14EEG00000086499',
];

test('Zustimmungen: jede Fassung hat alle sechs Punkte mit Kurz- und Langfassung', function () {
    foreach (consentVersions() as $version => $v) {
        assertTrue(isset($v['gueltig_ab'], $v['bezeichnung'], $v['vollmacht_p1']), "Metadaten fehlen in $version");
        foreach (array_keys(consentFields()) as $field) {
            assertTrue(trim($v['texte'][$field]['kurz'] ?? '') !== '', "$version/$field kurz fehlt");
            assertTrue(trim($v['texte'][$field]['lang'] ?? '') !== '', "$version/$field lang fehlt");
        }
        assertSame(count(consentFields()), count($v['texte']), "$version hat zusätzliche/fehlende Punkte");
    }
});

test('Zustimmungen: aktuelle Fassung ist v2, Bestandsfassungen v1-online/v1-offline existieren', function () {
    assertSame('v2', consentCurrentVersion());
    assertTrue(consentVersion('v1-online') !== null);
    assertTrue(consentVersion('v1-offline') !== null);
    assertSame(null, consentVersion('gibt-es-nicht'));
    assertSame(null, consentVersion(null));
});

test('Zustimmungen v2: Vollmacht enthält den P1-Passus (Kundenschnittstelle + Schlüssel)', function () use ($ctCommunity) {
    $lang = consentText('v2', 'zustimmung_vollmacht', 'lang', $ctCommunity);
    assertContains('Kundenschnittstelle (P1)', $lang);
    assertContains('zählerspezifischen Schlüssels', $lang);
    assertContains('§ 7 der AGB', $lang);
    assertContains('Kärnten Netz GmbH', $lang);
    assertContains('P1-Kundenschnittstelle', consentText('v2', 'zustimmung_vollmacht', 'kurz', $ctCommunity));
    assertTrue(consentCoversP1('v2'));
    assertFalse(consentCoversP1('v1-online'));
    assertFalse(consentCoversP1('v1-offline'));
    assertFalse(consentCoversP1(null));
});

test('Zustimmungen v2: Datenschutz ist eine Kenntnisnahme, keine Einwilligung', function () use ($ctCommunity) {
    $lang = consentText('v2', 'zustimmung_datenschutz', 'lang', $ctCommunity);
    assertContains('Ich nehme zur Kenntnis', $lang);
    assertFalse(str_contains($lang, 'willige'), 'v2 darf keine Einwilligung mehr formulieren');
    // Die alten Fassungen bleiben dagegen unverändert (Nachweis, was unterschrieben wurde).
    assertContains('Ich willige', consentText('v1-online', 'zustimmung_datenschutz', 'lang', $ctCommunity));
});

test('Zustimmungen: Platzhalter werden durch RC-Nummer und Vereinsnamen ersetzt, keiner bleibt übrig', function () use ($ctCommunity) {
    assertContains('stromfueralle.at/rc108175/statuten', consentText('v2', 'zustimmung_mitgliedschaft', 'lang', $ctCommunity));
    assertContains('„EEG Strompool Feldkirchen Süd-West“', consentText('v2', 'zustimmung_mitgliedschaft', 'lang', $ctCommunity));
    foreach (consentVersions() as $version => $v) {
        foreach (array_keys(consentFields()) as $field) {
            foreach (['kurz', 'lang'] as $variant) {
                $t = consentText($version, $field, $variant, $ctCommunity);
                assertFalse(str_contains($t, '{'), "Platzhalter übrig in $version/$field/$variant: $t");
            }
        }
    }
    // Andere EEG -> andere RC-Nummer, nichts fest codiert.
    $other = consentText('v2', 'zustimmung_agb', 'lang', ['marktpartner_id' => 'RC999999', 'name' => 'EEG Test']);
    assertContains('stromfueralle.at/rc999999/agb', $other);
    assertFalse(str_contains($other, 'rc108175'));
});

test('Zustimmungen: unbekannte Version/Feld wirft eine Exception statt leer zu bleiben', function () use ($ctCommunity) {
    $thrown = false;
    try { consentText('v99', 'zustimmung_agb', 'lang', $ctCommunity); } catch (InvalidArgumentException $e) { $thrown = true; }
    assertTrue($thrown);
});

test('consentHtml: escapt HTML, Absätze als <br>, Verweise als Link', function () {
    $html = consentHtml("A <b>&</b>\nsiehe stromfueralle.at/rc108175/agb.");
    assertContains('&lt;b&gt;&amp;&lt;/b&gt;', $html);
    assertContains('<br>', $html);
    assertContains('<a href="/rc108175/agb" target="_blank" rel="noopener">stromfueralle.at/rc108175/agb</a>.', $html);
});

test('consentLatex: escapt Sonderzeichen, Verweise umbrechbar in \\texttt, Absätze als \\newline', function () {
    $tex = consentLatex("AGB & Preis 100%\n(stromfueralle.at/rc108175/agb)");
    assertContains('AGB \\& Preis 100\\%', $tex);
    assertContains('\\newline ', $tex);
    assertContains('\\texttt{stromfueralle.at/\\allowbreak{}rc108175/\\allowbreak{}agb}', $tex);
});

test('SEPA-Mandat: enthält Berechtigungserklärung, Sitz aus der Adresse (St. Nikolai), Creditor-ID', function () use ($ctCommunity) {
    $t = sepaMandateText($ctCommunity);
    assertContains('Ich erkläre ausdrücklich, hinsichtlich des angegebenen Kontos berechtigt zu sein, ein SEPA-Lastschrift-Mandat zu erteilen.', $t);
    assertContains('Sitz St. Nikolai', $t);
    assertFalse(str_contains($t, 'Feldkirchen in Kärnten'));
    assertContains('Creditor-ID: AT14EEG00000086499', $t);
    assertFalse(str_contains(sepaMandateText($ctCommunity, false), 'Ich erkläre ausdrücklich'),
        'Nachdruck alter v1-online-Anträge ohne den damals nicht gezeigten Satz');
    assertContains('Creditor-ID: nicht hinterlegt', sepaMandateText(['name' => 'X', 'address' => 'A 1, 1234 Ort']));
});

test('Online-PDF: Kurzfassung der akzeptierten Version + Versionshinweis mit Links', function () use ($ctCommunity) {
    $a = ['consent_version' => 'v2'] + array_fill_keys(array_keys(consentFields()), 't');
    $blk = onlineBeitrittConsentLatex($a, $ctCommunity);
    assertSame('v2', $blk['version']);
    assertSame(6, substr_count($blk['liste'], '\\item[\\cbon]'));
    assertContains('P1-Kundenschnittstelle samt Schlüssel', $blk['liste']);
    assertContains('Fassung vom 07.11.2026 (Version v2)', $blk['hinweis']);
    foreach (['zustimmungen', 'statuten', 'agb', 'preisliste', 'datenschutz'] as $doc) {
        assertContains('rc108175/\\allowbreak{}' . $doc, $blk['hinweis']);
    }
});

test('Online-PDF: alter Antrag ohne Version -> v1-online (damals gezeigter Text)', function () use ($ctCommunity) {
    $a = array_fill_keys(array_keys(consentFields()), true);
    $blk = onlineBeitrittConsentLatex($a, $ctCommunity);
    assertSame('v1-online', $blk['version']);
    assertContains('Ich bevollmächtige den Vorstand zur Zustimmungserklärung', $blk['liste']);
    assertFalse(str_contains($blk['liste'], 'P1'));
    assertContains('Fassung vom 14.07.2026 (Version v1-online)', $blk['hinweis']);
    $unchecked = onlineBeitrittConsentLatex(['consent_version' => 'v2'], $ctCommunity);
    assertSame(6, substr_count($unchecked['liste'], '\\item[\\cb]'));
});

test('Offline-Formular: Langfassung v2, SEPA-Text und Stammdaten aus der EEG', function () use ($ctCommunity) {
    $vars = offlineBeitrittserklaerungVars($ctCommunity);
    assertSame('108175', $vars['EEG_RC']);
    assertSame('Eichenweg 2, 9560 St. Nikolai', $vars['EEG_ADRESSE']);
    assertSame('office@stromfueralle.at', $vars['KONTAKT_EMAIL'], 'Fallback ohne contact_email');
    assertSame(6, substr_count($vars['RAW_ZUSTIMMUNGEN_LISTE'], '\\item '));
    assertContains('(c) die Beantragung der Freischaltung der Kundenschnittstelle (P1)', $vars['RAW_ZUSTIMMUNGEN_LISTE']);
    assertContains('Ich erkläre ausdrücklich', $vars['RAW_SEPA_TEXT']);
    assertContains('Version v2', $vars['RAW_ZUSTIMMUNGEN_HINWEIS']);
    $withContact = offlineBeitrittserklaerungVars($ctCommunity + ['contact_email' => 'info@example.org', 'contact_phone' => '+43 1 234']);
    assertContains('info@example.org', $withContact['RAW_KONTAKT']);
    assertContains('Tel.\\ +43 1 234', $withContact['RAW_KONTAKT']);
});

// Schutz vor einer erneuten Text-Kopie: der Wortlaut darf nur in consent_texts.php stehen. Taucht
// er wieder in einer Vorlage/View auf, laufen die Fassungen früher oder später auseinander --
// genau das Problem, das mit der zentralen Quelle behoben wurde (Patrick, 07.11.2026).
test('Zustimmungstexte stehen in keiner Vorlage/View fest codiert (nur in consent_texts.php)', function () {
    $root = __DIR__ . '/..';
    $files = [
        'webapp/public/index.php',
        'webapp/src/views/pages/beitreten_formular.php',
        'webapp/src/views/pages/member_form.php',
        'latex-service/templates/beitrittserklaerung_formular.tex',
        'latex-service/templates/beitrittserklaerung_leer.tex',
    ];
    foreach ($files as $f) {
        $content = file_get_contents($root . '/' . $f);
        foreach (['Ich bevollmächtige', 'Ich beantrage die Mitgliedschaft', 'Das Mitglied bevollmächtigt', 'Hiermit ermächtige ich'] as $phrase) {
            assertFalse(str_contains($content, $phrase), "„{$phrase}“ fest codiert in {$f}");
        }
    }
    assertContains('<<<RAW_ZUSTIMMUNGEN_LISTE>>>', file_get_contents($root . '/latex-service/templates/beitrittserklaerung_leer.tex'));
    assertContains('<<<RAW_ZUSTIMMUNGEN_HINWEIS>>>', file_get_contents($root . '/latex-service/templates/beitrittserklaerung_formular.tex'));
});

// ─── EEG ohne Ausleseeinheit / EEG-eigene Fassungen (08.11.2026) ────────────────────────────

test('v2-ohne-ae: kein P1-/Ausleseeinheit-Passus, sonst vollständig', function () use ($ctCommunity) {
    foreach (array_keys(consentFields()) as $field) {
        foreach (['kurz', 'lang'] as $variant) {
            $t = consentText('v2-ohne-ae', $field, $variant, $ctCommunity);
            assertFalse(str_contains($t, 'P1'), "P1 in $field/$variant");
            assertFalse(str_contains($t, 'Ausleseeinheit'), "Ausleseeinheit in $field/$variant");
        }
    }
    assertContains('(b) die Zustimmung zur Übermittlung der Viertelstundenwerte', consentText('v2-ohne-ae', 'zustimmung_vollmacht', 'lang', $ctCommunity));
    assertFalse(consentCoversP1('v2-ohne-ae'));
});

test('communityUsesAusleseeinheit: Standard ja, DB-Werte f/false/0 = nein', function () {
    assertTrue(communityUsesAusleseeinheit([]));
    assertTrue(communityUsesAusleseeinheit(['uses_ausleseeinheit' => 't']));
    assertFalse(communityUsesAusleseeinheit(['uses_ausleseeinheit' => 'f']));
    assertFalse(communityUsesAusleseeinheit(['uses_ausleseeinheit' => false]));
});

test('consentCurrentVersion: EEG-Fassung vor Plattform-Standard, ohne Ausleseeinheit -> v2-ohne-ae', function () {
    consentClearRegisteredVersions();
    try {
        $a = ['id' => 'aaa', 'marktpartner_id' => 'RC1', 'name' => 'A'];
        $b = ['id' => 'bbb', 'marktpartner_id' => 'RC2', 'name' => 'B', 'uses_ausleseeinheit' => 'f'];
        assertSame('v2', consentCurrentVersion($a));
        assertSame('v2-ohne-ae', consentCurrentVersion($b));
        assertSame('v2', consentCurrentVersion());

        $row = ['community_id' => 'aaa', 'gueltig_ab' => '2026-11-08', 'texts' => json_encode([
            'zustimmung_vollmacht' => ['kurz' => 'Eigene Vollmacht.', 'lang' => 'Eigene Vollmacht inkl. P1.'],
        ])];
        consentRegisterVersions(['rc1-1' => consentVersionFromRow($row)]);
        assertSame('rc1-1', consentCurrentVersion($a));
        assertSame('v2-ohne-ae', consentCurrentVersion($b), 'andere EEG bleibt beim Standard');
        assertSame('Eigene Vollmacht.', consentText('rc1-1', 'zustimmung_vollmacht', 'kurz', $a));
        assertTrue(consentCoversP1('rc1-1'));
        // Nicht ausgefüllte Punkte werden aus v2 ergänzt, nie leer.
        assertSame(consentText('v2', 'zustimmung_agb', 'lang', $a), consentText('rc1-1', 'zustimmung_agb', 'lang', $a));
        assertSame('Fassung der Energiegemeinschaft vom 08.11.2026', consentVersion('rc1-1')['bezeichnung']);

        $newer = ['community_id' => 'aaa', 'gueltig_ab' => '2026-11-09', 'texts' => '{}'];
        consentRegisterVersions(['rc1-2' => consentVersionFromRow($newer)]);
        assertSame('rc1-2', consentCurrentVersion($a), 'jüngste EEG-Fassung gilt');
    } finally {
        consentClearRegisteredVersions();
    }
});
