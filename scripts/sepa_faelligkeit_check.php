<?php

declare(strict_types=1);

/**
 * scripts/sepa_faelligkeit_check.php — erinnert den Obmann/die Manager einer EEG per E-Mail,
 * sobald bei einem freigegebenen Abrechnungslauf die SEPA-Vorabinformationsfrist
 * (communities.sepa_prenotification_days, Standard 14 Tage ab Freigabe) abgelaufen ist und die
 * Sammellastschrift-XML noch nicht heruntergeladen wurde -- Patrick, 26.09.2026: "möchte gerne
 * per E-Mail verständigt werden, wenn die Pre-Notification-Zeit vorbei ist [...] damit ich jetzt
 * die SEPA-Lastschrift-XML-Datei bei der Sparkasse hochladen und somit das Geld von meinen
 * Mitgliedern einfordern kann."
 *
 * Aufruf wie die übrigen Cron-Skripte, im webapp-Container (Cron-Eintrag siehe CLAUDE.md):
 *   docker compose exec -T webapp php < scripts/sepa_faelligkeit_check.php
 *
 * Läuft über ALLE Communities (kein aktiver Mandant beim Cron-Aufruf, anders als bei einer
 * normalen Web-Anfrage) -- DB::setCommunity() wird pro Community innerhalb der Schleife gesetzt,
 * weil billing_runs/invoices Row-Level-Security-geschützt sind (siehe DB.php).
 *
 * Verschickt höchstens EINE Erinnerung je Abrechnungslauf (billing_runs.
 * sepa_faelligkeit_erinnerung_gesendet_at) -- ein täglicher Cron-Lauf soll nicht jeden Tag erneut
 * mailen. Läuft die Sammellastschrift-XML einmal herunter (/portal/billing/:id/sepa-xml, setzt
 * billing_runs.sepa_xml_heruntergeladen_at), gilt der Lauf als erledigt und wird gar nicht mehr
 * geprüft.
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';
require '/var/www/html/src/Mailer.php';

$communities = DB::fetchAll('SELECT id, name, sepa_prenotification_days FROM communities');

$gesendet = 0;
foreach ($communities as $c) {
    DB::setCommunity($c['id']);
    $days = (int)($c['sepa_prenotification_days'] ?? 14);

    $runs = DB::fetchAll(
        "SELECT id, quartal, released_at
           FROM billing_runs
          WHERE community_id = ? AND status = 'done'
            AND sepa_xml_heruntergeladen_at IS NULL
            AND sepa_faelligkeit_erinnerung_gesendet_at IS NULL
            AND released_at IS NOT NULL
            AND released_at <= now() - make_interval(days => ?)",
        [$c['id'], $days]
    );

    foreach ($runs as $run) {
        // Nur erinnern, wenn es in diesem Lauf überhaupt etwas einzuziehen gibt -- ein reiner
        // Gutschriften-Lauf (nur negative Salden) braucht keine SEPA-Lastschrift und soll nicht
        // dauerhaft als "erinnerungswürdig" hängen bleiben.
        $hatLastschriften = DB::fetchOne(
            'SELECT 1 FROM invoices WHERE billing_run_id = ? AND saldo_eur > 0 LIMIT 1',
            [$run['id']]
        );
        if (!$hatLastschriften) {
            DB::execute('UPDATE billing_runs SET sepa_faelligkeit_erinnerung_gesendet_at = now() WHERE id = ?', [$run['id']]);
            continue;
        }

        $recipients = DB::fetchAll(
            "SELECT DISTINCT u.email
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
              WHERE ur.community_id = ? AND ur.role = 'manager' AND u.active = true",
            [$c['id']]
        );
        $recipients = array_values(array_filter(array_map(fn($r) => trim((string)($r['email'] ?? '')), $recipients)));
        if (!$recipients) {
            fwrite(STDERR, "[{$c['name']}] Lauf {$run['quartal']}: kein aktiver Obmann/Manager mit E-Mail gefunden.\n");
            continue;
        }

        $subject = 'SEPA-Lastschrift fällig: Abrechnungslauf ' . $run['quartal'] . ' – ' . $c['name'];
        $body =
            '<p>Die SEPA-Vorabinformationsfrist (' . $days . ' Tage) für den Abrechnungslauf '
            . '<strong>' . htmlspecialchars($run['quartal']) . '</strong> ist abgelaufen.</p>'
            . '<p>Sie können die Sammellastschrift-Datei jetzt in der Plattform herunterladen und '
            . 'bei Ihrer Bank einreichen: Abrechnung &rarr; „SEPA-XML" beim betreffenden Lauf.</p>';

        $ok = 0;
        foreach ($recipients as $to) {
            try {
                Mailer::send($to, $subject, $body);
                $ok++;
            } catch (\Throwable $e) {
                fwrite(STDERR, "[{$c['name']}] Mail an {$to} fehlgeschlagen: " . $e->getMessage() . "\n");
            }
        }
        if ($ok > 0) {
            DB::execute('UPDATE billing_runs SET sepa_faelligkeit_erinnerung_gesendet_at = now() WHERE id = ?', [$run['id']]);
            $gesendet++;
            fwrite(STDERR, "[{$c['name']}] Erinnerung für Lauf {$run['quartal']} an {$ok} Empfänger gesendet.\n");
        }
    }
}

fwrite(STDERR, "Fertig -- {$gesendet} Erinnerung(en) versendet.\n");
