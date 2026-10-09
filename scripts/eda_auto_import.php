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
 * Alle 15 Minuten statt nur einmal täglich (Patrick, 09.10.2026: "Ich habe jetzt schon ein paar
 * Mal was gehabt, und da hat sich das aktuellste Excel-File nicht automatisch geholt" -- bei nur
 * einem Lauf pro Tag (07:00) wirkt ein Import, der erst NACH 07:00 im Postfach ankommt, bis zum
 * nächsten Tag wie "nicht automatisch geholt", obwohl er nur noch nicht dran war). Das Postfach
 * zu prüfen ist ein reiner, günstiger Lesezugriff über Microsoft Graph -- ob eine neue Datei
 * wirklich da ist oder nicht, macht für die Kosten kaum einen Unterschied, ein kurzes Intervall
 * kostet also nichts Nennenswertes, auch wenn echte EDA-Exporte selbst nur einmal im Monat
 * anfallen (Minuten-Feld bewusst als "0,15,30,45" statt des sonst üblichen "*' + '/15" -- Letzteres
 * würde hier den PHP-Kommentarblock selbst vorzeitig beenden):
 *   0,15,30,45 * * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/eda_auto_import.php >> /var/log/eeg-eda-import.log 2>&1
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';
require '/var/www/html/src/Mailer.php';
require '/var/www/html/src/GraphMailReader.php';
require '/var/www/html/src/EdaParserRunner.php';
require '/var/www/html/src/EdaAutoImporter.php';

foreach (EdaAutoImporter::run() as $line) {
    fwrite(STDERR, '[eda_auto_import] ' . $line . "\n");
}
