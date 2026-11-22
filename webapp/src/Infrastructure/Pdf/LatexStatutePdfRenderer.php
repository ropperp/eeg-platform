<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use App\Domain\Statutes\StatutePdfRenderer;
use App\Domain\Statutes\StatuteQuestionnaire as Q;

/**
 * PDFs des Statuten-Generators über den latex-service:
 *  - statuten   → latex-service/templates/statuten.tex (Querverweise per \label/\ref in der Vorlage)
 *  - anzeige    → latex-service/templates/statuten_anzeige.tex (Checkliste Vereinsanzeige)
 *  - protokoll  → latex-service/templates/protokoll.tex (vorhandene Protokollvorlage, vorausgefüllt)
 *
 * Alle Texte aus den Antworten werden hier escaped; wahlabhängige Absätze gehen als RAW_-Variablen
 * in die Vorlage. Die öffentlichen statischen Methoden liefern nur Variablen (testbar ohne Dienst).
 */
final class LatexStatutePdfRenderer implements StatutePdfRenderer
{
    public const DRAFT_HEADER = 'Entwurf -- keine Rechtsberatung';

    /** Netzebenen (ElWG/SNE-VO): 4/5 Regionalbereich, 6/7 Lokalbereich. Nur Ziffer → Klartext auf dem Deckblatt. */
    private const GRID_LEVEL_TEXT = [
        '4' => 'Umspannung Hoch-/Mittelspannung',
        '5' => 'Mittelspannung',
        '6' => 'Umspannung Mittel-/Niederspannung',
        '7' => 'Niederspannung',
    ];

    /** Anlagenmodell im Satz „Organisation der gemeinsamen Nutzung … (…)“ in § 3. */
    private const PLANT_TEXT = [
        'ueberschuss' => 'insbesondere Überschusseinspeisung aus Anlagen der Mitglieder',
        'voll'        => 'insbesondere Volleinspeisung aus Anlagen der Mitglieder',
        'eigen'       => 'insbesondere aus eigenen Anlagen des Vereins',
    ];

    public function render(string $document, array $answers, int $version, bool $draft): string
    {
        $assets = [];
        [$template, $vars] = match ($document) {
            'statuten'  => ['statuten', self::statuteVars($answers, $version, $draft)],
            'anzeige'   => ['statuten_anzeige', self::checklistVars($answers, $version, $draft)],
            'protokoll' => ['protokoll', self::protocolVars($answers, $version, $assets)],
            default     => throw new \InvalidArgumentException('Unbekanntes Dokument: ' . $document),
        };
        $error = null;
        $pdf = \generateLatexPdf($template, $vars, $assets, $error);
        if ($pdf === null) {
            throw new \RuntimeException($error ?? 'PDF-Erzeugung fehlgeschlagen.');
        }
        return $pdf;
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, string>
     */
    public static function statuteVars(array $a, int $version, bool $draft): array
    {
        $e = fn(mixed $v): string => \texEscape((string) $v);

        $sources = array_map(fn($k) => Q::ENERGY_SOURCES[$k], $a['energy_sources']);
        $area = $a['area'] === 'lokal' ? 'Lokalbereich' : 'Regionalbereich';
        $areaId = trim((string) $a['area_id']);
        $gridLevel = trim((string) $a['grid_level']);
        $idText = '';
        if ($areaId !== '' || $gridLevel !== '') {
            $parts = array_filter([$areaId !== '' ? $area . '-ID ' . $e($areaId) : '', $gridLevel !== '' ? 'Netzebene ' . $e($gridLevel) : '']);
            $idText = ' (' . implode(', ', $parts) . ')';
        }

        // § 3: Eintrittsgeld, Entgelte, Nachschuss
        $entry = (int) $a['entry_fee'] > 0 ? ' und ein einmaliges Eintrittsgeld von ' . self::eur((int) $a['entry_fee']) . ' Euro' : '';
        $pricesBy = $a['prices_by'] === 'mv' ? 'die Mitgliederversammlung' : 'der Vorstand';
        $preise = sprintf(
            'Die Höhe des jährlichen Mitgliedsbeitrags legt der Vorstand innerhalb einer Bandbreite von %s bis %s Euro je Mitglied fest. Die Entgelte für die bezogene und die eingespeiste Energie legt %s fest. Der Mitgliedsbeitrag wird %s verrechnet.',
            self::eur((int) $a['fee_min']), self::eur((int) $a['fee_max']), $pricesBy, Q::FEE_BILLING[$a['fee_billing']]
        );
        $nachschuss = !empty($a['additional_contrib'])
            ? 'Reichen die Mittel zur Deckung unabweisbarer Verbindlichkeiten nicht aus, kann die Mitgliederversammlung mit der Mehrheit nach \\ref{abs:mehrheit} einen einmaligen Nachschuss beschließen, der den doppelten Jahresbeitrag je Mitglied nicht übersteigen darf.'
            : 'Eine Nachschusspflicht der Mitglieder besteht nicht.';

        // § 4: Mitgliedsarten
        $types = ['ordentliche Mitglieder'];
        if (!empty($a['member_extra'])) {
            $types[] = 'außerordentliche Mitglieder';
        }
        if (!empty($a['member_honorary'])) {
            $types[] = 'Ehrenmitglieder';
        }
        $arten = count($types) === 1
            ? 'Der Verein hat ausschließlich ordentliche Mitglieder.'
            : 'Die Mitglieder des Vereins gliedern sich in ' . self::listJoin($types) . '.';
        if (!empty($a['member_extra'])) {
            $arten .= ' Außerordentliche Mitglieder fördern die Vereinstätigkeit vor allem durch Zahlung eines Beitrags, ohne an der gemeinsamen Energienutzung teilzunehmen.';
        }
        if (!empty($a['member_honorary'])) {
            $arten .= ' Ehrenmitglieder werden wegen besonderer Verdienste um den Verein von der Mitgliederversammlung ernannt.';
        }

        // § 5/§ 6
        $aufnahme = $a['admission_by'] === 'mv'
            ? 'Über die Aufnahme entscheidet die Mitgliederversammlung auf Grundlage eines schriftlichen Antrags (auch elektronisch). Die Aufnahme darf nur aus sachlichen Gründen verweigert werden; die Gründe sind mitzuteilen.'
            : 'Über die Aufnahme entscheidet der Vorstand auf Grundlage eines schriftlichen Antrags (auch elektronisch). Die Aufnahme darf nur aus sachlichen Gründen verweigert werden; die Gründe sind mitzuteilen.';
        $nachfolge = !empty($a['succession'])
            ? sprintf('Im Todesfall können die Rechtsnachfolger binnen %s nach dem Tod erklären, die Mitgliedschaft fortzusetzen, sofern sie die Voraussetzungen nach \\ref{abs:mitgliedkreis} erfüllen.', (int) $a['succession_months'] === 1 ? 'einem Monat' : self::number((int) $a['succession_months']) . ' Monaten')
            : 'Die Mitgliedschaft ist nicht vererblich; Rechtsnachfolger können die Aufnahme neu beantragen.';
        $excludedBy = $a['exclusion_by'] === 'mv' ? 'Beschluss der Mitgliederversammlung mit Dreiviertelmehrheit' : 'Beschluss des Vorstands';
        $ausschluss = sprintf(
            'Ein Mitglied kann durch %s ausgeschlossen werden, wenn es trotz %s unter Setzung einer angemessenen Nachfrist länger als %s mit der Zahlung von Beiträgen oder Entgelten im Rückstand ist, wegen grober Verletzung der Mitgliedspflichten oder wegen unehrenhaften Verhaltens. Schutzbedürftige Haushalte werden dabei nicht schlechter gestellt (\\ref{abs:schutzbeduerftig}).',
            $excludedBy,
            (int) $a['exclusion_reminders'] === 1 ? 'einer schriftlichen Mahnung' : self::number((int) $a['exclusion_reminders']) . ' schriftlicher Mahnungen',
            (int) $a['exclusion_months'] === 1 ? 'einen Monat' : self::number((int) $a['exclusion_months']) . ' Monate'
        );

        // § 7
        $datenfreigabe = !empty($a['data_release'])
            ? 'Ordentliche Mitglieder sind verpflichtet, dem Netzbetreiber die zur Teilnahme erforderliche Datenfreigabe (Viertelstundenwerte) zu erteilen und aufrechtzuerhalten.'
            : 'Ordentliche Mitglieder wirken an der für die Teilnahme erforderlichen Datenübermittlung durch den Netzbetreiber mit.';

        // § 9
        $mvInterval = (int) $a['mv_interval'] === 1 ? 'jährlich' : 'mindestens alle ' . self::number((int) $a['mv_interval']) . ' Jahre';
        $einladung = !empty($a['invite_email']) ? 'schriftlich oder per E-Mail an die zuletzt bekanntgegebene Adresse' : 'schriftlich';
        $virtuell = !empty($a['virtual'])
            ? 'Die Mitgliederversammlung kann auch virtuell oder hybrid (über ein geeignetes Videokonferenzsystem) abgehalten werden, sofern die Identität der Teilnehmenden und die Stimmabgabe nachvollziehbar sichergestellt sind.'
            : 'Die Mitgliederversammlung findet als Präsenzversammlung statt.';
        $stimmrecht = 'Stimmberechtigt sind nur die ordentlichen Mitglieder; jedes ordentliche Mitglied hat eine Stimme.';
        $stimmrecht .= !empty($a['proxy'])
            ? sprintf(' Die Übertragung des Stimmrechts auf ein anderes ordentliches Mitglied ist im Wege einer schriftlichen Bevollmächtigung zulässig; ein Mitglied kann höchstens %s vertreten.', (int) $a['proxy_max'] === 1 ? 'eine fremde Stimme' : self::number((int) $a['proxy_max']) . ' fremde Stimmen')
            : ' Eine Übertragung des Stimmrechts ist nicht zulässig.';
        $beschluss = $a['quorum'] === 'haelfte'
            ? sprintf('Die Mitgliederversammlung ist bei Anwesenheit der Hälfte aller stimmberechtigten Mitglieder beschlussfähig. Ist sie zur festgesetzten Stunde nicht beschlussfähig, so findet die Mitgliederversammlung %d Minuten später mit derselben Tagesordnung statt und ist dann ohne Rücksicht auf die Anzahl der Erschienenen beschlussfähig.', (int) $a['quorum_wait_minutes'])
            : 'Die Mitgliederversammlung ist ohne Rücksicht auf die Anzahl der Erschienenen beschlussfähig.';

        // § 10/§ 12: wer legt Entgelte fest
        $mvEntgelte = $a['prices_by'] === 'mv'
            ? 'Festsetzung der Entgelte für bezogene und eingespeiste Energie,'
            : 'Kenntnisnahme der vom Vorstand festgelegten Mitgliedsbeiträge und Entgelte,';
        $vorstandEntgelte = $a['prices_by'] === 'mv'
            ? 'Festsetzung der Mitgliedsbeiträge innerhalb der Bandbreite nach \\ref{par:mittel},'
            : 'Festsetzung der Mitgliedsbeiträge innerhalb der Bandbreite nach \\ref{par:mittel} und der Entgelte für bezogene und eingespeiste Energie,';

        // § 11: Vorstand
        $functions = ['dem Obmann bzw. der Obfrau', 'dem Kassier bzw. der Kassierin'];
        if (!empty($a['has_secretary'])) {
            $functions[] = 'dem Schriftführer bzw. der Schriftführerin';
        }
        foreach (['deputy_chair' => 'einer Stellvertretung des Obmanns', 'deputy_treasurer' => 'einer Stellvertretung des Kassiers',
                  'deputy_secretary' => 'einer Stellvertretung des Schriftführers'] as $k => $label) {
            if (!empty($a[$k])) {
                $functions[] = $label;
            }
        }
        $size = (int) $a['board_size'];
        $extra = $size - count($functions);
        $besetzung = sprintf('Der Vorstand besteht aus %s natürlichen Personen, und zwar aus %s', self::number($size), self::listJoin($functions));
        $besetzung .= $extra > 0 ? sprintf(' sowie %s weiteren Vorstandsmitglied%s.', self::number($extra, true), $extra === 1 ? '' : 'ern') : '.';
        $periode = self::years((int) $a['board_term']);
        $wiederwahl = (int) $a['reelection_max'] > 0
            ? sprintf('Eine Wiederwahl ist höchstens %s möglich.', self::times((int) $a['reelection_max']))
            : 'Wiederwahl ist möglich.';
        $kooptierung = !empty($a['cooptation'])
            ? 'Der Vorstand hat bei Ausscheiden eines gewählten Mitglieds das Recht, an seine Stelle ein anderes wählbares Mitglied zu kooptieren, wozu die nachträgliche Genehmigung in der nächstfolgenden Mitgliederversammlung einzuholen ist.'
            : 'Scheidet ein Vorstandsmitglied vorzeitig aus, ist in der nächsten Mitgliederversammlung eine Nachwahl durchzuführen.';
        $vorstandBeschluss = 'Er fasst seine Beschlüsse mit einfacher Stimmenmehrheit'
            . (!empty($a['casting_vote']) ? '; bei Stimmengleichheit gibt die Stimme des Vorsitzenden den Ausschlag.' : '; bei Stimmengleichheit gilt ein Antrag als abgelehnt.');
        $umlauf = !empty($a['circular_resolution'])
            ? 'Beschlüsse können auch im Umlaufweg (schriftlich oder per E-Mail) gefasst werden, wenn kein Vorstandsmitglied widerspricht.'
            : 'Beschlüsse werden in Sitzungen gefasst; eine Teilnahme per Videokonferenz gilt als Anwesenheit.';

        // § 13: Vertretung, Schriftführer, Stellvertretung
        $vertretung = $a['representation'] === 'gemeinsam'
            ? 'Der Verein wird nach außen von zwei Vorstandsmitgliedern gemeinsam vertreten, von denen eines der Obmann bzw. die Obfrau oder der Kassier bzw. die Kassierin sein muss. Schriftliche Ausfertigungen bedürfen dieser beiden Unterschriften.'
            : 'Der Obmann bzw. die Obfrau vertritt den Verein nach außen. Schriftliche Ausfertigungen des Vereins bedürfen zu ihrer Gültigkeit der Unterschrift des Obmanns bzw. der Obfrau, in Geldangelegenheiten nach \\ref{abs:vieraugen} zusätzlich der des Kassiers bzw. der Kassierin.';
        $schrift = !empty($a['has_secretary'])
            ? 'Der Schriftführer bzw. die Schriftführerin führt die Protokolle der Mitgliederversammlung und des Vorstands.'
            : 'Die Protokolle der Mitgliederversammlung und des Vorstands führt ein vom Vorsitz bestimmtes Vorstandsmitglied.';
        $stv = [];
        $stv[] = !empty($a['deputy_chair'])
            ? 'Im Fall der Verhinderung tritt an die Stelle des Obmanns bzw. der Obfrau die Stellvertretung.'
            : 'Im Fall der Verhinderung tritt an die Stelle des Obmanns bzw. der Obfrau der Kassier bzw. die Kassierin.';
        $stv[] = !empty($a['deputy_treasurer'])
            ? 'An die Stelle des Kassiers bzw. der Kassierin tritt dessen bzw. deren Stellvertretung.'
            : 'An die Stelle des Kassiers bzw. der Kassierin tritt der Obmann bzw. die Obfrau.';
        $stv[] = 'Ist ein für die gemeinsame Zeichnung nach \\ref{abs:vieraugen} nötiges Vorstandsmitglied verhindert, zeichnet ein anderes Vorstandsmitglied mit; gibt es keines, sind solche Rechtsgeschäfte außer bei Gefahr im Verzug bis zum Wegfall der Verhinderung aufzuschieben.';
        if (!empty($a['has_secretary'])) {
            $stv[] = !empty($a['deputy_secretary'])
                ? 'An die Stelle des Schriftführers bzw. der Schriftführerin tritt dessen bzw. deren Stellvertretung.'
                : 'An die Stelle des Schriftführers bzw. der Schriftführerin tritt ein vom Vorsitz bestimmtes Vorstandsmitglied.';
        }

        // Deckblatt (Aufbau wie bewährte EEG-Statuten): Typ, Name, Rechtsgrundlage, ID/Netzebene, Netzbetreiber
        $prefix = 'Erneuerbare-Energie-Gemeinschaft';
        $name = trim((string) $a['name']);
        $coverName = str_starts_with($name, $prefix) ? (string) preg_replace('/^[\s\-–]+/u', '', substr($name, strlen($prefix))) : $name;
        $coverId = [];
        if ($areaId !== '') {
            $coverId[] = ($a['area'] === 'lokal' ? 'Lokale' : 'Regionale') . ' ID: ' . $e($areaId);
        }
        if ($gridLevel !== '') {
            $levelText = self::GRID_LEVEL_TEXT[$gridLevel] ?? null;
            $coverId[] = 'Netzebene~' . $e($gridLevel) . ($levelText !== null ? ' (' . $levelText . ')' : '');
        }

        $hinweis = 'Erstellt mit dem Statuten-Generator (Fassung ' . $version . '). Vorlage, keine Rechtsberatung. Vor Einreichung bei der Vereinsbehörde von einer fachkundigen Person prüfen lassen. Rechtsgrundlagen: Vereinsgesetz 2002, Elektrizitätswirtschaftsgesetz (ElWG), Erneuerbaren-Ausbau-Gesetz (EAG); Stand der Gesetze vor Einreichung im RIS prüfen.';

        return [
            'VEREINSNAME'        => (string) $a['name'],
            'DECKBLATT_NAME'     => $coverName !== '' ? $coverName : $name,
            'NAHEBEREICH_ART'    => $a['area'] === 'lokal' ? 'Lokale' : 'Regionale',
            'RAW_DECKBLATT_ID'   => $coverId === [] ? '' : '{\\large ' . implode(' \\textbar{} ', $coverId) . '\\par}\\vspace{0.5cm}',
            'SITZ'               => (string) $a['seat'],
            'RAW_TAETIGKEITSBEREICH' => self::activityArea($a),
            'NAHEBEREICH'        => $area,
            'NETZBETREIBER'      => (string) $a['grid_operator'],
            'RAW_NAHEBEREICH_ID' => $idText,
            'ENERGIETRAEGER'     => self::listJoin($sources),
            'ANLAGENMODELL'      => self::PLANT_TEXT[$a['plant_model']],
            'RAW_EINTRITTSGELD'  => $entry,
            'RAW_PREISE'         => $e($preise),
            'RAW_NACHSCHUSS'     => $nachschuss,
            'RAW_MITGLIEDSARTEN' => $e($arten),
            'RAW_AUFNAHME'       => $e($aufnahme),
            'RAW_RECHTSNACHFOLGE' => $nachfolge,
            'AUSTRITTSFRIST'     => Q::EXIT_NOTICE[$a['exit_notice']]['text'],
            'RAW_AUSSCHLUSS'     => $ausschluss,
            'RAW_DATENFREIGABE'  => $e($datenfreigabe),
            'ABRECHNUNGSFRIST'   => (string) (int) $a['billing_days'],
            'MV_INTERVALL'       => $mvInterval,
            'AOMV_WOCHEN'        => self::number((int) $a['extra_mv_weeks']),
            'EINLADUNG_TAGE'     => (string) (int) $a['invite_days'],
            'RAW_EINLADUNG'      => $e($einladung),
            'RAW_VIRTUELL'       => $e($virtuell),
            'RAW_STIMMRECHT'     => $e($stimmrecht),
            'RAW_BESCHLUSSFAEHIGKEIT' => $e($beschluss),
            'MEHRHEIT'           => $a['majority_change'] === '3/4' ? 'drei Vierteln' : 'zwei Dritteln',
            'RAW_MV_ENTGELTE'    => $e($mvEntgelte),
            'RAW_MV_EHREN'       => !empty($a['member_honorary']) ? '  \\item Verleihung und Aberkennung der Ehrenmitgliedschaft,' : '',
            'RAW_VORSTAND_ENTGELTE' => $vorstandEntgelte,
            'RAW_VORSTAND_BESETZUNG' => $e($besetzung),
            'FUNKTIONSPERIODE'   => $periode,
            'RAW_WIEDERWAHL'     => $e($wiederwahl),
            'RAW_KOOPTIERUNG'    => $e($kooptierung),
            'RAW_VORSTAND_BESCHLUSS' => $e($vorstandBeschluss),
            'RAW_UMLAUF'         => $e($umlauf),
            'RAW_VERTRETUNG_AUSSEN' => $vertretung,
            'VIER_AUGEN'         => self::eur((int) $a['four_eyes_limit']),
            'RAW_SCHRIFTFUEHRER' => $e($schrift),
            'RAW_STELLVERTRETUNG' => implode(' ', $stv),
            'RP_ANZAHL'          => ucfirst(self::number((int) $a['auditors_count'])),
            'RP_PERIODE'         => self::years((int) $a['auditors_term'], true),
            'AUFLOESUNG_VERMOEGEN' => Q::DISSOLUTION[$a['dissolution_assets']],
            'RAW_HINWEIS'        => $e($hinweis),
            'RAW_ENTWURF'        => $draft ? self::DRAFT_HEADER : '',
            'VERSION'            => (string) $version,
            'DATUM'              => date('d.m.Y'),
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, string>
     */
    public static function checklistVars(array $a, int $version, bool $draft): array
    {
        $founders = $a['founders'] ?? [];
        $rows = '';
        if ($founders !== []) {
            $rows = "\\vspace{2mm}\\noindent{\\small\\bfseries Gründungsmitglieder (laut Fragebogen; Geburtsdaten und Anschriften ergänzen)}\\par\n"
                . "\\noindent{\\small\\renewcommand{\\arraystretch}{1.4}\\begin{tabularx}{\\textwidth}{@{}p{55mm} p{38mm} X@{}}\n"
                . "\\textbf{Name} & \\textbf{Funktion} & \\textbf{Geburtsdatum, -ort, Anschrift} \\\\ \\hline\n";
            foreach ($founders as $f) {
                $rows .= \texEscape($f['name']) . ' & ' . \texEscape($f['role']) . " & \\\\ \\hline\n";
            }
            $rows .= "\\end{tabularx}}\n";
        }
        $ust = $a['vat_mode'] === 'regel'
            ? 'Umsatzsteuer: Regelbesteuerung gewählt -- Verzicht auf die Kleinunternehmerbefreiung mit Formular U12 erklären (bindet mindestens fünf Kalenderjahre, § 6 Abs 3 UStG); UID-Nummer beantragen; Umsatzsteuervoranmeldungen.'
            : sprintf(
                'Umsatzsteuer: Kleinunternehmerregelung (erwarteter Jahresumsatz %s Euro, Grenze 55.000 Euro im Vor- und im laufenden Jahr, § 6 Abs 1 Z 27 UStG). Rechnungen mit Hinweis auf die Steuerbefreiung; Umsätze laufend beobachten.',
                self::eur((int) $a['expected_turnover'])
            );
        return [
            'VEREINSNAME'     => (string) $a['name'],
            'SITZ'            => (string) $a['seat'],
            'BEHOERDE'        => 'Bezirkshauptmannschaft bzw. Magistrat am Vereinssitz ' . $a['seat']
                . '; im Wirkungsbereich einer Landespolizeidirektion diese (§ 9 VerG)',
            'GRUENDER_ANZAHL' => (string) (int) $a['founders_count'],
            'RAW_GRUENDER'    => $rows,
            'RAW_UST'         => \texEscape($ust),
            'RAW_ENTWURF'     => $draft ? self::DRAFT_HEADER : '',
            'VERSION'         => (string) $version,
            'DATUM'           => date('d.m.Y'),
        ];
    }

    /**
     * Protokollvorlage der Gründungsversammlung über die vorhandene protokoll.tex.
     *
     * @param array<string, mixed> $a
     * @param array<string, string> $assets
     * @return array<string, string>
     */
    public static function protocolVars(array $a, int $version, array &$assets): array
    {
        $blank = '\\rule{45mm}{0.4pt}';
        $functions = ['Obmann/Obfrau', 'Kassier/in'];
        if (!empty($a['has_secretary'])) {
            $functions[] = 'Schriftführer/in';
        }
        foreach (['deputy_chair' => 'Obmann-Stellvertretung', 'deputy_treasurer' => 'Kassier-Stellvertretung',
                  'deputy_secretary' => 'Schriftführer-Stellvertretung'] as $k => $label) {
            if (!empty($a[$k])) {
                $functions[] = $label;
            }
        }
        for ($i = count($functions); $i < (int) $a['board_size']; $i++) {
            $functions[] = 'Vorstandsmitglied';
        }
        $boardLines = implode("\n", array_map(fn($f) => $f . ': ________________________', $functions));
        $auditorLines = implode("\n", array_map(fn($n) => 'Rechnungsprüfer/in ' . $n . ': ________________________', range(1, max(2, (int) $a['auditors_count']))));
        $ust = $a['vat_mode'] === 'regel'
            ? 'Der Verein verzichtet auf die Kleinunternehmerbefreiung (Regelbesteuerung, Formular U12, Bindung mindestens fünf Kalenderjahre).'
            : 'Der Verein wendet die Kleinunternehmerregelung an (§ 6 Abs 1 Z 27 UStG).';

        $items = [
            ['title' => 'Begrüßung, Feststellung der Anwesenheit und der Beschlussfähigkeit', 'body' => 'Vorsitz und Protokollführung werden bestimmt.'],
            ['title' => 'Beschluss der Statuten', 'body' => "Die Statuten in der Fassung {$version} vom " . date('d.m.Y') . ' werden vorgestellt und besprochen.',
             'resolution' => 'Die vorliegenden Statuten werden beschlossen.'],
            ['title' => 'Wahl des Vorstands', 'body' => 'Funktionsperiode: ' . self::years((int) $a['board_term']) . ".\n" . $boardLines,
             'resolution' => 'Die genannten Personen werden in den Vorstand gewählt und nehmen die Wahl an.'],
            ['title' => 'Wahl der Rechnungsprüfer', 'body' => 'Funktionsperiode: ' . self::years((int) $a['auditors_term']) . '. Die Rechnungsprüfer gehören nicht dem Vorstand an und sind unabhängig und unbefangen.' . "\n" . $auditorLines,
             'resolution' => 'Die genannten Personen werden zu Rechnungsprüfern gewählt und nehmen die Wahl an.'],
            ['title' => 'Mitgliedsbeiträge und Entgelte', 'body' => sprintf('Bandbreite des Mitgliedsbeitrags laut Statuten: %s bis %s Euro je Jahr. Festgelegter Beitrag: ________ Euro.', self::eur((int) $a['fee_min']), self::eur((int) $a['fee_max']))],
            ['title' => 'Steuerliche Behandlung', 'body' => $ust . ' Steuernummer beim Finanzamt beantragen (Verf 15a).'],
            ['title' => 'Anzeige der Vereinserrichtung und nächste Schritte', 'body' => 'Der Vorstand wird beauftragt, die Errichtung des Vereins bei der Vereinsbehörde anzuzeigen und die Teilnahme beim Netzbetreiber zu beantragen.'],
            ['title' => 'Allfälliges', 'body' => ''],
        ];
        foreach ($items as &$it) {
            $it += ['resolution' => null, 'votes_yes' => null, 'votes_no' => null, 'votes_abstain' => null];
        }
        unset($it);

        $assets = LatexAssemblyPdfRenderer::brandAsset();
        $vars = LatexAssemblyPdfRenderer::vars(
            [
                'title' => 'Gründungsversammlung (1. Mitgliederversammlung)', 'kind' => 'ordentlich', 'held_on' => '',
                'location' => '', 'chair_name' => '', 'minutes_taker' => '',
                'notes' => 'Vorlage: Leerstellen in der Versammlung ausfüllen. Vor der Einreichung prüfen lassen.',
            ],
            $items,
            [['name' => '', 'role_label' => 'Vorsitz'], ['name' => '', 'role_label' => 'Protokollführung']],
            ['name' => (string) $a['name'], 'address' => (string) $a['seat'], 'zvr_number' => ''],
            true,
            $assets
        );
        $vars['DATUM'] = '__________';
        $vars['RAW_ENTWURF'] = 'VORLAGE -- in der Gründungsversammlung ausfüllen';
        $vars['RAW_KOPFDATEN'] = implode("\n", [
            'Datum & ' . $blank . ' \\\\',
            'Ort & ' . $blank . ' \\\\',
            'Anwesende Gründer & ' . $blank . ' \\\\',
            'Vorsitz & ' . $blank . ' \\\\',
            'Protokollführung & ' . $blank . ' \\\\',
        ]);
        return $vars;
    }

    /** @param array<string, mixed> $a */
    private static function activityArea(array $a): string
    {
        $state = Q::STATES[$a['state']] ?? '';
        $district = trim((string) $a['district']);
        $parts = array_filter([$district !== '' ? $district : '', $state !== '' ? 'Bundesland ' . $state : '']);
        $where = $parts === [] ? 'Österreich' : implode(', ', $parts);
        return \texEscape($where) . ', soweit sich die Anlagen der Mitglieder im Nahebereich nach \\ref{abs:nahebereich} befinden';
    }

    /** @param list<string> $items */
    private static function listJoin(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' und ' . $last;
    }

    private static function eur(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    private static function number(int $n, bool $dative = false): string
    {
        $words = [1 => $dative ? 'einem' : 'eins', 2 => 'zwei', 3 => 'drei', 4 => 'vier', 5 => 'fünf', 6 => 'sechs',
                  7 => 'sieben', 8 => 'acht', 9 => 'neun', 10 => 'zehn', 11 => 'elf', 12 => 'zwölf'];
        return $words[$n] ?? (string) $n;
    }

    private static function years(int $n, bool $dative = false): string
    {
        if ($n === 1) {
            return $dative ? 'einem Jahr' : 'ein Jahr';
        }
        return self::number($n) . ($dative ? ' Jahren' : ' Jahre');
    }

    private static function times(int $n): string
    {
        return $n === 1 ? 'einmal' : self::number($n) . 'mal';
    }
}
