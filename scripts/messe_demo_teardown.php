<?php

declare(strict_types=1);

/**
 * scripts/messe_demo_teardown.php -- entfernt das per messe_demo_setup.php angelegte
 * Demo-Mitglied samt seiner 20 Zählpunkte wieder vollständig (ON DELETE CASCADE räumt
 * metering_points + esp_measurements automatisch mit ab). Nach der Messe ausführen, sonst
 * verzerren die 20 Fantasiewerte dauerhaft die echte Live-Anzeige/den Energiefluss der EEG --
 * siehe Kommentar in messe_demo_setup.php.
 *
 * Aufruf (im Repo-Root, auf dem Server):
 *   docker compose exec -T webapp php scripts/messe_demo_teardown.php [community-slug]
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';

const MESSE_DEMO_EMAIL = 'messe-demo@stromfueralle.local';

$slugArg = $argv[1] ?? null;
if ($slugArg) {
    $community = DB::fetchOne('SELECT * FROM communities WHERE slug = ?', [$slugArg]);
    if (!$community) { fwrite(STDERR, "Community '$slugArg' nicht gefunden.\n"); exit(1); }
} else {
    $all = DB::fetchAll('SELECT * FROM communities WHERE active = true ORDER BY name');
    if (count($all) > 1) {
        fwrite(STDERR, "Mehrere aktive Communities gefunden, bitte Slug als Argument angeben.\n");
        exit(1);
    }
    $community = $all[0] ?? null;
    if (!$community) { fwrite(STDERR, "Keine aktive Community gefunden.\n"); exit(1); }
}
DB::setCommunity($community['id']);

$member = DB::fetchOne('SELECT id FROM members WHERE community_id = ? AND email = ?', [$community['id'], MESSE_DEMO_EMAIL]);
if (!$member) {
    fwrite(STDERR, "Kein Messe-Demo-Mitglied in '{$community['slug']}' gefunden -- nichts zu tun.\n");
    exit(0);
}
DB::execute('DELETE FROM members WHERE id = ?', [$member['id']]);
fwrite(STDERR, "Messe-Demo-Mitglied samt aller 20 Zählpunkte und Messwerte entfernt.\n");
