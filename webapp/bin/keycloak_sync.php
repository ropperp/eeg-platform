<?php

declare(strict_types=1);

/**
 * Arbeitet die Warteschlange keycloak_sync_queue ab (Abgleich Portal -> Keycloak, docs/adr/0007-keycloak.md).
 * Die Webapp macht das ohnehin nach jeder Anfrage; dieser Cron holt nach, was dabei liegen blieb
 * (z. B. Keycloak kurz nicht erreichbar). Ohne eingerichtetes Keycloak tut er nichts.
 *
 * Host-Cron (siehe docs/BETRIEBSHANDBUCH.md):
 *   * * * * * cd /opt/eeg-platform && docker compose exec -T webapp php /var/www/html/bin/keycloak_sync.php >> /var/log/eeg-keycloak-sync.log 2>&1
 */

use App\Infrastructure\Auth\KeycloakSync;

require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/AppConfig.php';
require __DIR__ . '/../src/autoload.php';

$sync = KeycloakSync::fromEnv();
if ($sync === null) {
    exit(0);
}
$total = ['ok' => 0, 'fehler' => 0];
for ($i = 0; $i < 50; $i++) {
    $r = $sync->drain(50);
    $total['ok'] += $r['ok'];
    $total['fehler'] += $r['fehler'];
    if ($r['ok'] + $r['fehler'] === 0 || $r['fehler'] > 0) {
        break;
    }
}
if ($total['ok'] + $total['fehler'] > 0) {
    fwrite(STDOUT, date('c') . " keycloak_sync: {$total['ok']} ok, {$total['fehler']} Fehler\n");
}
exit($total['fehler'] > 0 ? 1 : 0);
