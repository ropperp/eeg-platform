<?php

declare(strict_types=1);

/**
 * scripts/statute_cleanup.php — Löschkonzept des Statuten-Generators (docs/DSGVO.md):
 * Einladungen samt Entwürfen und PDFs werden 12 Monate nach der letzten Erzeugung gelöscht
 * (nie erzeugte bzw. widerrufene Einladungen 12 Monate nach Ablauf bzw. Widerruf).
 *
 * Aufruf vom Host per Cron, Ausführung IM webapp-Container (DB-Zugang, Storage-Volume):
 *   docker compose exec -T webapp php < scripts/statute_cleanup.php
 * Einmal täglich reicht:
 *   30 3 * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/statute_cleanup.php >> /var/log/eeg-statute-cleanup.log 2>&1
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }
if (!defined('ROOT')) { define('ROOT', '/var/www/html'); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';
require '/var/www/html/src/autoload.php';

$deleted = \App\Support\Services::statuteService()->purgeStale();
fwrite(STDERR, '[statute_cleanup] ' . date('Y-m-d H:i') . ' gelöscht: ' . $deleted . " Einladung(en)\n");
