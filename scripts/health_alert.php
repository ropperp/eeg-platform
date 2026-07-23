<?php

declare(strict_types=1);

/**
 * scripts/health_alert.php — Sendet eine Alarm-E-Mail ans Admin-Postfach, wenn ein Container
 * ungesund (unhealthy) ist bzw. war und (nicht) automatisch neu gestartet werden konnte.
 *
 * Wird von scripts/health_monitor.sh im webapp-Container per stdin ausgeführt:
 *   docker compose exec -T -e ALERT_CONTAINER=... -e ALERT_STATUS=... -e ALERT_ACTION=... \
 *       webapp php < scripts/health_alert.php
 * Nutzt exakt dieselbe Microsoft-Graph-Anbindung (Mailer, Zugangsdaten aus der DB) wie der
 * Rest der Plattform -- kein Image-Rebuild nötig.
 *
 * Empfänger: die in den E-Mail-Einstellungen hinterlegten Alarm-Adressen (backup_alert_email_1/2),
 * optional HEALTH_ALERT_EMAIL, sonst der erste aktive Platform-Admin.
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/DB.php';
require '/var/www/html/src/Mailer.php';

$container = getenv('ALERT_CONTAINER') ?: 'unbekannt';
$status    = getenv('ALERT_STATUS') ?: 'unhealthy';
$action    = getenv('ALERT_ACTION') ?: '';
$host      = getenv('ALERT_HOST') ?: 'eeg-server';
$when      = date('d.m.Y H:i');

$recipients = [];
try {
    $cfg = DB::fetchOne('SELECT backup_alert_email_1, backup_alert_email_2 FROM platform_mail_config WHERE id = 1');
    foreach (['backup_alert_email_1', 'backup_alert_email_2'] as $k) {
        $v = trim((string)($cfg[$k] ?? ''));
        if ($v !== '') $recipients[] = $v;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Konfig-Empfänger nicht lesbar: " . $e->getMessage() . "\n");
}
if ($env = getenv('HEALTH_ALERT_EMAIL')) {
    $recipients[] = $env;
}
if (!$recipients) {
    try {
        $row = DB::fetchOne(
            "SELECT u.email
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
              WHERE ur.role = 'platform_admin' AND u.active = true
              ORDER BY u.created_at
              LIMIT 1"
        );
        if (!empty($row['email'])) $recipients[] = $row['email'];
    } catch (\Throwable $e) {
        fwrite(STDERR, "Empfänger-Ermittlung fehlgeschlagen: " . $e->getMessage() . "\n");
    }
}
$recipients = array_values(array_unique(array_filter(array_map('trim', $recipients))));

if (!$recipients) {
    fwrite(STDERR, "Kein Alarm-Empfänger gefunden (Alarm-Adressen in E-Mail-Einstellungen setzen, HEALTH_ALERT_EMAIL setzen oder Platform-Admin anlegen).\n");
    exit(2);
}

$subject = '⚠️ EEG-Dienst „' . $container . '" ' . $status . ' (' . $host . ')';
$body =
    '<p><strong>Ein Dienst der EEG-Plattform meldet ein Problem.</strong></p>' .
    '<p><strong>Container:</strong> ' . htmlspecialchars($container) . '<br>' .
    '<strong>Status:</strong> ' . htmlspecialchars($status) . '<br>' .
    ($action !== '' ? '<strong>Automatische Maßnahme:</strong> ' . htmlspecialchars($action) . '<br>' : '') .
    '<strong>Zeitpunkt:</strong> ' . htmlspecialchars($when) . '<br>' .
    '<strong>Server:</strong> ' . htmlspecialchars($host) . '</p>' .
    '<p>Prüfen mit <code>docker compose ps</code> und <code>docker compose logs ' . htmlspecialchars($container) . '</code>. ' .
    'Bei anhaltendem Problem manuell neu starten: <code>docker compose up -d --force-recreate ' . htmlspecialchars($container) . '</code>.</p>';

$ok = 0;
foreach ($recipients as $to) {
    try {
        Mailer::send($to, $subject, $body);
        fwrite(STDERR, "Alarm-Mail an {$to} gesendet.\n");
        $ok++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "Alarm-Mail an {$to} fehlgeschlagen: " . $e->getMessage() . "\n");
    }
}
exit($ok > 0 ? 0 : 3);
