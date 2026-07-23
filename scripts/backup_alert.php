<?php

declare(strict_types=1);

/**
 * scripts/backup_alert.php — Sendet eine Alarm-E-Mail ans Admin-Postfach, wenn das nächtliche
 * Backup (scripts/backup.sh) NICHT sauber durchgelaufen ist.
 *
 * Wird von backup.sh im webapp-Container per stdin ausgeführt:
 *   docker compose exec -T -e ALERT_REASON="..." webapp php < scripts/backup_alert.php
 * Dadurch ist KEIN Image-Rebuild nötig, und der Versand nutzt exakt dieselbe
 * Microsoft-Graph-Anbindung wie der Rest der Plattform (Mailer, Zugangsdaten aus der DB).
 *
 * Empfänger: Umgebungsvariable BACKUP_ALERT_EMAIL, sonst der erste Platform-Admin aus der DB.
 */

// STDERR ist nicht in jeder PHP-SAPI vordefiniert (z.B. wenn das Skript per stdin an `php`
// übergeben wird) -- in PHP 8 wäre der Zugriff auf eine undefinierte Konstante ein Fatal Error.
if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/DB.php';
require '/var/www/html/src/Mailer.php';

$reason = getenv('ALERT_REASON') ?: 'unbekannter Fehler';
$host   = getenv('ALERT_HOST') ?: 'eeg-server';
$when   = date('d.m.Y H:i');

// Empfänger sammeln: konfigurierte Adressen (Platform-Admin -> E-Mail-Einstellungen), optional
// eine per Umgebungsvariable, sonst als Fallback der erste aktive Platform-Admin.
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
if ($env = getenv('BACKUP_ALERT_EMAIL')) {
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

// Doppelte/leere entfernen
$recipients = array_values(array_unique(array_filter(array_map('trim', $recipients))));

if (!$recipients) {
    fwrite(STDERR, "Kein Alarm-Empfänger gefunden (Backup-Alarm-Adressen in E-Mail-Einstellungen setzen, BACKUP_ALERT_EMAIL setzen oder Platform-Admin anlegen).\n");
    exit(2);
}

$subject = '⚠️ EEG-Backup FEHLGESCHLAGEN (' . $host . ')';
$body =
    '<p><strong>Das automatische Backup der EEG-Plattform ist nicht durchgelaufen.</strong></p>' .
    '<p><strong>Zeitpunkt:</strong> ' . htmlspecialchars($when) . '<br>' .
    '<strong>Server:</strong> ' . htmlspecialchars($host) . '<br>' .
    '<strong>Grund:</strong> ' . htmlspecialchars($reason) . '</p>' .
    '<p>Bitte zeitnah prüfen: läuft der <code>timescaledb</code>-Container, ist genug ' .
    'Speicherplatz frei, und lässt sich <code>bash scripts/backup.sh</code> manuell ausführen? ' .
    'Solange kein neues Backup vorliegt, ist der letzte gesicherte Stand nicht aktuell.</p>';

$ok = 0; $errors = [];
foreach ($recipients as $to) {
    try {
        Mailer::send($to, $subject, $body);
        fwrite(STDERR, "Alarm-Mail an {$to} gesendet.\n");
        $ok++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "Alarm-Mail an {$to} fehlgeschlagen: " . $e->getMessage() . "\n");
        $errors[] = $to;
    }
}
exit($ok > 0 ? 0 : 3);   // Erfolg, sobald mindestens eine Adresse erreicht wurde
