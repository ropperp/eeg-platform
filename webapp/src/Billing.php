<?php

declare(strict_types=1);

/**
 * Abrechnungslogik.
 * 60-Tage-Korrekturfenster ist hartcodiert — kein Override möglich.
 * Basis: ausschließlich eda_measurements (Quality L2/L3).
 */
class Billing
{
    /**
     * Erstellt einen neuen Abrechnungslauf für den angegebenen Zeitraum.
     * Gibt einen bestehenden Lauf zurück wenn period_from+period_to bereits existiert.
     */
    public static function getOrCreateRun(string $communityId, string $periodFrom, string $periodTo): array
    {
        DB::setCommunity($communityId);

        $existing = DB::fetchOne(
            'SELECT * FROM billing_runs WHERE community_id = ? AND period_from = ?::date AND period_to = ?::date',
            [$communityId, $periodFrom, $periodTo]
        );
        if ($existing) return $existing;

        $quartal      = self::deriveQuartal($periodFrom, $periodTo);
        $freigabeNach = date('Y-m-d', strtotime($periodTo . ' +60 days'));

        DB::execute(
            'INSERT INTO billing_runs (community_id, quartal, period_from, period_to, freigabe_nach, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$communityId, $quartal, $periodFrom, $periodTo, $freigabeNach, 'pending']
        );

        return DB::fetchOne(
            'SELECT * FROM billing_runs WHERE community_id = ? AND period_from = ?::date AND period_to = ?::date',
            [$communityId, $periodFrom, $periodTo]
        );
    }

    /**
     * Berechnet Rechnungen für einen Abrechnungslauf.
     * Idempotent: bestehende Rechnungen werden zuerst gelöscht.
     * Setzt Status auf 'ready'. Gibt Warnungen als String-Array zurück.
     */
    public static function compute(string $billingRunId): array
    {
        $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ?', [$billingRunId]);
        if (!$run) throw new RuntimeException('Abrechnungslauf nicht gefunden');
        if (!in_array($run['status'], ['pending', 'ready'])) {
            throw new RuntimeException('Abrechnung bereits freigegeben — kein Neuberechnen möglich');
        }

        DB::setCommunity($run['community_id']);
        $tariff    = self::getTariffForPeriod($run['community_id'], $run['period_from']);
        $tax       = self::getTaxForPeriod($run['community_id'], $run['period_from']);
        $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$run['community_id']]);

        $warnings = [];

        DB::beginTransaction();
        try {
            // Idempotenz: bestehende PDFs + Rechnungszeilen löschen
            $oldInvoices = DB::fetchAll(
                'SELECT id, pdf_path FROM invoices WHERE billing_run_id = ?', [$billingRunId]
            );
            foreach ($oldInvoices as $old) {
                if (!empty($old['pdf_path']) && file_exists($old['pdf_path'])) {
                    @unlink($old['pdf_path']);
                }
            }
            DB::execute(
                'DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE billing_run_id = ?)',
                [$billingRunId]
            );
            DB::execute('DELETE FROM invoices WHERE billing_run_id = ?', [$billingRunId]);

            $members = DB::fetchAll(
                'SELECT * FROM members WHERE community_id = ? AND status = ? ORDER BY last_name, first_name, id',
                [$run['community_id'], 'active']
            );

            $dt1        = new DateTimeImmutable($run['period_from']);
            $dt2        = new DateTimeImmutable($run['period_to']);
            $periodDays = (int)$dt1->diff($dt2)->days + 1;
            $prefix     = ($community['marktpartner_id'] ?? 'EEG') . '-' . $run['quartal'] . '-';
            $invoiceSeq = 1;

            foreach ($members as $member) {
                $mps = DB::fetchAll(
                    'SELECT * FROM metering_points WHERE community_id = ? AND member_id = ? AND active = true',
                    [$run['community_id'], $member['id']]
                );

                $items = [];
                $saldo = 0.0;

                foreach ($mps as $mp) {
                    $energy = DB::fetchOne(
                        'SELECT COALESCE(SUM(kwh_teilnahme), 0) AS kwh_bezug,
                                COALESCE(SUM(kwh_erzeugung), 0) AS kwh_einsp
                         FROM eda_measurements
                         WHERE community_id = ? AND metering_point_id = ?
                           AND time >= ?::date AND time <= ?::date
                           AND quality IN (\'L2\', \'L3\')',
                        [$run['community_id'], $mp['id'], $run['period_from'], $run['period_to']]
                    );

                    if (in_array($mp['type'], ['consumer', 'prosumer']) && $energy['kwh_bezug'] > 0) {
                        $amount  = round((float)$energy['kwh_bezug'] * (float)$tariff['bezug_ct_kwh'] / 100, 2);
                        $items[] = [
                            'type'        => 'bezug',
                            'kwh'         => (float)$energy['kwh_bezug'],
                            'rate_ct_kwh' => (float)$tariff['bezug_ct_kwh'],
                            'amount_eur'  => $amount,
                        ];
                        $saldo += $amount;
                    }

                    if (in_array($mp['type'], ['producer', 'prosumer']) && $energy['kwh_einsp'] > 0) {
                        $gutschrift = round((float)$energy['kwh_einsp'] * (float)$tariff['einspeisung_ct_kwh'] / 100, 2);
                        $items[]    = [
                            'type'        => 'einspeisung',
                            'kwh'         => (float)$energy['kwh_einsp'],
                            'rate_ct_kwh' => (float)$tariff['einspeisung_ct_kwh'],
                            'amount_eur'  => -$gutschrift,
                        ];
                        $saldo -= $gutschrift;
                    }
                }

                // Mitgliedsbeitrag anteilig nach Tagen
                $beitrag = round((float)$tariff['mitgliedsbeitrag_eur'] * $periodDays / 365, 2);
                $months  = round($periodDays / 365 * 12, 2);
                $items[] = [
                    'type'        => 'mitgliedsbeitrag',
                    'kwh'         => null,
                    'rate_ct_kwh' => null,
                    'months'      => $months,
                    'amount_eur'  => $beitrag,
                ];
                $saldo += $beitrag;

                $rechnungsnummer = $prefix . str_pad((string)$invoiceSeq++, 3, '0', STR_PAD_LEFT);

                DB::execute(
                    'INSERT INTO invoices (billing_run_id, community_id, member_id, rechnungsnummer, saldo_eur)
                     VALUES (?, ?, ?, ?, ?)',
                    [$billingRunId, $run['community_id'], $member['id'], $rechnungsnummer, $saldo]
                );

                $invoiceId = DB::fetchOne(
                    'SELECT id FROM invoices WHERE rechnungsnummer = ?', [$rechnungsnummer]
                )['id'];

                foreach ($items as $item) {
                    DB::execute(
                        'INSERT INTO invoice_items (invoice_id, type, kwh, rate_ct_kwh, months, amount_eur)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [
                            $invoiceId,
                            $item['type'],
                            $item['kwh']         ?? null,
                            $item['rate_ct_kwh'] ?? null,
                            $item['months']      ?? null,
                            $item['amount_eur'],
                        ]
                    );
                }

                $pdf = self::generateInvoicePdf(
                    $invoiceId, $rechnungsnummer, $member, $items, $saldo, $tariff, $tax, $community, $run
                );
                if ($pdf) {
                    DB::execute('UPDATE invoices SET pdf_path = ? WHERE id = ?', [$pdf, $invoiceId]);
                } else {
                    $name       = ($member['invoice_name'] ?? '') ?: trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                    $warnings[] = 'PDF für ' . $name . ' konnte nicht erstellt werden.';
                }
            }

            DB::execute('UPDATE billing_runs SET status = ? WHERE id = ?', ['ready', $billingRunId]);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        return $warnings;
    }

    /**
     * Gibt einen fertigen Abrechnungslauf frei (ready → done).
     * Wirft Exception wenn 60-Tage-Fenster noch nicht abgelaufen ist.
     */
    public static function release(string $billingRunId, string $releasedByUserId): void
    {
        $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ?', [$billingRunId]);
        if (!$run) throw new RuntimeException('Abrechnungslauf nicht gefunden');
        if ($run['status'] !== 'ready') {
            throw new RuntimeException('Abrechnung muss zuerst berechnet werden (Status muss "ready" sein)');
        }

        $freigabeNach = new DateTimeImmutable($run['freigabe_nach']);
        if (new DateTimeImmutable() < $freigabeNach) {
            throw new RuntimeException(
                '60-Tage-Korrekturfenster noch nicht abgelaufen. ' .
                'Freigabe frühestens möglich ab ' . $freigabeNach->format('d.m.Y') . '.'
            );
        }

        DB::setCommunity($run['community_id']);
        DB::execute(
            'UPDATE billing_runs SET status = ?, released_by = ?, released_at = now() WHERE id = ?',
            ['done', $releasedByUserId, $billingRunId]
        );
    }

    private static function generateInvoicePdf(
        string $invoiceId,
        string $rechnungsnummer,
        array  $member,
        array  $items,
        float  $saldo,
        array  $tariff,
        array  $tax,
        array  $community,
        array  $run
    ): ?string {
        $latexUrl = rtrim(getenv('LATEX_SERVICE_URL') ?: 'http://latex-service:3210', '/') . '/generate';
        $apiKey   = getenv('LATEX_API_KEY') ?: '';

        $bezugItem    = billing_item_find($items, 'bezug');
        $einspeisItem = billing_item_find($items, 'einspeisung');
        $beitragItem  = billing_item_find($items, 'mitgliedsbeitrag');

        $steuerBetrag = 0.0;
        if ($tax['tax_model'] === 'standard') {
            $steuerBetrag = round($saldo * (float)$tax['tax_rate_percent'] / 100, 2);
        }
        $summeBrutto = $saldo + $steuerBetrag;

        // RAW_STEUER_ZEILE darf nie leer sein — leere Zeile = LaTeX-Fehler in tabularx
        if ($tax['tax_model'] === 'kleinunternehmer') {
            $steuerZeile = '\multicolumn{5}{l}{\footnotesize Gem.~\S{}~6~Abs.~1~Z~27~UStG~wird~keine~Umsatzsteuer~ausgewiesen.} \\\\';
        } else {
            $rate        = number_format((float)($tax['tax_rate_percent'] ?? 0), 0, ',', '.');
            $betragFmt   = number_format($steuerBetrag, 2, ',', '.');
            $steuerZeile = 'Umsatzsteuer ' . $rate . '\,\% & & & & \EUR{' . $betragFmt . '} \\\\';
        }

        $mitgliedName    = ($member['invoice_name'] ?? '') ?: trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        $adressParts     = array_filter([
            $member['address'] ?? '',
            trim(($member['zip'] ?? '') . ' ' . ($member['city'] ?? '')),
        ]);
        $mitgliedAdresse = implode(', ', $adressParts);

        $vars = [
            'EEG_NAME'            => $community['name']          ?? '',
            'EEG_ADRESSE'         => $community['address']       ?? '',
            'EEG_UID'             => $community['uid_number']    ?? '',
            'RECHNUNGSNUMMER'     => $rechnungsnummer,
            'RECHNUNGSDATUM'      => date('d.m.Y'),
            'ABRECHNUNGSZEITRAUM' => date('d.m.Y', strtotime($run['period_from'])) . ' -- ' . date('d.m.Y', strtotime($run['period_to'])),
            'MITGLIED_NAME'       => $mitgliedName,
            'MITGLIED_ADRESSE'    => $mitgliedAdresse,
            'MITGLIED_UID'        => $member['invoice_uid']      ?? '',
            'BEZUG_KWH'           => $bezugItem    ? number_format((float)$bezugItem['kwh'],              3, ',', '.') : '0,000',
            'BEZUG_TARIF'         => $bezugItem    ? number_format((float)$bezugItem['rate_ct_kwh'],      4, ',', '.') : '0,0000',
            'BEZUG_BETRAG'        => $bezugItem    ? number_format((float)$bezugItem['amount_eur'],       2, ',', '.') : '0,00',
            'EINSPEISUNG_KWH'     => $einspeisItem ? number_format((float)$einspeisItem['kwh'],          3, ',', '.') : '0,000',
            'EINSPEISUNG_TARIF'   => $einspeisItem ? number_format((float)$einspeisItem['rate_ct_kwh'],  4, ',', '.') : '0,0000',
            'EINSPEISUNG_BETRAG'  => $einspeisItem ? number_format(abs((float)$einspeisItem['amount_eur']), 2, ',', '.') : '0,00',
            'MITGLIEDSBEITRAG'    => $beitragItem  ? number_format((float)$beitragItem['amount_eur'],    2, ',', '.') : '0,00',
            'SUMME_NETTO'         => number_format($saldo,       2, ',', '.'),
            'SUMME_BRUTTO'        => number_format($summeBrutto, 2, ',', '.'),
            'RAW_STEUER_ZEILE'    => $steuerZeile,
            'IBAN'                => $community['iban']          ?? '',
            'BIC'                 => $community['bic']           ?? '',
            'ZAHLUNGSZIEL'        => ($community['payment_days'] ?? 14) . ' Tage',
        ];

        // FIX: payload key ist 'vars', nicht 'variables'
        $body    = json_encode(['template' => 'rechnung', 'vars' => $vars], JSON_UNESCAPED_UNICODE);
        $headers = "Content-Type: application/json\r\n";
        if ($apiKey !== '') $headers .= "X-Api-Key: $apiKey\r\n";

        $ctx      = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => $headers,
            'content'       => $body,
            'timeout'       => 30,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($latexUrl, false, $ctx);

        // FIX: service liefert rohes PDF-Binary, kein JSON mit base64
        if ($response === false || !str_starts_with($response, '%PDF')) return null;

        $dir = '/var/www/html/storage/pdfs';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $path = $dir . '/' . $invoiceId . '.pdf';
        file_put_contents($path, $response);
        return $path;
    }

    private static function deriveQuartal(string $periodFrom, string $periodTo): string
    {
        $from = new DateTimeImmutable($periodFrom);
        $to   = new DateTimeImmutable($periodTo);

        if ($from->format('Y') === $to->format('Y')) {
            $year     = $from->format('Y');
            $quarters = [
                '1' => ['01-01', '03-31'],
                '2' => ['04-01', '06-30'],
                '3' => ['07-01', '09-30'],
                '4' => ['10-01', '12-31'],
            ];
            foreach ($quarters as $q => [$qFrom, $qTo]) {
                if ($from->format('m-d') === $qFrom && $to->format('m-d') === $qTo) {
                    return $year . '-Q' . $q;
                }
            }
            return $year . '-' . $from->format('m');
        }

        return $from->format('Y') . '-' . $from->format('m');
    }

    private static function getTariffForPeriod(string $communityId, string $date): array
    {
        $row = DB::fetchOne(
            'SELECT * FROM tariff_config WHERE community_id = ? AND valid_from <= ?
             ORDER BY valid_from DESC LIMIT 1',
            [$communityId, $date]
        );
        if (!$row) throw new RuntimeException('Kein Tarif für diesen Zeitraum konfiguriert');
        return $row;
    }

    private static function getTaxForPeriod(string $communityId, string $date): array
    {
        $row = DB::fetchOne(
            'SELECT * FROM tax_config WHERE community_id = ? AND valid_from <= ?
             ORDER BY valid_from DESC LIMIT 1',
            [$communityId, $date]
        );
        if (!$row) throw new RuntimeException('Keine Steuerkonfiguration für diesen Zeitraum');
        return $row;
    }
}

function billing_item_find(array $items, string $type): ?array
{
    foreach ($items as $item) {
        if ($item['type'] === $type) return $item;
    }
    return null;
}
