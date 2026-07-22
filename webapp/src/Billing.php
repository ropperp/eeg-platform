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
            'SELECT * FROM billing_runs WHERE community_id = ? AND quartal = ?',
            [$communityId, $quartal]
        );
        if ($existing) return $existing;

        [$periodFrom, $periodTo, $freigabeNach] = self::quarterDates($quartal);

        DB::execute(
            'INSERT INTO billing_runs (community_id, quartal, period_from, period_to, freigabe_nach, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$communityId, $quartal, $periodFrom, $periodTo, $freigabeNach, 'pending']
        );

        return DB::fetchOne(
            'SELECT * FROM billing_runs WHERE community_id = ? AND quartal = ?',
            [$communityId, $quartal]
        );
    }

    /**
     * Erzeugt (bzw. erneuert) die Rechnungs-Entwürfe eines Abrechnungslaufs aus den EDA-Daten
     * und setzt den Lauf auf 'ready'. KEIN 60-Tage-Check -- Entwürfe dürfen jederzeit berechnet
     * und danach pro Rechnung manuell nachbearbeitet werden (siehe
     * /portal/billing/invoices/:id/edit), bevor der Lauf endgültig freigegeben wird (finalize()).
     * Idempotent: bereits vorhandene Entwürfe dieses Laufs werden vorher verworfen und neu
     * erzeugt (invoice_items hängen per ON DELETE CASCADE an invoices).
     */
    public static function generateDrafts(string $billingRunId): void
    {
        $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ?', [$billingRunId]);
        if (!$run) throw new RuntimeException('Abrechnungslauf nicht gefunden');
        if (!in_array($run['status'], ['pending', 'ready'], true)) {
            throw new RuntimeException('Abrechnung wurde bereits freigegeben und kann nicht neu berechnet werden.');
        }

        DB::setCommunity($run['community_id']);
        DB::beginTransaction();

        try {
            // Bestehende Entwürfe dieses Laufs verwerfen (Neuberechnung überschreibt evtl.
            // manuelle Änderungen -- bewusst, "Neu berechnen" = frisch aus den EDA-Daten).
            DB::execute('DELETE FROM invoices WHERE billing_run_id = ?', [$billingRunId]);

            $members = DB::fetchAll(
                'SELECT m.*, mp.id AS mp_id, mp.type AS mp_type, mp.zaehlpunkt_nr
                 FROM members m
                 JOIN metering_points mp ON mp.member_id = m.id AND mp.community_id = m.community_id
                 WHERE m.community_id = ? AND m.status = ?',
                [$run['community_id'], 'active']
            );

            // Aktuell gültige Tarife für den Abrechnungszeitraum laden
            $tariff = self::getTariffForPeriod($run['community_id'], $run['period_from']);
            $tax    = self::getTaxForPeriod($run['community_id'], $run['period_from']);

            // Fortlaufende Rechnungsnummer
            $community = DB::fetchOne('SELECT * FROM communities WHERE id = ?', [$run['community_id']]);
            $prefix    = ($community['marktpartner_id'] ?? 'EEG') . '-' . $run['quartal'] . '-';

            $invoiceSeq = 1;

            // Manuelle Zusatzpositionen (z.B. einmaliger Rabatt) gelten für alle Rechnungen
            // dieses Laufs -- vom Manager vor der Freigabe über /portal/billing erfasst.
            $extraItems = DB::fetchAll(
                'SELECT * FROM billing_run_extra_items WHERE billing_run_id = ? ORDER BY created_at',
                [$billingRunId]
            );

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
                         WHERE community_id = ? AND metering_point_id = ?
                           AND time >= ? AND time < ?::date + INTERVAL \'1 day\'
                           AND quality IN (\'L2\', \'L3\')',
                        [$run['community_id'], $mp['mp_id'], $run['period_from'], $run['period_to']]
                    );

                    if (in_array($mp['mp_type'], ['consumer', 'prosumer']) && $energyData['kwh_bezug'] > 0) {
                        $amount = round((float)$energyData['kwh_bezug'] * (float)$tariff['bezug_ct_kwh'] / 100, 2);
                        $items[] = ['type' => 'bezug', 'kwh' => $energyData['kwh_bezug'],
                                    'rate_ct_kwh' => $tariff['bezug_ct_kwh'], 'amount_eur' => $amount,
                                    'zaehlpunkt_nr' => $mp['zaehlpunkt_nr']];
                        $saldo += $amount;
                    }

                    if (in_array($mp['mp_type'], ['producer', 'prosumer']) && $energyData['kwh_einspeisung'] > 0) {
                        $gutschrift = round((float)$energyData['kwh_einspeisung'] * (float)$tariff['einspeisung_ct_kwh'] / 100, 2);
                        $items[] = ['type' => 'einspeisung', 'kwh' => $energyData['kwh_einspeisung'],
                                    'rate_ct_kwh' => $tariff['einspeisung_ct_kwh'], 'amount_eur' => -$gutschrift,
                                    'zaehlpunkt_nr' => $mp['zaehlpunkt_nr']];
                        $saldo -= $gutschrift;
                    }
                }

                // Mitgliedsbeitrag anteilig nach tatsächlicher Mitgliedsdauer (siehe
                // Billing::mitgliedsbeitragAnteilig).
                $anteil  = self::mitgliedsbeitragAnteilig(
                    $run['period_from'], $run['period_to'],
                    $member['member_since'], (float)$tariff['mitgliedsbeitrag_eur']
                );
                $items[] = ['type' => 'mitgliedsbeitrag', 'kwh' => null, 'rate_ct_kwh' => null,
                             'months' => $anteil['months'], 'amount_eur' => $anteil['amount']];
                $saldo += $anteil['amount'];

                // Manuelle Zusatzpositionen (Rabatt/Gutschrift o.ä.) gelten für alle Mitglieder
                // dieses Laufs -- 1:1 in jede einzelne Rechnung übernehmen.
                foreach ($extraItems as $extra) {
                    $items[] = ['type' => 'manuell', 'label' => $extra['label'],
                                 'quantity' => $extra['quantity'], 'unit' => $extra['unit'],
                                 'amount_eur' => (float)$extra['amount_eur']];
                    $saldo += (float)$extra['amount_eur'];
                }

                $rechnungsnummer = $prefix . str_pad((string)$invoiceSeq++, 3, '0', STR_PAD_LEFT);

                DB::execute(
                    'INSERT INTO invoices (billing_run_id, community_id, member_id, rechnungsnummer, saldo_eur, pdf_path)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    // pdf_path ist nur ein "existiert"-Marker für die Rechnungslisten (invoices.php/
                    // billing_invoices.php zeigen den Download-Link nur wenn gesetzt) -- die PDF selbst
                    // wird nicht vorgerendert/auf Platte gespeichert, sondern bei jedem Abruf frisch aus
                    // invoice_items generiert (siehe /portal/invoices/:id/pdf). Vorher stand hier ein
                    // eigener, kaputter Render-Aufruf (falscher Request-/Response-Aufbau gegenüber
                    // latex-service), wodurch pdf_path nie gesetzt wurde und der Download-Link für
                    // JEDE über die Abrechnung freigegebene Rechnung dauerhaft fehlte.
                    [$billingRunId, $run['community_id'], $mid, $rechnungsnummer, $saldo, $rechnungsnummer]
                );

                $invoiceId = DB::fetchOne('SELECT id FROM invoices WHERE rechnungsnummer = ?', [$rechnungsnummer])['id'];

                foreach ($items as $item) {
                    DB::execute(
                        'INSERT INTO invoice_items (invoice_id, type, kwh, rate_ct_kwh, months, amount_eur, label, quantity, unit, zaehlpunkt_nr)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$invoiceId, $item['type'], $item['kwh'] ?? null, $item['rate_ct_kwh'] ?? null,
                         $item['months'] ?? null, $item['amount_eur'], $item['label'] ?? null,
                         $item['quantity'] ?? null, $item['unit'] ?? null, $item['zaehlpunkt_nr'] ?? null]
                    );
                }
            }

            DB::execute("UPDATE billing_runs SET status = 'ready' WHERE id = ?", [$billingRunId]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Gibt einen berechneten Abrechnungslauf endgültig frei (Status 'done'). Setzt das
     * 60-Tage-Korrekturfenster hart durch (kein Override) -- vorher müssen die Entwürfe per
     * generateDrafts() erzeugt (Status 'ready') und ggf. manuell nachbearbeitet worden sein.
     */
    public static function finalize(string $billingRunId, string $releasedByUserId): void
    {
        $run = DB::fetchOne('SELECT * FROM billing_runs WHERE id = ?', [$billingRunId]);
        if (!$run) throw new RuntimeException('Abrechnungslauf nicht gefunden');
        if ($run['status'] !== 'ready') {
            throw new RuntimeException('Bitte zuerst die Rechnungen berechnen (Entwurf erstellen), dann freigeben.');
        }
        $freigabeNach = new DateTimeImmutable($run['freigabe_nach']);
        if (new DateTimeImmutable() < $freigabeNach) {
            throw new RuntimeException(
                '60-Tage-Korrekturfenster noch nicht abgelaufen. ' .
                'Freigabe frühestens möglich ab ' . $freigabeNach->format('d.m.Y') . '.'
            );
        }
        DB::execute(
            "UPDATE billing_runs SET status = 'done', released_by = ?, released_at = now() WHERE id = ?",
            [$releasedByUserId, $billingRunId]
        );
    }

    /**
     * Rechnet den gespeicherten Rechnungssaldo neu aus der Summe der invoice_items -- nach
     * einer manuellen Bearbeitung der Positionen (Einzelbearbeitung vor Versand).
     */
    public static function recalcInvoiceSaldo(string $invoiceId): void
    {
        $sum = DB::fetchOne('SELECT COALESCE(SUM(amount_eur), 0) AS s FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);
        DB::execute('UPDATE invoices SET saldo_eur = ? WHERE id = ?', [round((float)$sum['s'], 2), $invoiceId]);
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

    /**
     * Anteiliger Mitgliedsbeitrag nach tatsächlicher Mitgliedsdauer im Abrechnungszeitraum:
     * wer erst unterjährig beitritt, zahlt nur für die Monate, in denen er (zumindest anteilig)
     * Mitglied war. Beispiel bei Quartalsbeitrag 6 € (= 2 €/Monat): Beitritt im 2. Monat des
     * Quartals -> nur 4 €; ganzes Quartal dabei -> volle 6 €. Voller Quartalsbeitrag =
     * Jahresbeitrag/4 = 3 Monate à Jahresbeitrag/12, ein voll dabei gewesenes Mitglied zahlt
     * also exakt wie zuvor. Mitglieder, die im Zeitraum ausgetreten sind, werden gar nicht erst
     * abgerechnet (status='active'-Filter in release()) -- daher nur Beitritts-, keine
     * Austritts-Proration. Reine Funktion (keine DB) -> automatisiert testbar (tests/).
     * @return array{months:int, amount:float}
     */
    public static function mitgliedsbeitragAnteilig(
        string $periodFrom, string $periodTo, string $memberSince, float $jahresbeitrag
    ): array {
        $monatsBeitrag = $jahresbeitrag / 12;
        $memberSinceTs = strtotime($memberSince);
        $aktiveMonate  = 0;
        $cursor = strtotime(date('Y-m-01', strtotime($periodFrom)));
        $endTs  = strtotime($periodTo);
        while ($cursor <= $endTs) {
            // Monat zählt, wenn die Mitgliedschaft spätestens am Monatsende begonnen hat.
            if ($memberSinceTs <= strtotime(date('Y-m-t', $cursor))) {
                $aktiveMonate++;
            }
            $cursor = strtotime('+1 month', $cursor);
        }
        return ['months' => $aktiveMonate, 'amount' => round($aktiveMonate * $monatsBeitrag, 2)];
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
