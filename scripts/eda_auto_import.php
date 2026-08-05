<?php

declare(strict_types=1);

/**
 * scripts/eda_auto_import.php — liest das zentrale EDA-Postfach aus und importiert neue
 * Exportdateien automatisch (siehe EdaAutoImporter.php für die eigentliche Logik/Annahmen).
 *
 * Aufruf identisch zu scripts/health_alert.php -- vom Host per Cron, Ausführung IM
 * webapp-Container (dort liegen eda-parser/ und die DB-Zugangsdaten):
 *   docker compose exec -T webapp php < scripts/eda_auto_import.php
 *
 * Gedacht für einmal täglich, da EDA-Exporte ohnehin nur einmal im Monat anfallen:
 *   0 7 * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/eda_auto_import.php >> /var/log/eeg-eda-import.log 2>&1
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';
require '/var/www/html/src/Mailer.php';
require '/var/www/html/src/GraphMailReader.php';
require '/var/www/html/src/EdaAutoImporter.php';

foreach (EdaAutoImporter::run() as $line) {
    fwrite(STDERR, '[eda_auto_import] ' . $line . "\n");
}
