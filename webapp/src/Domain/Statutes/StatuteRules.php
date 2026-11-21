<?php

declare(strict_types=1);

namespace App\Domain\Statutes;

/**
 * Automatische Prüfregeln des Statuten-Generators (docs/STATUTEN_GENERATOR.md, Abschnitt 3).
 *
 * Fehler verhindern das Erzeugen; Hinweise werden angezeigt und ändern nichts. Rechtsstand
 * (RIS, geprüft 21.11.2026):
 *  - VerG: Mitgliederversammlung mindestens alle 5 Jahre (§ 5 Abs 2), Leitungsorgan mindestens
 *    2 natürliche Personen (§ 5 Abs 3), mindestens 2 unabhängige, unbefangene Rechnungsprüfer
 *    (§ 5 Abs 5), qualifizierte Rechnungslegung ab 1 Mio €, Abschlussprüfer ab 3 Mio €
 *    gewöhnlicher Einnahmen oder Ausgaben in zwei Folgejahren (§ 22).
 *  - ElWG: Erneuerbare-Energie-Gemeinschaft § 66 (mit § 79 EAG), Mitglieder nur natürliche
 *    Personen, Gemeinden, Behörden/öffentlich-rechtliche Stellen und KMU (§ 6 Abs 1 Z 45),
 *    Nahebereich nur Lokal- oder Regionalbereich (§ 70 Abs 6 Z 3 und 4).
 *  - UStG: Kleinunternehmergrenze 55.000 € im Vor- und im laufenden Jahr, 10 % Toleranz bis
 *    Jahresende (§ 6 Abs 1 Z 27); Verzicht bindet mindestens 5 Kalenderjahre (§ 6 Abs 3).
 */
final class StatuteRules
{
    /**
     * @param array<string, mixed> $a  Antworten aus StatuteQuestionnaire::normalize()
     * @param bool $complete  true = zum Erzeugen (Pflichtfelder prüfen), false = Zwischenspeichern
     * @return array{errors: list<string>, hints: list<string>}
     */
    public static function check(array $a, bool $complete = true): array
    {
        $errors = [];
        $hints = [];

        // ─── Pflichtangaben (nur beim Erzeugen) ────────────────────────────────
        if ($complete) {
            foreach (['name' => 'Vereinsname', 'seat' => 'Sitz (Gemeinde)', 'state' => 'Bundesland',
                      'grid_operator' => 'Netzbetreiber'] as $k => $label) {
                if (trim((string) ($a[$k] ?? '')) === '') {
                    $errors[] = "Bitte „{$label}“ angeben.";
                }
            }
        }

        // ─── A. Grunddaten ─────────────────────────────────────────────────────
        if (!in_array($a['area'] ?? '', ['lokal', 'regional'], true)) {
            $errors[] = 'Eine Erneuerbare-Energie-Gemeinschaft ist nur im Lokal- oder Regionalbereich möglich (§ 66 Abs 1 i. V. m. § 70 Abs 6 Z 3 und 4 ElWG). Für größere Gebiete ist eine Bürgerenergiegemeinschaft nötig.';
        }
        $name = mb_strtolower((string) ($a['name'] ?? ''));
        if ($name !== '' && !preg_match('/energie|strom|eeg|gemeinschaft|sonne|solar/u', $name)) {
            $hints[] = 'Der Vereinsname muss einen Schluss auf den Vereinszweck zulassen (§ 4 Abs 1 VerG). Ein Zusatz wie „Erneuerbare-Energie-Gemeinschaft …“ ist sicher.';
        }
        if ((int) ($a['founders_count'] ?? 0) < 2) {
            $errors[] = 'Ein Verein braucht mindestens zwei Gründungsmitglieder.';
        }

        // ─── B. Zweck & Geld ───────────────────────────────────────────────────
        if ((int) $a['fee_min'] > (int) $a['fee_max']) {
            $errors[] = 'Der Mindest-Mitgliedsbeitrag ist höher als der Höchstbeitrag.';
        }
        $turnover = (int) ($a['expected_turnover'] ?? 0);
        $limit = StatuteQuestionnaire::KLEINUNTERNEHMER_GRENZE;
        if (($a['vat_mode'] ?? 'klein') === 'klein') {
            if ($turnover > $limit) {
                $errors[] = sprintf(
                    'Mit einem erwarteten Jahresumsatz von %s € ist die Kleinunternehmerregelung nicht möglich (Grenze %s € im Vor- und im laufenden Jahr, § 6 Abs 1 Z 27 UStG). Bitte „Regelbesteuerung“ wählen.',
                    self::eur($turnover), self::eur($limit)
                );
            } elseif ($turnover >= (int) ($limit * 0.8)) {
                $hints[] = sprintf(
                    'Der erwartete Umsatz liegt nahe an der Kleinunternehmergrenze (%s €). Wird sie um mehr als 10 %% überschritten, ist ab diesem Umsatz Umsatzsteuer zu verrechnen; im Folgejahr gilt die Befreiung nicht mehr. Umsätze laufend beobachten.',
                    self::eur($limit)
                );
            } else {
                $hints[] = 'Kleinunternehmerregelung: Rechnungen ohne Umsatzsteuer mit dem Hinweis „Kleinunternehmer gem. § 6 Abs 1 Z 27 UStG“. Zum Umsatz zählen vor allem die Entgelte für gelieferte Energie; echte Mitgliedsbeiträge ohne Gegenleistung zählen nicht dazu.';
            }
        } else {
            $hints[] = 'Regelbesteuerung: Der Verzicht auf die Kleinunternehmerbefreiung wird beim Finanzamt erklärt (Formular U12) und bindet mindestens fünf Kalenderjahre (§ 6 Abs 3 UStG). Vorteil: Vorsteuerabzug, z. B. bei eigenen Anlagen.';
        }
        if ($turnover > 3000000) {
            $hints[] = 'Bei gewöhnlichen Einnahmen oder Ausgaben über 3 Mio € in zwei Folgejahren gilt der Verein als „großer Verein“: erweiterter Jahresabschluss und Abschlussprüfer (§ 22 Abs 2 VerG).';
        } elseif ($turnover > 1000000) {
            $hints[] = 'Bei gewöhnlichen Einnahmen oder Ausgaben über 1 Mio € in zwei Folgejahren ist statt der Einnahmen-Ausgaben-Rechnung ein Jahresabschluss (Bilanz, GuV) nötig (§ 22 Abs 1 VerG).';
        }
        $hints[] = 'Eine EEG ist nicht gemeinnützig und daher mit ihren Gewinnen körperschaftsteuerpflichtig (keine Mindestkörperschaftsteuer). Steuernummer beim Finanzamt beantragen (Formular Verf 15a).';

        // ─── C. Mitgliedschaft ─────────────────────────────────────────────────
        $hints[] = 'Austritt: Für Mitglieder, die Verbraucher oder Kleinunternehmen sind, gelten zwingende gesetzliche Kündigungs- und Rücktrittsrechte (u. a. KSchG, FAGG, ElWG). Die Austrittsfrist der Statuten darf diese nicht verlängern; die Statuten verweisen darauf.';
        if (($a['exit_notice'] ?? '') === 'q') {
            $hints[] = 'Ein Austritt nur zum Quartalsende ist für Verbraucher eher lang. Empfehlung: ein Monat zum Monatsletzten.';
        }

        // ─── D. Organe ─────────────────────────────────────────────────────────
        $board = (int) ($a['board_size'] ?? 0);
        if ($board < 2) {
            $errors[] = 'Der Vorstand (Leitungsorgan) muss aus mindestens zwei natürlichen Personen bestehen (§ 5 Abs 3 VerG).';
        }
        $seats = StatuteQuestionnaire::requiredBoardSeats($a);
        if ($board >= 2 && $board < $seats) {
            $errors[] = "Für die gewählten Funktionen (inkl. Stellvertretungen) braucht der Vorstand mindestens {$seats} Personen.";
        }
        if ((int) ($a['auditors_count'] ?? 0) < 2) {
            $errors[] = 'Es sind mindestens zwei Rechnungsprüfer zu bestellen (§ 5 Abs 5 VerG).';
        }
        foreach (self::auditorsInBoard($a['founders'] ?? []) as $person) {
            $errors[] = "„{$person}“ ist als Rechnungsprüfer und im Vorstand eingetragen. Rechnungsprüfer müssen unabhängig und unbefangen sein und dürfen nicht dem Vorstand angehören (§ 5 Abs 5 VerG).";
        }
        if (empty($a['deputy_chair'])) {
            $hints[] = 'Ohne Obmann-Stellvertretung vertritt bei Verhinderung des Obmanns der Kassier; diese Regel wird automatisch in die Statuten aufgenommen.';
        }
        if ((int) ($a['auditors_term'] ?? 0) > (int) ($a['board_term'] ?? 0)) {
            $hints[] = 'Die Funktionsperiode der Rechnungsprüfer ist länger als die des Vorstands. Zulässig, aber meist werden beide gemeinsam gewählt.';
        }

        // ─── E. Mitgliederversammlung ──────────────────────────────────────────
        $mv = (int) ($a['mv_interval'] ?? 0);
        if ($mv > 5) {
            $errors[] = 'Die ordentliche Mitgliederversammlung muss mindestens alle fünf Jahre stattfinden (§ 5 Abs 2 VerG).';
        }
        if ($mv > (int) ($a['board_term'] ?? 0)) {
            $errors[] = 'Die Mitgliederversammlung muss mindestens so oft stattfinden, wie die Funktionsperiode des Vorstands dauert – sonst kann nicht rechtzeitig neu gewählt werden.';
        }
        if ($mv > 1 && $mv <= 5) {
            $hints[] = 'Empfehlung: jährliche Mitgliederversammlung (Rechnungsabschluss, Bericht der Rechnungsprüfer, Entlastung).';
        }

        // ─── Fest eingebaut (nicht wählbar) ────────────────────────────────────
        // „Nicht gemeinnützig“, „schutzbedürftige Haushalte nicht ausschließbar“ und der Kreis der
        // zulässigen Mitglieder (keine Großunternehmen) stehen als feste Textbausteine in
        // statuten.tex; im Fragebogen gibt es dafür bewusst kein Feld.

        return ['errors' => array_values(array_unique($errors)), 'hints' => array_values(array_unique($hints))];
    }

    /**
     * Personen, die in der (freiwilligen) Gründerliste sowohl als Rechnungsprüfer als auch mit
     * einer Vorstandsfunktion stehen.
     *
     * @param list<array{name: string, role: string}> $founders
     * @return list<string>
     */
    public static function auditorsInBoard(array $founders): array
    {
        $auditors = [];
        $board = [];
        foreach ($founders as $f) {
            $key = mb_strtolower(trim($f['name']));
            $role = mb_strtolower($f['role']);
            if (str_contains($role, 'prüf') || str_contains($role, 'pruef')) {
                $auditors[$key] = $f['name'];
            }
            if (preg_match('/obmann|obfrau|kassier|schriftführ|schriftfuehr|vorstand|stellvertret/u', $role)) {
                $board[$key] = true;
            }
        }
        return array_values(array_intersect_key($auditors, $board));
    }

    private static function eur(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
