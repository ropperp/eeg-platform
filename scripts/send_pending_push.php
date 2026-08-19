<?php

declare(strict_types=1);

/**
 * scripts/send_pending_push.php — leert push_notifications_queue über APNs (siehe Push.php für
 * die eigentliche Zustell-Logik; Datenbank-Trigger in database/migrate_20260903.sql füllen die
 * Warteschlange, machen selbst aber keine Netzwerk-Aufrufe).
 *
 * Aufruf identisch zu scripts/eda_auto_import.php -- vom Host per Cron, Ausführung IM
 * webapp-Container (dort liegen die APNs-Zugangsdaten/DB-Zugang):
 *   docker compose exec -T webapp php < scripts/send_pending_push.php
 *
 * Push-Benachrichtigungen sollen sich "sofort" anfühlen (neue Rechnung, Einspeisung-Schwelle,
 * neues Postfach-Element) -- deshalb häufiger als der EDA-Import, z.B. jede Minute:
 *   * * * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/send_pending_push.php >> /var/log/eeg-push.log 2>&1
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';
require '/var/www/html/src/Push.php';

$result = Push::sendPending();
if (($result['sent'] ?? 0) > 0 || ($result['failed'] ?? 0) > 0 || ($result['skipped'] ?? 0) > 0) {
    fwrite(STDERR, '[send_pending_push] gesendet=' . $result['sent']
        . ' fehlgeschlagen=' . $result['failed'] . ' übersprungen=' . $result['skipped'] . "\n");
}
