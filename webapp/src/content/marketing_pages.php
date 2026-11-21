<?php

declare(strict_types=1);

/**
 * Inhalte der öffentlichen Info-Seiten (Funktionen, Umstieg, FAQ, Pilot-EEG). Bewusst als Daten statt
 * als einzelne Templates: eine Vorlage (views/pages/marketing_page.php) rendert Text, Navigation und
 * strukturierte Daten (schema.org) gleich, die Seiten sind leicht zu pflegen.
 *
 * Hintergrund (17.11.2026): ein Sichtbarkeits-Check bemängelte zu wenig eigenen Inhalt, keine eigene
 * Seite pro Funktion und fehlende Frage-Antwort-Inhalte -- genau das Format, das KI-Antwortmaschinen
 * zitieren. Texte deshalb konkret und vollständig beantwortend, nicht werblich; Ansprache "Sie".
 *
 * Felder je Seite: title (<title>, max. ~60 Zeichen), description (Meta, max. 155 Zeichen), h1, lead,
 * sections (Liste von [h2, p (Absätze), list (optional)]), faq (Schlüssel aus marketingFaq()), related.
 */

/**
 * Netzgebühr-Hinweis für Marketing-Texte. Wert (Prozent) und Stand der Netztarife pflegt der
 * Plattform-Admin (Admin -> Einstellungen), nichts davon steht fest im Text: die Netztarife ändern
 * sich jährlich (Systemnutzungsentgelte-Verordnung). Ohne gepflegten Wert gibt es keine Zahl.
 *
 * @return array{satz: string, fussnote: string}
 */
function marketingNetzHinweis(): array
{
    $prozent = platformSettingText('netz_savings_percent');
    $stand = platformSettingText('netz_tariff_stand');
    $satz = 'Reduzierte Netzentgelte für den Strom, der innerhalb der Gemeinschaft geteilt wird.';
    $fussnote = '';
    if ($prozent !== '') {
        $zahl = rtrim(rtrim(number_format((float)$prozent, 1, ',', ''), '0'), ',');
        $satz = 'Reduzierte Netzentgelte für den Strom, der innerhalb der Gemeinschaft geteilt wird: derzeit rund ' . $zahl . ' % weniger Netzgebühr.*';
        $fussnote = '* Netztarife laut Systemnutzungsentgelte-Verordnung' . ($stand !== '' ? ', Stand ' . date('d.m.Y', strtotime($stand)) : '')
            . '. Die tatsächliche Ersparnis hängt vom Netzbetreiber und von Ihrem Verbrauch ab.';
    }
    return ['satz' => $satz, 'fussnote' => $fussnote];
}

/** @return array<string, array<string, mixed>> */
function marketingPages(): array
{
    $netz = marketingNetzHinweis();
    return [
        'abrechnung' => [
            'title'       => 'EEG-Abrechnung mit EDA-Daten | Strom für alle',
            'description' => 'Abrechnung für Energiegemeinschaften: EDA-Daten einspielen, Rechnungen und Gutschriften als PDF, SEPA-Lastschrift und Mahnwesen in einem.',
            'h1'          => 'Abrechnung für Energiegemeinschaften',
            'lead'        => 'Von der EDA-Datei bis zur bezahlten Rechnung: Strom für alle rechnet Bezug und Einspeisung Ihrer Mitglieder automatisch ab – auf Basis der offiziellen Energiedaten der Netzbetreiber.',
            'sections'    => [
                ['h2' => 'So läuft die Abrechnung ab', 'list' => [
                    'Energiedaten einspielen: die Monats- oder Viertelstundenwerte aus dem EDA-Portal als XLSX hochladen – oder automatisch aus dem Postfach abrufen lassen.',
                    'Zuordnung prüfen: neue Zählpunkte werden erkannt und angelegt, eine Datenqualitäts-Übersicht zeigt Lücken und Ersatzwerte.',
                    'Abrechnungszeitraum wählen: Quartal oder frei gewählter Zeitraum, Tarife für Bezug und Einspeisung pro EEG hinterlegt.',
                    'Rechnungen und Gutschriften erzeugen: als PDF mit Ihrem Briefkopf, per E-Mail an die Mitglieder.',
                    'Bezahlung einziehen: SEPA-Lastschrift-Datei für die Bank, Zahlungsstatus und Mahnungen in der Plattform.',
                ]],
                ['h2' => 'Was die Abrechnung berücksichtigt', 'list' => [
                    'Bezug und Einspeisung je Zählpunkt, auch mehrere Zählpunkte pro Mitglied.',
                    'Nur belastbare Messwerte (laut EDA abrechenbare Qualität), Ersatzwerte werden ausgewiesen.',
                    'Beitritt mitten im Zeitraum: Energie und Mitgliedsbeitrag werden anteilig verrechnet.',
                    'Umsatzsteuer, Mitgliedsbeitrag und zusätzliche Positionen pro Rechnung.',
                    'Jahresübersicht pro Mitglied für die eigene Dokumentation.',
                ]],
                ['h2' => 'Warum das für Ihren Vorstand wichtig ist', 'p' => [
                    'Die Abrechnung ist in vielen Energiegemeinschaften die aufwendigste ehrenamtliche Arbeit. Mit Strom für alle wird daraus ein wiederholbarer Ablauf mit wenigen Klicks – nachvollziehbar dokumentiert, mit Aktivitätsprotokoll und getrennten Daten je Gemeinschaft.',
                ]],
            ],
            'faq'         => ['kosten', 'umstieg', 'abrechnung'],
            'related'     => ['mitgliederverwaltung', 'umstieg', 'echtzeit-daten'],
        ],
        'mitgliederverwaltung' => [
            'title'       => 'Mitgliederverwaltung für EEG | Strom für alle',
            'description' => 'Mitglieder, Zählpunkte und Verträge einer Energiegemeinschaft verwalten: Online-Beitritt, digitale Unterschrift, Mitgliederportal und Protokolle.',
            'h1'          => 'Mitgliederverwaltung für Energiegemeinschaften',
            'lead'        => 'Alle Mitglieder, Zählpunkte, Verträge und Vereinsunterlagen Ihrer EEG an einem Ort – vom Online-Beitritt bis zum Protokoll der Mitgliederversammlung.',
            'sections'    => [
                ['h2' => 'Vom Beitritt bis zum Vertrag', 'list' => [
                    'Online-Beitrittsformular mit allen nötigen Zustimmungen, Unterschrift und SEPA-Mandat – direkt am Handy.',
                    'Freigabe neuer Mitglieder durch den Vorstand, Zählpunkte zuordnen.',
                    'Bezugs- und Einspeisevereinbarungen per Knopfdruck erzeugen und digital unterschreiben lassen.',
                    'Ausweis-Scans, Verträge und Dokumente sicher beim Mitglied abgelegt.',
                ]],
                ['h2' => 'Für Vorstand und Verein', 'list' => [
                    'Mitgliederliste mit Suche nach Name, Adresse, Kundennummer oder Zählpunkt.',
                    'Protokolle der Mitgliederversammlung erstellen, von mehreren Personen unterschreiben lassen und als PDF ablegen.',
                    'Support-Anfragen der Mitglieder an einer Stelle beantworten.',
                    'Mehrere Energiegemeinschaften auf einer Plattform, mit vollständig getrennten Daten.',
                ]],
                ['h2' => 'Für die Mitglieder', 'p' => [
                    'Jedes Mitglied hat einen eigenen Zugang zum Mitgliederportal: eigene Verträge, Rechnungen, Jahresübersicht und Energiedaten. Anmeldung mit Passwort und Zwei-Faktor-Code oder mit Passkey. Jedes Mitglied sieht ausschließlich die eigenen Daten.',
                ]],
                ['h2' => 'Nachvollziehbar und sicher', 'list' => [
                    'Klare Rollen: Vorstand bzw. Obmann verwaltet die eigene Gemeinschaft, Mitglieder sehen nur sich selbst.',
                    'Ein Aktivitätsprotokoll hält fest, wer wann was geändert hat – hilfreich bei Fragen und für die Rechnungsprüfung.',
                    'Mitglieder können ihre gespeicherten Daten jederzeit selbst als Auskunft herunterladen, wie es die DSGVO vorsieht.',
                    'Betrieb in Österreich, regelmäßige Sicherungen, die Daten jeder Gemeinschaft sind auf Datenbankebene getrennt.',
                ]],
            ],
            'faq'         => ['umstieg', 'datenschutz', 'kosten'],
            'related'     => ['abrechnung', 'umstieg', 'echtzeit-daten'],
        ],
        'echtzeit-daten' => [
            'title'       => 'Echtzeit-Daten vom Smart Meter für EEG | Strom für alle',
            'description' => 'Echtzeit-Verbrauch und Einspeisung einer Energiegemeinschaft live sehen: Ausleseeinheit für die Smart-Meter-Schnittstelle, Visualisierung, Live-Anzeige.',
            'h1'          => 'Echtzeit-Daten vom Smart Meter',
            'lead'        => 'Die offiziellen EDA-Daten kommen erst am nächsten Tag. Mit der Ausleseeinheit von Strom für alle sehen Mitglieder und Vorstand sofort, wann in der Gemeinschaft Strom gebraucht wird und wann Überschuss da ist.',
            'sections'    => [
                ['h2' => 'Wie es funktioniert', 'p' => [
                    'Eine kleine Ausleseeinheit wird an die Kundenschnittstelle (P1) des Smart Meters gesteckt und sendet Verbrauch und Einspeisung laufend alle paar Sekunden verschlüsselt an die Plattform. Entwickelt wurde sie als Diplomarbeitsprojekt an der HTL1 Lastenstraße in Klagenfurt, getestet mit den Smart Metern von Kärnten Netz.',
                ]],
                ['h2' => 'Was Mitglieder und Vorstand sehen', 'list' => [
                    'Den eigenen Verbrauch und die eigene Einspeisung in Echtzeit.',
                    'Die Gesamtbilanz der Gemeinschaft: Erzeugung, Verbrauch und Autarkie-Quote.',
                    'Viertelstundenwerte im Tagesverlauf, um den Verbrauch in sonnige Stunden zu verlegen.',
                    'Auf Wunsch eine öffentliche Live-Anzeige der Gemeinschaft – nur Summen, keine Einzeldaten.',
                ]],
                ['h2' => 'Stand und Kosten', 'p' => [
                    'Die Ausleseeinheit läuft im Pilotbetrieb in der EEG Strompool Feldkirchen Süd-West und wird schrittweise an die Mitglieder ausgegeben (voraussichtlich rund 30 Euro pro Stück, Preis noch nicht endgültig). Für andere Energiegemeinschaften ist sie als Zusatzpaket zur Verwaltung und Abrechnung erhältlich – die Kosten besprechen wir auf Anfrage.',
                ]],
            ],
            'faq'         => ['echtzeit', 'kosten', 'datenschutz'],
            'related'     => ['abrechnung', 'mitgliederverwaltung', 'energiegemeinschaft-feldkirchen'],
        ],
        'umstieg' => [
            'title'       => 'Umstieg auf Strom für alle | EEG-Software wechseln',
            'description' => 'Wechsel von Excel oder anderer EEG-Software: Mitglieder per Import übernehmen, EDA-Datei einspielen, abrechnen. Importfunktionen passen wir für Sie an.',
            'h1'          => 'Umstieg auf Strom für alle',
            'lead'        => 'Der Wechsel ist einfacher, als viele denken: Mitglieder übernehmen, EDA-Datei einspielen, abrechnen. Für Ihre bisherigen Daten programmieren wir bei Bedarf eine passende Importfunktion.',
            'sections'    => [
                ['h2' => 'In drei Schritten zur ersten Abrechnung', 'list' => [
                    'Mitglieder und Zählpunkte übernehmen – über die vorhandenen Importfunktionen oder eine eigens für Ihre bisherigen Unterlagen angepasste.',
                    'Die EDA-Datei (XLSX) einspielen: Zählpunkte werden zugeordnet, neue automatisch erkannt.',
                    'Tarife hinterlegen und die erste Abrechnung erzeugen.',
                ]],
                ['h2' => 'Ihre Daten, Ihr Format', 'p' => [
                    'Ob Excel-Liste, Export aus einer anderen Software oder Papierunterlagen: Beim Umstieg passen wir die Importfunktionen an Ihre vorhandenen Daten an, damit niemand Mitglieder einzeln abtippen muss.',
                ]],
                ['h2' => 'Was wir für den Umstieg brauchen', 'list' => [
                    'Ihre bisherige Mitgliederliste mit Zählpunkten, z. B. als Excel-Datei oder Export.',
                    'Die Tarife für Bezug und Einspeisung sowie den Mitgliedsbeitrag.',
                    'Eine aktuelle EDA-Datei Ihrer Gemeinschaft, um die Zuordnung der Zählpunkte zu prüfen.',
                    'Ihr Logo und die Vereinsdaten für Rechnungen, Verträge und Briefkopf.',
                ]],
                ['h2' => 'Gemeinsam weiterentwickeln', 'p' => [
                    'Strom für alle ist aus der Praxis einer Energiegemeinschaft entstanden und wird laufend weiterentwickelt. Vorstände und Mitglieder sind ausdrücklich eingeladen, ihre Ideen einzubringen – viele Funktionen der Plattform gehen auf Wünsche aus dem Vereinsalltag zurück.',
                ]],
            ],
            'faq'         => ['umstieg', 'ideen', 'kosten'],
            'related'     => ['abrechnung', 'mitgliederverwaltung'],
        ],
        'energiegemeinschaft-feldkirchen' => [
            'title'       => 'Energiegemeinschaft Feldkirchen in Kärnten beitreten',
            'description' => 'EEG Strompool Feldkirchen Süd-West: Energiegemeinschaft in Feldkirchen, Kärnten. So treten Sie bei, so funktioniert es, das bringt es Ihnen.',
            'h1'          => 'Energiegemeinschaft in Feldkirchen (Kärnten)',
            'lead'        => 'Die EEG Strompool Feldkirchen Süd-West ist ein nicht auf Gewinn ausgerichteter Verein, in dem Haushalte und Betriebe aus der Region Strom aus Photovoltaik untereinander teilen. Sie läuft als erste Gemeinschaft auf der Plattform Strom für alle.',
            'sections'    => [
                ['h2' => 'Was eine Energiegemeinschaft bringt', 'list' => [
                    'Strom von Nachbarn aus der Region zu einem fairen, im Verein festgelegten Preis.',
                    'Für Einspeiser: ein fairer, vom Verein festgelegter Preis für den Überschuss.',
                    $netz['satz'],
                    'Ihr bisheriger Stromlieferant bleibt – die Gemeinschaft deckt einen Teil Ihres Bedarfs.',
                ], 'footnote' => $netz['fussnote']],
                ['h2' => 'So treten Sie bei', 'list' => [
                    'Prüfen, ob Ihr Zählpunkt im passenden Netzgebiet liegt (Kärnten Netz bietet dafür eine Abfrage).',
                    'Das Online-Beitrittsformular ausfüllen – Zählpunktnummer und Bankverbindung bereithalten.',
                    'Der Vorstand gibt den Beitritt frei, danach erhalten Sie Ihre Vereinbarung zur digitalen Unterschrift.',
                    'Die Anmeldung beim Netzbetreiber stößt die Gemeinschaft an; gegebenenfalls bestätigen Sie die Datenfreigabe im Kundenportal Ihres Netzbetreibers.',
                ]],
                ['h2' => 'Live mitverfolgen', 'p' => [
                    'Auf der öffentlichen Live-Anzeige sehen Sie jederzeit, wie viel die Gemeinschaft gerade erzeugt und verbraucht. Mitglieder sehen im Portal zusätzlich ihre eigenen Werte.',
                ]],
            ],
            'faq'         => ['beitreten', 'echtzeit', 'datenschutz'],
            'related'     => ['echtzeit-daten'],
            'cta'         => ['href' => '/beitreten', 'label' => 'Jetzt informieren und beitreten'],
        ],
    ];
}

/**
 * Fragen und Antworten (FAQ-Seite und einzelne Info-Seiten). Antworten vollständig und für sich
 * verständlich formulieren -- sie werden als FAQPage (schema.org) ausgeliefert und so zitiert.
 *
 * @return array<string, array{q: string, a: string}>
 */
function marketingFaq(): array
{
    return [
        'was'          => ['q' => 'Was ist „Strom für alle“?',
            'a' => 'Strom für alle ist eine Plattform für Erneuerbare-Energie-Gemeinschaften (EEG) in Österreich. Sie vereint Mitgliederverwaltung, Verträge mit digitaler Unterschrift, die Abrechnung auf Basis der EDA-Energiedaten und auf Wunsch Echtzeit-Daten vom Smart Meter. Entstanden ist sie aus der Praxis der EEG Strompool Feldkirchen Süd-West in Kärnten.'],
        'fuer-wen'     => ['q' => 'Für wen ist die Plattform gedacht?',
            'a' => 'Für Vorstände und Betreiber von Energiegemeinschaften, die Mitglieder, Verträge und Abrechnung nicht mehr mit Excel und E-Mails erledigen wollen – und für deren Mitglieder, die im Portal ihre Rechnungen, Verträge und Energiedaten sehen.'],
        'kosten'       => ['q' => 'Was kostet Strom für alle?',
            'a' => 'Die Kosten besprechen wir auf Anfrage, weil sie vom Umfang abhängen: nur Mitgliederverwaltung und Abrechnung oder zusätzlich die Echtzeit-Ausleseeinheit für die Smart Meter samt Visualisierung. Schreiben Sie uns über das Kontaktformular, wir melden uns mit einem Angebot.'],
        'umstieg'      => ['q' => 'Wie aufwendig ist der Umstieg von einer anderen Software oder von Excel?',
            'a' => 'Der Umstieg ist unkompliziert: Die Mitglieder werden über Importfunktionen angelegt, danach wird die EDA-Datei (XLSX) eingespielt und die Abrechnung erstellt. Für Ihre bisherigen Daten passen wir die Importfunktion bei Bedarf eigens an, damit nichts abgetippt werden muss.'],
        'abrechnung'   => ['q' => 'Wie funktioniert die Abrechnung einer Energiegemeinschaft mit Strom für alle?',
            'a' => 'Sie spielen die Energiedaten aus dem EDA-Portal ein (oder lassen sie automatisch aus dem Postfach abrufen), wählen den Abrechnungszeitraum und erzeugen Rechnungen und Gutschriften als PDF. Die Plattform verschickt sie per E-Mail, erstellt die SEPA-Lastschrift-Datei für die Bank und verwaltet Zahlungsstatus und Mahnungen.'],
        'echtzeit'     => ['q' => 'Welche Plattform zeigt Echtzeit-Daten einer Energiegemeinschaft mit Smart Meter in Kärnten?',
            'a' => 'Strom für alle zeigt Verbrauch, Einspeisung und Autarkie-Quote einer Energiegemeinschaft in Echtzeit. Dafür liest eine eigene Ausleseeinheit die Kundenschnittstelle (P1) der Smart Meter aus; im Pilotbetrieb läuft das mit den Smart Metern von Kärnten Netz in der EEG Strompool Feldkirchen Süd-West.'],
        'datenschutz'  => ['q' => 'Sind die Energiedaten sicher und DSGVO-konform?',
            'a' => 'Ja. Energiedaten sind personenbezogen, deshalb sieht jedes Mitglied nur die eigenen Werte und die Summe der Gemeinschaft. Die Daten mehrerer Gemeinschaften sind auf Datenbankebene getrennt, die Anmeldung ist mit Zwei-Faktor-Code oder Passkey geschützt, und die Plattform wird in Österreich betrieben.'],
        'wer-nutzt'    => ['q' => 'Welche Energiegemeinschaften nutzen die Plattform?',
            'a' => 'Derzeit läuft die EEG Strompool Feldkirchen Süd-West in Kärnten als erste Gemeinschaft auf Strom für alle. Die Plattform ist für beliebig viele Gemeinschaften in ganz Österreich ausgelegt; weitere sind herzlich willkommen.'],
        'ideen'        => ['q' => 'Können wir eigene Ideen und Wünsche einbringen?',
            'a' => 'Ja, ausdrücklich. Strom für alle wird laufend weiterentwickelt, und viele Funktionen gehen auf Wünsche von Vorständen und Mitgliedern zurück. Vorschläge können direkt im Portal über „Support“ eingebracht werden.'],
        'beitreten'    => ['q' => 'Wie trete ich der Energiegemeinschaft in Feldkirchen in Kärnten bei?',
            'a' => 'Prüfen Sie zuerst bei Kärnten Netz, ob Ihr Zählpunkt im passenden Netzgebiet liegt. Dann füllen Sie das Online-Beitrittsformular der EEG Strompool Feldkirchen Süd-West aus (Zählpunktnummer und Bankverbindung bereithalten). Nach der Freigabe durch den Vorstand unterschreiben Sie Ihre Vereinbarung digital. Die Anmeldung beim Netzbetreiber stößt die Gemeinschaft an.'],
        'oesterreich'  => ['q' => 'Funktioniert die Plattform auch außerhalb von Kärnten?',
            'a' => 'Ja. Die Abrechnung beruht auf den EDA-Daten, die alle österreichischen Netzbetreiber im selben Format liefern, und funktioniert damit in ganz Österreich. Die Echtzeit-Ausleseeinheit nutzt die genormte Kundenschnittstelle der Smart Meter; mit welchen Zählern sie außerhalb von Kärnten Netz funktioniert, klären wir gerne im Einzelfall.'],
    ];
}

/** Kurzbeschreibung der Funktionsseiten für Übersichten (Startseite, /funktionen). */
function marketingFeatureTeasers(): array
{
    return [
        'abrechnung'           => ['icon' => 'currency-eur', 'label' => 'Abrechnung', 'text' => 'EDA-Daten einspielen, Rechnungen als PDF, SEPA-Lastschrift und Mahnwesen.'],
        'mitgliederverwaltung' => ['icon' => 'users-three', 'label' => 'Mitgliederverwaltung', 'text' => 'Online-Beitritt, digitale Verträge, Mitgliederportal und Versammlungsprotokolle.'],
        'echtzeit-daten'       => ['icon' => 'lightning', 'label' => 'Echtzeit-Daten', 'text' => 'Ausleseeinheit für den Smart Meter, Live-Bilanz der Gemeinschaft.'],
        'umstieg'              => ['icon' => 'arrow-right', 'label' => 'Umstieg', 'text' => 'Import der Mitglieder, EDA-Datei einspielen, abrechnen – Importe passen wir an.'],
    ];
}

/**
 * schema.org-Daten der Startseite: Plattform (SoftwareApplication) und Verein (NGO) GETRENNT und
 * verknüpft -- der Sichtbarkeits-Check bemängelte, dass beides vermischt war. Vereinsdaten kommen
 * zur Laufzeit aus der Datenbank (wie im Impressum), nicht aus dem Repo.
 *
 * @param array<string, mixed>|null $facts PdoPublicFactsRepository::forSlug() oder null (DB weg)
 * @return array<string, mixed>
 */
function marketingOrgJsonLd(string $baseUrl, ?array $facts): array
{
    $base = rtrim($baseUrl, '/');
    $verein = [
        '@type'       => 'NGO',
        '@id'         => $base . '/#verein',
        'name'        => $facts['name'] ?? 'EEG Strompool Feldkirchen Süd-West',
        'url'         => $base . '/energiegemeinschaft-feldkirchen',
        'description' => 'Nicht auf Gewinn ausgerichteter Verein: Erneuerbare-Energie-Gemeinschaft in Feldkirchen in Kärnten, Betreiber der Plattform Strom für alle.',
        'areaServed'  => ['@type' => 'AdministrativeArea', 'name' => 'Kärnten'],
    ];
    if (!empty($facts['address']) && preg_match('/^(.+?),\s*(\d{4})\s+(.+)$/u', (string) $facts['address'], $m)) {
        $verein['address'] = ['@type' => 'PostalAddress', 'streetAddress' => trim($m[1]), 'postalCode' => $m[2],
            'addressLocality' => trim($m[3]), 'addressRegion' => 'Kärnten', 'addressCountry' => 'AT'];
    } else {
        $verein['address'] = ['@type' => 'PostalAddress', 'addressLocality' => 'Feldkirchen in Kärnten', 'addressRegion' => 'Kärnten', 'addressCountry' => 'AT'];
    }
    foreach (['email' => 'email', 'phone' => 'telephone'] as $k => $prop) {
        if (!empty($facts[$k])) {
            $verein[$prop] = (string) $facts[$k];
        }
    }
    if (!empty($facts['zvr'])) {
        $verein['identifier'] = ['@type' => 'PropertyValue', 'propertyID' => 'ZVR-Zahl', 'value' => (string) $facts['zvr']];
    }
    return [
        '@context' => 'https://schema.org',
        '@graph'   => [
            [
                '@type'               => 'SoftwareApplication',
                '@id'                 => $base . '/#plattform',
                'name'                => 'Strom für alle',
                'url'                 => $base . '/',
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem'     => 'Web',
                'description'         => 'Plattform für Erneuerbare-Energie-Gemeinschaften (EEG) in Österreich: Mitgliederverwaltung, Abrechnung mit EDA-Daten, SEPA, digitale Verträge und Echtzeit-Daten vom Smart Meter.',
                'featureList'         => ['Mitgliederverwaltung', 'Abrechnung mit EDA-Daten', 'SEPA-Lastschrift und Mahnwesen', 'Digitale Vertragsunterschrift', 'Echtzeit-Daten vom Smart Meter', 'Protokolle von Mitgliederversammlungen'],
                'areaServed'          => ['@type' => 'Country', 'name' => 'Österreich'],
                'inLanguage'          => 'de-AT',
                'provider'            => ['@id' => $base . '/#verein'],
                'logo'                => $base . '/logo-light.png',
            ],
            $verein,
            [
                '@type'      => 'WebSite',
                '@id'        => $base . '/#website',
                'name'       => 'Strom für alle',
                'url'        => $base . '/',
                'inLanguage' => 'de-AT',
                'publisher'  => ['@id' => $base . '/#verein'],
                'about'      => ['@id' => $base . '/#plattform'],
            ],
        ],
    ];
}

/**
 * FAQPage-Daten für eine Liste von FAQ-Schlüsseln.
 *
 * @param list<string> $keys
 * @return array<string, mixed>
 */
function marketingFaqJsonLd(array $keys): array
{
    $all = marketingFaq();
    $items = [];
    foreach ($keys as $k) {
        if (isset($all[$k])) {
            $items[] = ['@type' => 'Question', 'name' => $all[$k]['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $all[$k]['a']]];
        }
    }
    return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items];
}

/** JSON-LD sicher in ein <script> einbetten (kein "</script>" im Text möglich). */
function jsonLdScript(array $data): string
{
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}
