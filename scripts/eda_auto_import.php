<?php

declare(strict_types=1);

/**
 * scripts/eda_auto_import.php — liest das zentrale EDA-Postfach aus und importiert neue
 * Exportdateien automatisch (siehe EdaAutoImporter.php für die eigentliche Logik/Annahmen).
 *
 * Aufruf identisch zu scripts/health_alert.php -- vom Host per Cron, Ausführung IM
 * webapp-Container (DB-Zugang); den eigentlichen Import erledigt seit 16.11.2026 der Container
 * eda-worker, dieses Skript wartet auf dessen Ergebnis:
 *   docker compose exec -T webapp php < scripts/eda_auto_import.php
 *
 * Alle 5 Minuten (Patrick, 14.11.2026: "Vielleicht machen wir das bitte doch alle 5 Minuten";
 * davor alle 15 Minuten, ganz am Anfang nur einmal täglich um 07:00). Das Postfach zu prüfen ist
 * ein reiner, günstiger Lesezugriff über Microsoft Graph; zwei gleichzeitige Läufe verhindert
 * eine Sperrdatei in EdaAutoImporter::run(). Minutenfeld "0-59/5" ist gleichbedeutend mit dem
 * üblichen Stern-Schrägstrich-5, das hier den PHP-Kommentarblock vorzeitig beenden würde:
 *   0-59/5 * * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/eda_auto_import.php >> /var/log/eeg-eda-import.log 2>&1
 *
 * Jede Log-Zeile beginnt mit Datum/Uhrzeit -- so ist in /var/log/eeg-eda-import.log sofort zu
 * sehen, ob (und wann zuletzt) der Cron überhaupt läuft.
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/autoload.php';
require '/var/www/html/src/AppConfig.php';
require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';
require '/var/www/html/src/Mailer.php';
require '/var/www/html/src/GraphMailReader.php';
require '/var/www/html/src/EdaParserRunner.php';
require '/var/www/html/src/EdaAutoImporter.php';

date_default_timezone_set('Europe/Vienna');
$stamp = date('Y-m-d H:i:s');
try {
    foreach (EdaAutoImporter::run() as $line) {
        fwrite(STDERR, "[{$stamp}] [eda_auto_import] {$line}\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[{$stamp}] [eda_auto_import] FEHLER: " . $e->getMessage() . "\n");
    exit(1);
}
