<?php

declare(strict_types=1);

/**
 * EINZIGE Quelle für die sechs rechtlichen Zustimmungen der Beitrittserklärung (Patrick,
 * 07.11.2026: Offline-Formular, Online-Formular und Online-PDF hatten drei unterschiedliche
 * Textfassungen). Alles, was diese Texte anzeigt, liest sie von hier:
 *   - Online-Formular (beitreten_formular.php): Titel + Langfassung, vor dem Anhaken lesbar
 *   - Online-PDF (/portal/applications/:id/formular): Kurzfassung + Versionshinweis + Links
 *   - Offline-Formular (latex-service beitrittserklaerung_leer.tex, /:rc/beitrittserklaerung.pdf):
 *     Langfassung, weil es auf Papier kein Portal zum Nachlesen gibt
 *   - Volltext-Archiv aller Fassungen (/:rc/zustimmungen)
 *   - Mitglied manuell anlegen (member_form.php) und App-API
 *
 * Bewusst versionierte Konfigurationsdatei statt DB-Tabelle: Rechtstexte ändern sich nur mit
 * einem Release, Git + Tag dokumentieren lückenlos, wer wann was geändert hat, und es gibt
 * keinen Weg, eine bereits unterschriebene Fassung nachträglich im Admin-Bereich zu
 * überschreiben. Jede Beitrittserklärung speichert die Version (consent_version), die sie
 * akzeptiert hat -- deshalb eine bestehende Version NIE inhaltlich ändern, sondern immer eine
 * neue Version anlegen und CONSENT_CURRENT_VERSION umstellen.
 *
 * Platzhalter (consentRender()): {rc} = Marktpartner-ID klein (z.B. rc108175),
 * {eeg_name} = Vereinsname aus den EEG-Stammdaten. Zeilenumbrüche (\n) trennen Absätze bzw.
 * Aufzählungspunkte -- HTML macht daraus <br>, LaTeX \newline.
 */

const CONSENT_CURRENT_VERSION = 'v2';

/** Reihenfolge und Titel der sechs Zustimmungen (= DB-Spalten membership_applications/members). */
function consentFields(): array
{
    return [
        'zustimmung_mitgliedschaft'      => 'Vereins- und EEG-Mitgliedschaft',
        'zustimmung_vollmacht'           => 'Vollmacht',
        'zustimmung_widerrufsfrist'      => 'Beginn vor Ablauf der Rücktrittsfrist',
        'zustimmung_email_kommunikation' => 'E-Mail-Rechnung / -Korrespondenz',
        'zustimmung_datenschutz'         => 'Datenschutz',
        'zustimmung_agb'                 => 'AGB & Tarif-/Preisblatt',
    ];
}

/**
 * Alle jemals gültigen Fassungen, neueste zuerst. Ältere Fassungen bleiben für den Nachweis
 * stehen, welchen Text ein Bestandsmitglied unterschrieben hat (siehe migrate_20261107.sql).
 */
function consentVersions(): array
{
    $v1OnlineKurz = [
        'zustimmung_mitgliedschaft'      => 'Ich beantrage die Mitgliedschaft im Verein und nehme die Vereinsstatuten zur Kenntnis.',
        'zustimmung_vollmacht'           => 'Ich bevollmächtige den Vorstand zur Zustimmungserklärung und Übermittlung der Viertelstundenwerte gegenüber dem Netzbetreiber.',
        'zustimmung_widerrufsfrist'      => 'Ich stimme zu, dass die Stromzuteilung bereits vor Ablauf der 14-tägigen Widerrufsfrist beginnt.',
        'zustimmung_email_kommunikation' => 'Ich stimme der Zustellung von Rechnungen und vereinsrelevanten Dokumenten per E-Mail zu.',
        'zustimmung_datenschutz'         => 'Ich willige in die Verarbeitung meiner Stamm-, Erzeugungs- und Verbrauchsdaten gemäß Datenschutzerklärung ein.',
        'zustimmung_agb'                 => 'Ich bestätige, die geltenden Konditionen laut Preisliste und AGB gelesen und akzeptiert zu haben.',
    ];

    return [
        'v2' => [
            'bezeichnung' => 'Fassung 2 (Vollmacht inkl. P1-Kundenschnittstelle)',
            'gueltig_ab'  => '2026-11-07',
            'vollmacht_p1' => true,
            'texte' => [
                'zustimmung_mitgliedschaft' => [
                    'kurz' => 'Ich beantrage die Mitgliedschaft im Verein und nehme die Vereinsstatuten zur Kenntnis.',
                    'lang' => 'Ich beantrage die Mitgliedschaft im Verein „{eeg_name}“ und werde damit gleichzeitig Teilnehmer:in der Erneuerbare-Energie-Gemeinschaft (EEG). Ich nehme die Vereinsstatuten (stromfueralle.at/{rc}/statuten) zur Kenntnis und bestätige, dass die oben angeführten Daten korrekt sind.',
                ],
                'zustimmung_vollmacht' => [
                    'kurz' => 'Ich bevollmächtige den Vorstand, mich gegenüber Netzbetreiber, Behörden und Energieversorgern in allen Belangen der EEG zu vertreten, inkl. Zustimmungserklärung, Viertelstundenwerten und Freischaltung der P1-Kundenschnittstelle samt Schlüssel.',
                    'lang' => "Ich bevollmächtige den Vorstand der EEG, mich in allen zur Teilnahme, Anmeldung und Abwicklung der Energiegemeinschaft erforderlichen Belangen gegenüber Behörden, dem Netzbetreiber (Kärnten Netz GmbH) und Energieversorgern zu vertreten. Dies umfasst insbesondere\n"
                        . "(a) die Abgabe der Zustimmungserklärung zur Teilnahme,\n"
                        . "(b) die Zustimmung zur Übermittlung der Viertelstundenwerte über das Netzkundenportal bzw. den EDA-Marktprozess sowie\n"
                        . "(c) die Beantragung der Freischaltung der Kundenschnittstelle (P1) meines Smart Meters und die Entgegennahme des zählerspezifischen Schlüssels zur Entschlüsselung der Daten. Der Schlüssel wird ausschließlich für den Betrieb der Ausleseeinheit gemäß § 7 der AGB verwendet und vertraulich behandelt.\n"
                        . 'Die Vollmacht ist jederzeit schriftlich (per E-Mail) widerrufbar.',
                ],
                'zustimmung_widerrufsfrist' => [
                    'kurz' => 'Ich stimme dem Beginn der Stromzuteilung vor Ablauf der 14-tägigen Widerrufsfrist zu.',
                    'lang' => 'Ich stimme zu, dass die Stromzuteilung bereits vor Ablauf der 14-tägigen Widerrufsfrist beginnt. Ich kann diese Beitrittserklärung binnen 14 Tagen schriftlich (per E-Mail) widerrufen (stromfueralle.at/{rc}/widerruf).',
                ],
                'zustimmung_email_kommunikation' => [
                    'kurz' => 'Ich stimme der Zustellung von Rechnungen und Dokumenten per E-Mail zu.',
                    'lang' => 'Ich stimme der Zustellung von Rechnungen sowie beitritts- und vereinsrelevanten Dokumenten per E-Mail an die oben angeführte Adresse zu.',
                ],
                // Bewusst keine Einwilligung mehr ("Ich nehme zur Kenntnis"): Rechtsgrundlage ist
                // Art. 6 Abs. 1 lit. b/c/f DSGVO, keine Einwilligung nach lit. a -- eine
                // "Einwilligung" wäre jederzeit widerrufbar und würde die Abrechnung blockieren.
                'zustimmung_datenschutz' => [
                    'kurz' => 'Ich nehme die Datenschutzerklärung zur Kenntnis.',
                    'lang' => 'Ich nehme zur Kenntnis, dass die EEG meine Stamm-, Erzeugungs- und Verbrauchsdaten zur Abwicklung der Mitgliedschaft verarbeitet, speichert und im erforderlichen Umfang mit dem Netzbetreiber und beauftragten Dienstleistern (Auftragsverarbeiter iSd DSGVO) austauscht. Insbesondere verwendet die vom Verein beauftragte Abrechnungsplattform (stromfueralle.at) meine Daten zur Verbrauchs- und Erzeugungsabrechnung, zur Live-Visualisierung in meinem persönlichen Online-Zugang sowie für den Betrieb der Ausleseeinheit. Details siehe Datenschutzerklärung (stromfueralle.at/{rc}/datenschutz).',
                ],
                'zustimmung_agb' => [
                    'kurz' => 'Ich habe Preisliste und AGB (inkl. verpflichtender Ausleseeinheit) gelesen und akzeptiert.',
                    'lang' => 'Ich bestätige, dass ich die geltenden Konditionen laut Tarif- und Preisblatt (stromfueralle.at/{rc}/preisliste) sowie die AGB (stromfueralle.at/{rc}/agb) gelesen, verstanden und akzeptiert habe. Mir ist bekannt, dass der Betrieb der Ausleseeinheit für Smart Meter gemäß § 7 der AGB verpflichtender Bestandteil der Teilnahme ist.',
                ],
            ],
        ],

        // Bis 06.11.2026 im Online-Formular angezeigte Fassung -- online wurde nur dieser
        // (kurze) Wortlaut angezeigt und angehakt, deshalb ist er hier Kurz- UND Langfassung.
        'v1-online' => [
            'bezeichnung' => 'Fassung 1 (Online-Beitrittsformular)',
            'gueltig_ab'  => '2026-07-14',
            'vollmacht_p1' => false,
            'texte' => array_map(fn($t) => ['kurz' => $t, 'lang' => $t], $v1OnlineKurz),
        ],

        // Bis 06.11.2026 auf dem Papier-Beitrittsformular abgedruckte Fassung (damals die
        // statische docs/vorlagen/Beitrittserklaerung.tex, siehe Git-Historie) -- Wortlaut
        // unverändert übernommen, inkl. des damaligen Links /datenschutz ohne RC-Nummer.
        'v1-offline' => [
            'bezeichnung' => 'Fassung 1 (Papier-Beitrittsformular)',
            'gueltig_ab'  => '2026-07-01',
            'vollmacht_p1' => false,
            'texte' => [
                'zustimmung_mitgliedschaft' => [
                    'kurz' => $v1OnlineKurz['zustimmung_mitgliedschaft'],
                    'lang' => 'Ich beantrage die Mitgliedschaft im Verein „{eeg_name}“ und werde damit gleichzeitig Teilnehmer:in der Erneuerbare-Energie-Gemeinschaft (EEG). Ich nehme die Vereinsstatuten (stromfueralle.at/{rc}/statuten) zur Kenntnis und bestätige, dass die oben angeführten Daten korrekt sind.',
                ],
                'zustimmung_vollmacht' => [
                    'kurz' => $v1OnlineKurz['zustimmung_vollmacht'],
                    'lang' => 'Ich bevollmächtige den Vorstand der EEG, mich in allen zur Teilnahme, Anmeldung und Abwicklung der Energiegemeinschaft erforderlichen Belangen gegenüber Behörden, dem Netzbetreiber (Kärnten Netz GmbH) und Energieversorgern zu vertreten. Dies umfasst insbesondere die Abgabe der Zustimmungserklärung zur Teilnahme sowie zur Übermittlung der Viertelstundenwerte gegenüber dem Netzbetreiber über das Netzkundenportal bzw. den EDA-Marktprozess. Die Vollmacht ist jederzeit schriftlich (per E-Mail) widerrufbar.',
                ],
                'zustimmung_widerrufsfrist' => [
                    'kurz' => $v1OnlineKurz['zustimmung_widerrufsfrist'],
                    'lang' => 'Ich stimme zu, dass die Stromzuteilung bereits vor Ablauf der 14-tägigen Widerrufsfrist beginnt. Ich kann diese Beitrittserklärung binnen 14 Tagen schriftlich (per E-Mail) widerrufen.',
                ],
                'zustimmung_email_kommunikation' => [
                    'kurz' => $v1OnlineKurz['zustimmung_email_kommunikation'],
                    'lang' => 'Ich stimme der Zustellung von Rechnungen sowie beitritts- und vereinsrelevanten Dokumenten per E-Mail an die oben angeführte Adresse zu.',
                ],
                'zustimmung_datenschutz' => [
                    'kurz' => $v1OnlineKurz['zustimmung_datenschutz'],
                    'lang' => 'Ich willige in die Verarbeitung, Speicherung und den erforderlichen Austausch meiner Daten (Stamm-, Erzeugungs- und Verbrauchsdaten) zwischen der EEG, dem Netzbetreiber und beauftragten Dienstleistern (Auftragsverarbeiter iSd DSGVO) zur Abwicklung der Mitgliedschaft ein. Insbesondere darf die vom Verein beauftragte Abrechnungsplattform (stromfueralle.at) meine Daten zur Verbrauchs- und Erzeugungsabrechnung sowie zur Live-Visualisierung in meinem persönlichen Online-Zugang verwenden. Die Daten werden ausschließlich zu diesen Zwecken verwendet. Details siehe Datenschutzerklärung (stromfueralle.at/datenschutz).',
                ],
                'zustimmung_agb' => [
                    'kurz' => $v1OnlineKurz['zustimmung_agb'],
                    'lang' => 'Ich bestätige, dass ich die geltenden Konditionen laut Tarif- und Preisblatt (stromfueralle.at/{rc}/preisliste) sowie die AGB (stromfueralle.at/{rc}/agb) gelesen, verstanden und akzeptiert habe.',
                ],
            ],
        ],
    ];
}

function consentCurrentVersion(): string
{
    return CONSENT_CURRENT_VERSION;
}

/** Fassung zu einer Versionskennung; unbekannte/leere Kennung -> null. */
function consentVersion(?string $version): ?array
{
    return consentVersions()[(string)$version] ?? null;
}

/**
 * Ersetzt die Platzhalter durch die Stammdaten der jeweiligen EEG. $community braucht
 * mindestens 'marktpartner_id' und 'name' (Zeile aus communities).
 */
function consentRender(string $text, array $community): string
{
    return strtr($text, [
        '{rc}'       => strtolower((string)($community['marktpartner_id'] ?? '')),
        '{eeg_name}' => (string)($community['name'] ?? ''),
    ]);
}

/**
 * Text einer Zustimmung in einer bestimmten Fassung, Platzhalter bereits ersetzt.
 * $variant: 'kurz' oder 'lang'.
 */
function consentText(string $version, string $field, string $variant, array $community): string
{
    $v = consentVersion($version);
    if ($v === null || !isset($v['texte'][$field][$variant])) {
        throw new InvalidArgumentException("Unbekannte Zustimmung: $version/$field/$variant");
    }
    return consentRender($v['texte'][$field][$variant], $community);
}

/**
 * Deckt die in dieser Fassung erteilte Vollmacht auch die Freischaltung der
 * P1-Kundenschnittstelle samt Schlüssel ab? Erst ab v2 -- Bestandsmitglieder mit v1-* haben das
 * so nicht unterschrieben (wichtig für die Sammelliste P1-Freischaltung).
 */
function consentCoversP1(?string $version): bool
{
    return (bool)(consentVersion($version)['vollmacht_p1'] ?? false);
}

/** "07.11.2026" -- Datum, ab dem eine Fassung gilt. */
function consentValidFromDisplay(string $version): string
{
    $v = consentVersion($version);
    return $v ? date('d.m.Y', strtotime($v['gueltig_ab'])) : '';
}

/**
 * Plain-Text -> HTML: escapen, Absätze als <br>, "stromfueralle.at/..."-Verweise als
 * klickbare Links (öffnen in neuem Tab, damit Formulareingaben erhalten bleiben).
 */
function consentHtml(string $text): string
{
    $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
    return preg_replace(
        '#stromfueralle\.at(/[a-z0-9/_-]+)#i',
        '<a href="$1" target="_blank" rel="noopener">stromfueralle.at$1</a>',
        $html
    );
}

/** Plain-Text -> LaTeX: escapen, Absätze als \newline, Verweise in Schreibmaschinenschrift. */
function consentLatex(string $text): string
{
    $tex = texEscape($text);
    // \allowbreak nach jedem "/": Schreibmaschinenschrift trennt nie von selbst, ein langer
    // Verweis am Zeilenende würde sonst über den Rand hinausragen.
    $tex = preg_replace_callback(
        '#stromfueralle\.at(/[a-z0-9/]+)?#i',
        fn($m) => '\\texttt{' . str_replace('/', '/\\allowbreak{}', $m[0]) . '}',
        $tex
    );
    return str_replace("\n", '\\newline ', $tex);
}

/**
 * Wortlaut des SEPA-Lastschrift-Mandats -- gemeinsame Quelle für Online-Formular,
 * Online-PDF und Offline-Formular. Der letzte Satz ("Ich erkläre ausdrücklich ...") fehlte bis
 * 06.11.2026 online (nur am Papierformular vorhanden) -- $mitBerechtigungserklaerung=false nur
 * für den Nachdruck alter Online-Anträge (v1-online), die ihn so nicht gesehen haben.
 * $community: name, zvr_number, address (Sitz wird daraus abgeleitet), creditor_id.
 */
function sepaMandateText(array $community, bool $mitBerechtigungserklaerung = true): string
{
    $name = (string)($community['name'] ?? '');
    $zvr  = trim((string)($community['zvr_number'] ?? '')) ?: '--';
    $sitz = extractOrtFromAddress($community['address'] ?? '');
    $creditorId = trim((string)($community['creditor_id'] ?? '')) ?: 'nicht hinterlegt';
    return 'Hiermit ermächtige ich die Erneuerbare-Energie-Gemeinschaft ' . $name . ', ZVR ' . $zvr
        . ', Sitz ' . $sitz . ', Creditor-ID: ' . $creditorId . ', widerruflich, die von mir zu entrichtenden'
        . ' Zahlungen bei Fälligkeit zu Lasten meines Kontos mittels wiederkehrender SEPA-Lastschriften einzuziehen.'
        . ' Zugleich weise ich mein Kreditinstitut an, die von der ' . $name . ' auf mein Konto gezogenen'
        . ' SEPA-Lastschriften einzulösen. Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die'
        . ' Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten'
        . ' Bedingungen.'
        . ($mitBerechtigungserklaerung
            ? ' Ich erkläre ausdrücklich, hinsichtlich des angegebenen Kontos berechtigt zu sein, ein SEPA-Lastschrift-Mandat zu erteilen.'
            : '');
}

/**
 * Template-Variablen für das leere Offline-Formular (latex-service beitrittserklaerung_leer.tex,
 * Route /:rc/beitrittserklaerung.pdf): Langfassung der AKTUELLEN Zustimmungen + SEPA-Text,
 * alles aus dieser Datei, Stammdaten aus der EEG ($community = Zeile aus communities).
 */
function offlineBeitrittserklaerungVars(array $community): array
{
    $version = consentCurrentVersion();
    $rc = strtolower((string)($community['marktpartner_id'] ?? ''));
    $email = trim((string)($community['contact_email'] ?? '')) ?: 'office@stromfueralle.at';
    $phone = trim((string)($community['contact_phone'] ?? ''));

    $liste = [];
    foreach (consentFields() as $field => $title) {
        $liste[] = '\\item \\textbf{' . texEscape($title) . ':} '
            . consentLatex(consentText($version, $field, 'lang', $community));
    }

    return [
        'EEG_NAME'                 => (string)($community['name'] ?? ''),
        'EEG_ZVR'                  => trim((string)($community['zvr_number'] ?? '')) ?: '--',
        'EEG_RC'                   => preg_replace('/^rc/i', '', $rc),
        'EEG_ADRESSE'              => (string)($community['address'] ?? ''),
        'KONTAKT_EMAIL'            => $email,
        'RAW_KONTAKT'              => '\\mbox{' . texEscape($email) . '}'
            . ($phone !== '' ? ', Tel.\\ ' . texEscape($phone) : ''),
        'RAW_SEPA_TEXT'            => texEscape(sepaMandateText($community)),
        'RAW_ZUSTIMMUNGEN_LISTE'   => implode("\n", $liste),
        'RAW_ZUSTIMMUNGEN_HINWEIS' => consentLatex(
            'Fassung vom ' . consentValidFromDisplay($version) . ' (Version ' . $version . '). '
            . 'Alle Fassungen: stromfueralle.at/' . $rc . '/zustimmungen'
        ),
    ];
}

/**
 * Zustimmungsblock für das Online-PDF einer eingereichten Beitrittserklärung
 * (/portal/applications/:id/formular): Kurzfassung der Fassung, die DIESER Antrag akzeptiert
 * hat (consent_version; unbekannt/leer -> v1-online, die einzige Fassung vor Einführung der
 * Versionierung), plus Hinweis auf den vollständigen Wortlaut und die Rechtsdokumente.
 * Rückgabe: ['version' => ..., 'liste' => LaTeX-\item-Zeilen, 'hinweis' => LaTeX-Text].
 */
function onlineBeitrittConsentLatex(array $application, array $community): array
{
    $version = consentVersion($application['consent_version'] ?? null) !== null
        ? (string)$application['consent_version'] : 'v1-online';
    $rc = strtolower((string)($community['marktpartner_id'] ?? ''));
    $isTrue = fn($v) => in_array($v, [true, 't', '1', 1, 'true'], true);

    $liste = [];
    foreach (consentFields() as $field => $title) {
        $liste[] = '\\item[' . ($isTrue($application[$field] ?? false) ? '\\cbon' : '\\cb') . ']  \\textbf{'
            . texEscape($title) . ':} ' . consentLatex(consentText($version, $field, 'kurz', $community));
    }

    $hinweis = consentLatex(
        'Es gilt der vollständige Wortlaut der Zustimmungen in der Fassung vom '
        . consentValidFromDisplay($version) . ' (Version ' . $version . '), abrufbar unter '
        . 'stromfueralle.at/' . $rc . '/zustimmungen. '
        . 'Statuten: stromfueralle.at/' . $rc . '/statuten, '
        . 'AGB: stromfueralle.at/' . $rc . '/agb, '
        . 'Preisliste: stromfueralle.at/' . $rc . '/preisliste, '
        . 'Datenschutz: stromfueralle.at/' . $rc . '/datenschutz'
    );

    return ['version' => $version, 'liste' => implode("\n", $liste), 'hinweis' => $hinweis];
}
