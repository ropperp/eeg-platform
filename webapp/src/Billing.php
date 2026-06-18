<?php

declare(strict_types=1);

/**
 * Abrechnungslogik.
 * 60-Tage-Korrekturfenster ist hartcodiert — kein Override möglich.
 * Tarife und Steuern kommen immer aus der DB (historisiert).
 */
class Billing
{
    /**
     * Erstellt oder aktualisiert einen Abrechnungslauf für ein Quartal.
     * Prüft automatisch ob alle Voraussetzungen erfüllt sind.
     */
    public static function getOrCreateRun(string $communityId, string $quartal): array
    {
        DB::setCommunity($communityId);

        $existing = DB::fetchOne(
            'SELECT * FROM billing_runs WHERE community_id = $1 AND quartal = $2',
            [$communityId, $quartal]
        );
        if ($existing) return $existing;

        [$periodFrom, $periodTo, $freigabeNach] = self::quarterDates($quartal);

        DB::execute(
            'INSERT INTO billing_runs (community_id, quartal, period_from, period_to, freigabe_nach, status)
             VALUES ($1, $2, $3, $4, $5, $6)',
            [$communityId, $quartal, $periodFrom, $periodTo, $freigabeNach, 'pending']
        );

        return DB::fetchOne(
            'SELECT * FROM billing_runs WHERE community_id = $1 AND quartal = $2',
            [$communityId, $quartal]
        );
    }

    /**
     * Gibt den Abrechnungslauf frei.
     * Wirft Exception wenn 60-Tage-Fenster noch nicht abgelaufen ist.
     */
    public static function release(string $billingRunId, string $releasedByUserId): void
    {
        $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = $1', [$billingRunId]);
        if (!$run) throw new RuntimeException('Abrechnungslauf nicht gefunden');
        if ($run['status'] !== 'ready') throw new RuntimeException('Abrechnung ist noch nicht bereit');

        // 60-Tage-Sperre — hardcodiert, kein Override
        $freigabeNach = new DateTimeImmutable($run['freigabe_nach']);
        if (new DateTimeImmutable() < $freigabeNach) {
            throw new RuntimeException(
                '60-Tage-Korrekturfenster noch nicht abgelaufen. ' .
                'Freigabe frühestens möglich ab ' . $freigabeNach->format('d.m.Y') . '.'
            );
        }

        DB::setCommunity($run['community_id']);
        DB::beginTransaction();

        try {
            $members = DB::fetchAll(
                'SELECT m.*, mp.id AS mp_id, mp.type AS mp_type, mp.zaehlpunkt_nr
                 FROM members m
                 JOIN metering_points mp ON mp.member_id = m.id AND mp.community_id = m.community_id
                 WHERE m.community_id = $1 AND m.status = $2',
                [$run['community_id'], 'active']
            );

            // Aktuell gültige Tarife für den Abrechnungszeitraum laden
            $tariff = self::getTariffForPeriod($run['community_id'], $run['period_from']);
            $tax    = self::getTaxForPeriod($run['community_id'], $run['period_from']);

            // Fortlaufende Rechnungsnummer
            $community = DB::fetchOne('SELECT marktpartner_id FROM communities WHERE id = $1', [$run['community_id']]);
            $prefix    = ($community['marktpartner_id'] ?? 'EEG') . '-' . $run['quartal'] . '-';

            $invoiceSeq = 1;

            // Mitglieder gruppieren (ein Mitglied kann mehrere Zählpunkte haben)
            $memberGroups = [];
            foreach ($members as $row) {
                $mid = $row['id'];
                $memberGroups[$mid]['member'] = $row;
                $memberGroups[$mid]['metering_points'][] = $row;
            }

            foreach ($memberGroups as $mid => $group) {
                $member = $group['member'];
                $items  = [];
                $saldo  = 0.0;

                foreach ($group['metering_points'] as $mp) {
                    // EDA-Daten aus Abrechnungszeitraum summieren
                    $energyData = DB::fetchOne(
                        'SELECT
                            SUM(kwh_teilnahme)   AS kwh_bezug,
                            SUM(kwh_erzeugung)   AS kwh_einspeisung
                         FROM eda_measurements
                         WHERE community_id = $1 AND metering_point_id = $2
                           AND time >= $3 AND time < $4::date + INTERVAL \'1 day\'
                           AND quality IN (\'L2\', \'L3\')',
                        [$run['community_id'], $mp['mp_id'], $run['period_from'], $run['period_to']]
                    );

                    if (in_array($mp['mp_type'], ['consumer', 'prosumer']) && $energyData['kwh_bezug'] > 0) {
                        $amount = round((float)$energyData['kwh_bezug'] * (float)$tariff['bezug_ct_kwh'] / 100, 2);
                        $items[] = ['type' => 'bezug', 'kwh' => $energyData['kwh_bezug'],
                                    'rate_ct_kwh' => $tariff['bezug_ct_kwh'], 'amount_eur' => $amount];
                        $saldo += $amount;
                    }

                    if (in_array($mp['mp_type'], ['producer', 'prosumer']) && $energyData['kwh_einspeisung'] > 0) {
                        $gutschrift = round((float)$energyData['kwh_einspeisung'] * (float)$tariff['einspeisung_ct_kwh'] / 100, 2);
                        $items[] = ['type' => 'einspeisung', 'kwh' => $energyData['kwh_einspeisung'],
                                    'rate_ct_kwh' => $tariff['einspeisung_ct_kwh'], 'amount_eur' => -$gutschrift];
                        $saldo -= $gutschrift;
                    }
                }

                // Mitgliedsbeitrag (anteilig bei Neumitgliedern — vereinfacht: 3 Monate)
                $beitrag = round((float)$tariff['mitgliedsbeitrag_eur'] / 4, 2); // Quartalsanteil
                $items[] = ['type' => 'mitgliedsbeitrag', 'kwh' => null, 'rate_ct_kwh' => null,
                             'months' => 3, 'amount_eur' => $beitrag];
                $saldo += $beitrag;

                $rechnungsnummer = $prefix . str_pad((string)$invoiceSeq++, 3, '0', STR_PAD_LEFT);

                DB::execute(
                    'INSERT INTO invoices (billing_run_id, community_id, member_id, rechnungsnummer, saldo_eur)
                     VALUES ($1, $2, $3, $4, $5)',
                    [$billingRunId, $run['community_id'], $mid, $rechnungsnummer, $saldo]
                );

                $invoiceId = DB::fetchOne('SELECT id FROM invoices WHERE rechnungsnummer = $1', [$rechnungsnummer])['id'];

                foreach ($items as $item) {
                    DB::execute(
                        'INSERT INTO invoice_items (invoice_id, type, kwh, rate_ct_kwh, months, amount_eur)
                         VALUES ($1, $2, $3, $4, $5, $6)',
                        [$invoiceId, $item['type'], $item['kwh'] ?? null, $item['rate_ct_kwh'] ?? null,
                         $item['months'] ?? null, $item['amount_eur']]
                    );
                }

                // PDF generieren
                $pdf = self::generatePdf($invoiceId, $member, $items, $saldo, $tariff, $tax, $community, $run);
                if ($pdf) {
                    DB::execute('UPDATE invoices SET pdf_path = $1 WHERE id = $2', [$pdf, $invoiceId]);
                }
            }

            DB::execute(
                'UPDATE billing_runs SET status = $1, released_by = $2, released_at = now() WHERE id = $3',
                ['done', $releasedByUserId, $billingRunId]
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }
    }

    private static function generatePdf(
        string $invoiceId, array $member, array $items, float $saldo,
        array $tariff, array $tax, array $community, array $run
    ): ?string {
        $latexUrl = getenv('LATEX_SERVICE_URL') . '/generate';
        $apiKey   = getenv('LATEX_API_KEY');

        $steuerHinweis = $tax['tax_model'] === 'kleinunternehmer'
            ? 'Gem. § 6 Abs 1 Z 27 UStG wird keine Umsatzsteuer ausgewiesen.'
            : '';
        $steuerBetrag  = $tax['tax_model'] === 'standard'
            ? round($saldo * (float)$tax['tax_rate_percent'] / 100, 2)
            : 0;
        $summeBrutto = $saldo + $steuerBetrag;

        $bezugItem     = collect_item($items, 'bezug');
        $einspeisItem  = collect_item($items, 'einspeisung');
        $beitragItem   = collect_item($items, 'mitgliedsbeitrag');

        $variables = [
            'EEG_NAME'             => $community['name'] ?? '',
            'EEG_ADRESSE'          => $community['address'] ?? '',
            'EEG_UID'              => $community['uid_number'] ?? '',
            'RECHNUNGSNUMMER'      => 'n/a', // wird von caller gesetzt
            'RECHNUNGSDATUM'       => date('d.m.Y'),
            'ABRECHNUNGSZEITRAUM'  => $run['period_from'] . ' – ' . $run['period_to'],
            'MITGLIED_NAME'        => ($member['invoice_name'] ?? '') ?: trim($member['first_name'] . ' ' . $member['last_name']),
            'MITGLIED_ADRESSE'     => $member['address'] . ', ' . $member['zip'] . ' ' . $member['city'],
            'MITGLIED_UID'         => $member['invoice_uid'] ?? '',
            'BEZUG_KWH'            => $bezugItem ? number_format((float)$bezugItem['kwh'], 3, ',', '.') : '0,000',
            'BEZUG_TARIF'          => $bezugItem ? number_format((float)$bezugItem['rate_ct_kwh'], 4, ',', '.') : '0,0000',
            'BEZUG_BETRAG'         => $bezugItem ? number_format((float)$bezugItem['amount_eur'], 2, ',', '.') : '0,00',
            'EINSPEISUNG_KWH'      => $einspeisItem ? number_format(abs((float)$einspeisItem['kwh']), 3, ',', '.') : '0,000',
            'EINSPEISUNG_TARIF'    => $einspeisItem ? number_format((float)$einspeisItem['rate_ct_kwh'], 4, ',', '.') : '0,0000',
            'EINSPEISUNG_BETRAG'   => $einspeisItem ? number_format(abs((float)$einspeisItem['amount_eur']), 2, ',', '.') : '0,00',
            'MITGLIEDSBEITRAG'     => $beitragItem ? number_format((float)$beitragItem['amount_eur'], 2, ',', '.') : '0,00',
            'SUMME_NETTO'          => number_format($saldo, 2, ',', '.'),
            'STEUER_HINWEIS'       => $steuerHinweis,
            'STEUER_BETRAG'        => number_format($steuerBetrag, 2, ',', '.'),
            'SUMME_BRUTTO'         => number_format($summeBrutto, 2, ',', '.'),
            'IBAN'                 => $community['iban'] ?? '',
            'BIC'                  => $community['bic'] ?? '',
            'ZAHLUNGSZIEL'         => ($community['payment_days'] ?? 14) . ' Tage',
        ];

        $ch = curl_init($latexUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['template' => 'rechnung', 'variables' => $variables]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: ' . $apiKey],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !$response) return null;

        $data    = json_decode($response, true);
        $pdfB64  = $data['pdf'] ?? null;
        if (!$pdfB64) return null;

        $path = '/var/www/html/storage/pdfs/' . $invoiceId . '.pdf';
        file_put_contents($path, base64_decode($pdfB64));
        return $path;
    }

    private static function getTariffForPeriod(string $communityId, string $date): array
    {
        $row = DB::fetchOne(
            'SELECT * FROM tariff_config WHERE community_id = $1 AND valid_from <= $2
             ORDER BY valid_from DESC LIMIT 1',
            [$communityId, $date]
        );
        if (!$row) throw new RuntimeException('Kein Tarif für diesen Zeitraum konfiguriert');
        return $row;
    }

    private static function getTaxForPeriod(string $communityId, string $date): array
    {
        $row = DB::fetchOne(
            'SELECT * FROM tax_config WHERE community_id = $1 AND valid_from <= $2
             ORDER BY valid_from DESC LIMIT 1',
            [$communityId, $date]
        );
        if (!$row) throw new RuntimeException('Keine Steuerkonfiguration für diesen Zeitraum');
        return $row;
    }

    private static function quarterDates(string $quartal): array
    {
        [$year, $q] = explode('-Q', $quartal);
        $starts = ['1' => '01-01', '2' => '04-01', '3' => '07-01', '4' => '10-01'];
        $ends   = ['1' => '03-31', '2' => '06-30', '3' => '09-30', '4' => '12-31'];
        $from   = $year . '-' . $starts[$q];
        $to     = $year . '-' . $ends[$q];
        $freigabe = date('Y-m-d', strtotime($to . ' +60 days'));
        return [$from, $to, $freigabe];
    }
}

function collect_item(array $items, string $type): ?array
{
    foreach ($items as $item) {
        if ($item['type'] === $type) return $item;
    }
    return null;
}
