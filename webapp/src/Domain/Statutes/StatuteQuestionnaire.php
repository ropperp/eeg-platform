<?php

declare(strict_types=1);

namespace App\Domain\Statutes;

/**
 * Fragebogen des Statuten-Generators (Abschnitte A–F, docs/STATUTEN_GENERATOR.md): Felder,
 * erlaubte Werte, Standardwerte und die Normalisierung der Formulareingabe.
 *
 * Gespeichert wird ausschließlich, was normalize() liefert (Whitelist): unbekannte Felder fallen
 * weg, Auswahlfelder fallen auf den Standard zurück, Zahlen werden begrenzt. Die fachlichen
 * Prüfregeln (Widersprüche zwischen Feldern) stehen in StatuteRules.
 */
final class StatuteQuestionnaire
{
    /** Umsatzgrenze der Kleinunternehmerbefreiung (§ 6 Abs 1 Z 27 UStG 1994, ab 01.01.2025). */
    public const KLEINUNTERNEHMER_GRENZE = 55000;

    public const STATES = [
        'burgenland' => 'Burgenland', 'kaernten' => 'Kärnten', 'niederoesterreich' => 'Niederösterreich',
        'oberoesterreich' => 'Oberösterreich', 'salzburg' => 'Salzburg', 'steiermark' => 'Steiermark',
        'tirol' => 'Tirol', 'vorarlberg' => 'Vorarlberg', 'wien' => 'Wien',
    ];

    /** Netzbetreiber (Auswahl der großen Verteilernetzbetreiber; "Sonstiger" = Freitext). */
    public const GRID_OPERATORS = [
        'Kärnten Netz GmbH', 'Netz Niederösterreich GmbH', 'Netz Oberösterreich GmbH', 'Salzburg Netz GmbH',
        'Energienetze Steiermark GmbH', 'TINETZ-Tiroler Netze GmbH', 'Vorarlberger Energienetze GmbH',
        'Wiener Netze GmbH', 'Netz Burgenland GmbH', 'LINZ NETZ GmbH', 'Stromnetz Graz GmbH & Co KG',
        'Innsbrucker Kommunalbetriebe AG',
    ];

    public const AREAS = ['lokal' => 'Lokalbereich (Niederspannung)', 'regional' => 'Regionalbereich (Mittelspannung)'];

    public const ENERGY_SOURCES = ['pv' => 'Photovoltaik', 'wind' => 'Wind', 'wasser' => 'Wasserkraft', 'biomasse' => 'Biomasse'];

    public const PLANT_MODELS = [
        'ueberschuss' => 'Überschusseinspeiser (Anlagen der Mitglieder)',
        'voll'        => 'Volleinspeiser (Anlagen der Mitglieder)',
        'eigen'       => 'Eigene Anlagen des Vereins',
    ];

    public const FEE_BILLING = ['energie' => 'mit der Energieabrechnung', 'quartal' => 'quartalsweise', 'jahr' => 'jährlich'];
    public const BODY = ['vorstand' => 'Vorstand', 'mv' => 'Mitgliederversammlung'];
    public const VAT_MODES = [
        'klein' => 'Kleinunternehmerregelung (keine Umsatzsteuer)',
        'regel' => 'Regelbesteuerung (Umsatzsteuer, Vorsteuerabzug)',
    ];

    public const EXIT_NOTICE = [
        '2w' => ['label' => 'zwei Wochen', 'text' => 'unter Einhaltung einer Frist von zwei Wochen'],
        '1m' => ['label' => 'ein Monat zum Monatsletzten', 'text' => 'unter Einhaltung einer Frist von einem Monat zum Monatsletzten'],
        'q'  => ['label' => 'zum Quartalsende (ein Monat)', 'text' => 'unter Einhaltung einer Frist von einem Monat zum Ende eines Kalenderquartals'],
    ];

    public const REPRESENTATION = [
        'allein'    => 'Obmann/Obfrau allein',
        'gemeinsam' => 'zwei Vorstandsmitglieder gemeinsam',
    ];

    public const QUORUM = ['jederzeit' => 'jederzeit beschlussfähig', 'haelfte' => 'Hälfte anwesend, sonst nach Wartezeit'];
    public const MAJORITY = ['2/3' => 'Zweidrittelmehrheit', '3/4' => 'Dreiviertelmehrheit'];

    public const DISSOLUTION = [
        'eeg'          => 'an eine andere Energiegemeinschaft mit gleichartigem Zweck',
        'erneuerbar'   => 'für Zwecke der Förderung erneuerbarer Energie',
        'gemeinnuetzig' => 'an eine gemeinnützige Organisation',
    ];

    /** Felder, die der Admin in der Einladung vorbelegen darf. */
    public const PREFILL_KEYS = ['area', 'grid_operator', 'state'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            // A. Grunddaten (Modell EEG und Rechtsform Verein sind fest)
            'name' => '', 'seat' => '', 'state' => '', 'district' => '',
            'area' => 'regional', 'area_id' => '', 'grid_level' => '',
            'grid_operator' => '', 'founders_count' => 2, 'founders' => [],
            // B. Zweck & Geld
            'energy_sources' => ['pv'], 'plant_model' => 'ueberschuss',
            'entry_fee' => 0, 'fee_min' => 0, 'fee_max' => 120, 'fee_billing' => 'energie',
            'prices_by' => 'vorstand', 'additional_contrib' => false, 'billing_days' => 60,
            'expected_turnover' => 0, 'vat_mode' => 'klein',
            // C. Mitgliedschaft
            'member_extra' => true, 'member_honorary' => true, 'admission_by' => 'vorstand',
            'exit_notice' => '1m', 'exclusion_months' => 3, 'exclusion_reminders' => 2, 'exclusion_by' => 'vorstand',
            'succession' => true, 'succession_months' => 2, 'data_release' => true,
            // D. Organe
            'board_size' => 2, 'has_secretary' => false,
            'deputy_chair' => false, 'deputy_treasurer' => false, 'deputy_secretary' => false,
            'board_term' => 3, 'reelection_max' => 0, 'cooptation' => true,
            'representation' => 'allein', 'four_eyes_limit' => 1000,
            'casting_vote' => true, 'circular_resolution' => true,
            'auditors_count' => 2, 'auditors_term' => 3,
            // E. Mitgliederversammlung
            'mv_interval' => 1, 'extra_mv_weeks' => 4, 'invite_days' => 14, 'invite_email' => true,
            'virtual' => true, 'quorum' => 'jederzeit', 'quorum_wait_minutes' => 30,
            'proxy' => true, 'proxy_max' => 1, 'majority_change' => '2/3',
            // F. Auflösung
            'dissolution_assets' => 'eeg',
        ];
    }

    /**
     * Formulareingabe (oder gespeicherte Antworten) → vollständige, bereinigte Antworten.
     * Fehlende Felder bekommen den Standardwert; Checkboxen gelten nur als gesetzt, wenn sie
     * übermittelt wurden ($fromForm = true), sonst bleibt der gespeicherte Wert.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function normalize(array $in, bool $fromForm = true): array
    {
        $d = self::defaults();
        $out = [];

        $out['name']          = self::text($in['name'] ?? '', 150);
        $out['seat']          = self::text($in['seat'] ?? '', 100);
        $out['state']         = self::choice($in['state'] ?? '', self::STATES, '');
        $out['district']      = self::text($in['district'] ?? '', 150);
        // Bewusst KEIN Rückfall auf den Standard: ein unzulässiger Nahebereich (z. B. aus einem
        // manipulierten Formular) bleibt sichtbar und wird von StatuteRules als Fehler gemeldet.
        $area = (string) ($in['area'] ?? $d['area']);
        $out['area']          = $area === '' ? $d['area'] : self::text($area, 20);
        $out['area_id']       = self::text($in['area_id'] ?? '', 30);
        $out['grid_level']    = self::text($in['grid_level'] ?? '', 20);
        $out['grid_operator'] = self::text($in['grid_operator'] ?? '', 120);
        $out['founders_count'] = self::int($in['founders_count'] ?? $d['founders_count'], 0, 999);
        $out['founders']      = self::founders($in['founders'] ?? []);

        $sources = array_values(array_intersect(array_keys(self::ENERGY_SOURCES), (array) ($in['energy_sources'] ?? [])));
        $out['energy_sources'] = $sources !== [] ? $sources : $d['energy_sources'];
        $out['plant_model']   = self::choice($in['plant_model'] ?? '', self::PLANT_MODELS, $d['plant_model']);
        $out['entry_fee']     = self::int($in['entry_fee'] ?? 0, 0, 100000);
        $out['fee_min']       = self::int($in['fee_min'] ?? $d['fee_min'], 0, 100000);
        $out['fee_max']       = self::int($in['fee_max'] ?? $d['fee_max'], 0, 100000);
        $out['fee_billing']   = self::choice($in['fee_billing'] ?? '', self::FEE_BILLING, $d['fee_billing']);
        $out['prices_by']     = self::choice($in['prices_by'] ?? '', self::BODY, $d['prices_by']);
        $out['billing_days']  = self::int($in['billing_days'] ?? $d['billing_days'], 1, 365);
        $out['expected_turnover'] = self::int($in['expected_turnover'] ?? 0, 0, 1000000000);
        $out['vat_mode']      = self::choice($in['vat_mode'] ?? '', self::VAT_MODES, $d['vat_mode']);

        $out['admission_by']  = self::choice($in['admission_by'] ?? '', self::BODY, $d['admission_by']);
        $out['exit_notice']   = self::choice($in['exit_notice'] ?? '', self::EXIT_NOTICE, $d['exit_notice']);
        $out['exclusion_months'] = self::int($in['exclusion_months'] ?? $d['exclusion_months'], 1, 24);
        $out['exclusion_reminders'] = self::int($in['exclusion_reminders'] ?? $d['exclusion_reminders'], 1, 5);
        $out['exclusion_by']  = self::choice($in['exclusion_by'] ?? '', self::BODY, $d['exclusion_by']);
        $out['succession_months'] = self::int($in['succession_months'] ?? $d['succession_months'], 1, 12);

        $out['board_size']    = self::int($in['board_size'] ?? $d['board_size'], 0, 6);
        $out['board_term']    = self::int($in['board_term'] ?? $d['board_term'], 1, 5);
        $out['reelection_max'] = self::int($in['reelection_max'] ?? 0, 0, 10);
        $out['representation'] = self::choice($in['representation'] ?? '', self::REPRESENTATION, $d['representation']);
        $out['four_eyes_limit'] = self::int($in['four_eyes_limit'] ?? $d['four_eyes_limit'], 0, 10000000);
        $out['auditors_count'] = self::int($in['auditors_count'] ?? $d['auditors_count'], 0, 5);
        $out['auditors_term'] = self::int($in['auditors_term'] ?? $d['auditors_term'], 1, 5);

        $out['mv_interval']   = self::int($in['mv_interval'] ?? $d['mv_interval'], 1, 10);
        $out['extra_mv_weeks'] = self::intChoice($in['extra_mv_weeks'] ?? null, [2, 3, 4], $d['extra_mv_weeks']);
        $out['invite_days']   = self::intChoice($in['invite_days'] ?? null, [7, 14], $d['invite_days']);
        $out['quorum']        = self::choice($in['quorum'] ?? '', self::QUORUM, $d['quorum']);
        $out['quorum_wait_minutes'] = self::int($in['quorum_wait_minutes'] ?? $d['quorum_wait_minutes'], 0, 120);
        $out['proxy_max']     = self::int($in['proxy_max'] ?? $d['proxy_max'], 1, 5);
        $out['majority_change'] = self::choice($in['majority_change'] ?? '', self::MAJORITY, $d['majority_change']);

        $out['dissolution_assets'] = self::choice($in['dissolution_assets'] ?? '', self::DISSOLUTION, $d['dissolution_assets']);

        foreach (['additional_contrib', 'member_extra', 'member_honorary', 'succession', 'data_release',
                  'has_secretary', 'deputy_chair', 'deputy_treasurer', 'deputy_secretary', 'cooptation',
                  'casting_vote', 'circular_resolution', 'invite_email', 'virtual', 'proxy'] as $flag) {
            if ($fromForm) {
                $out[$flag] = !empty($in[$flag]);
            } else {
                $out[$flag] = array_key_exists($flag, $in) ? (bool) $in[$flag] : $d[$flag];
            }
        }
        // Ohne Schriftführer gibt es keinen Schriftführer-Stellvertreter.
        if (!$out['has_secretary']) {
            $out['deputy_secretary'] = false;
        }
        return $out;
    }

    /**
     * Vorbelegung aus der Einladung (nur erlaubte Felder) über die Standardwerte legen.
     *
     * @param array<string, mixed> $prefill
     * @return array<string, mixed>
     */
    public static function withPrefill(array $prefill): array
    {
        $a = self::defaults();
        foreach (self::PREFILL_KEYS as $k) {
            if (isset($prefill[$k]) && $prefill[$k] !== '') {
                $a[$k] = $prefill[$k];
            }
        }
        return self::normalize($a, false);
    }

    /**
     * Gründungsmitglieder: freiwillig, eine Zeile je Person "Name; Funktion". Nur für die
     * Protokollvorlage und die Anzeige-Checkliste (Datenminimierung, docs/DSGVO.md).
     *
     * @return list<array{name: string, role: string}>
     */
    public static function founders(mixed $raw): array
    {
        $lines = is_array($raw) ? $raw : preg_split('/\R/', (string) $raw);
        $out = [];
        foreach ($lines ?: [] as $line) {
            if (is_array($line)) {
                $name = self::text($line['name'] ?? '', 100);
                $role = self::text($line['role'] ?? '', 60);
            } else {
                $parts = array_map('trim', explode(';', (string) $line, 2));
                $name = self::text($parts[0], 100);
                $role = self::text($parts[1] ?? '', 60);
            }
            if ($name !== '') {
                $out[] = ['name' => $name, 'role' => $role];
            }
            if (count($out) >= 30) {
                break;
            }
        }
        return $out;
    }

    /** Anzahl der Vorstandsfunktionen, die die gewählte Besetzung mindestens braucht. */
    public static function requiredBoardSeats(array $a): int
    {
        return 2 + (int) !empty($a['has_secretary']) + (int) !empty($a['deputy_chair'])
            + (int) !empty($a['deputy_treasurer']) + (int) !empty($a['deputy_secretary']);
    }

    private static function text(mixed $v, int $max): string
    {
        $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($v) ? (string) $v : '') ?? '');
        return mb_substr($s, 0, $max);
    }

    private static function int(mixed $v, int $min, int $max): int
    {
        $n = is_numeric($v) ? (int) round((float) $v) : $min;
        return max($min, min($max, $n));
    }

    /** @param array<string, mixed> $options */
    private static function choice(mixed $v, array $options, string $default): string
    {
        $v = is_scalar($v) ? (string) $v : '';
        return array_key_exists($v, $options) ? $v : $default;
    }

    /** @param list<int> $options */
    private static function intChoice(mixed $v, array $options, int $default): int
    {
        $n = is_numeric($v) ? (int) $v : $default;
        return in_array($n, $options, true) ? $n : $default;
    }
}
