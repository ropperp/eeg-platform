<?php

declare(strict_types=1);

/**
 * scripts/messe_demo_setup.php -- legt 20 fiktive Zählpunkte (8 Einspeiser, 12 Verbraucher) für
 * eine Messe-/Diplomarbeits-Präsentation an, damit scripts/messe_demo_simulator.py über MQTT
 * realistische, schwankende Live-Werte dafür einspielen kann und der komplette echte Pfad
 * (mqtt-subscriber -> esp_measurements -> Energiefluss-Visualisierung/Live-Dashboard) für die
 * Vorführung genutzt wird, statt die Zahlen irgendwo im Frontend nur vorzutäuschen.
 *
 * Patrick, 02.10.2026: "brauch in einer Woche einen guten Energiefluss um auf einer Messe eine
 * Simulation zu zeigen [...] 8 Einspeiser und 12 Verbraucher [...] paar höhere und paar
 * niedrigere [...] über mqtt trotzdem".
 *
 * ALLE 20 Zählpunkte hängen an EINEM einzigen, fiktiven Demo-Mitglied (is_demo=true) in Patricks
 * eigener, echter EEG -- genau wie die bestehenden Demo-Login-Identitäten ("Verbraucher 1"/
 * "Einspeiser 1", siehe migrate_20260905.sql) schließt is_demo=true dieses Mitglied automatisch
 * von jedem echten Abrechnungslauf aus (Billing::generateDrafts() filtert m.is_demo = false) --
 * die 20 Zählpunkte können also beliebig lange laufen, ohne jemals in eine Rechnung einzufließen.
 *
 * WICHTIG, bewusst ANDERS als die bestehenden 2 Demo-Zählpunkte: diese 20 haben KEIN
 * mirror_source_metering_point_id (keine Live-Spiegelung eines echten Zählpunkts) und werden
 * deshalb von communityLivePower()/der öffentlichen Live-Anzeige NICHT ausgeschlossen -- sie
 * fließen während der Messe-Vorführung tatsächlich in die Community-weite Summe ein (das ist
 * der Sinn der Übung: ein reicher, lebendiger Energiefluss mit 20 statt nur den paar echten
 * Teilnehmern). Nach der Messe daher scripts/messe_demo_teardown.php nicht vergessen, sonst
 * verzerren die 20 Fantasiewerte dauerhaft die echte Live-Anzeige der EEG.
 *
 * Sicher erneut ausführbar: prüft vor dem Anlegen per E-Mail-Adresse, legt bei einem erneuten
 * Lauf nichts doppelt an.
 *
 * Aufruf (im Repo-Root, auf dem Server):
 *   docker compose exec -T webapp php scripts/messe_demo_setup.php [community-slug]
 * Ohne Argument wird automatisch die einzige aktive Community verwendet (Fehler, falls es
 * mehrere gibt -- dann den Slug explizit angeben). Das Skript gibt am Ende die 20 Zählernummern
 * aus, die scripts/messe_demo_simulator.py braucht (dort schon als Default hinterlegt, siehe
 * MESSE_METER_CODES) -- nur bei Abweichungen in der Ausgabe muss der Simulator angepasst werden.
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';

const MESSE_DEMO_EMAIL = 'messe-demo@stromfueralle.local';

/** 8 Einspeiser (producer), 12 Verbraucher (consumer) -- feste Zählernummern/Zählpunkte, damit
 *  Setup-Skript und Simulator unabhängig voneinander dieselben 20 Geräte ansprechen. */
function messeMeterDefinitions(): array
{
    $defs = [];
    for ($i = 1; $i <= 8; $i++) {
        $n = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $defs[] = [
            'type'          => 'producer',
            'meter_code'    => '90000001' . $n,
            'zaehlpunkt_nr' => 'DEMO-MESSE-EINSPEISER-' . $n,
        ];
    }
    for ($i = 1; $i <= 12; $i++) {
        $n = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $defs[] = [
            'type'          => 'consumer',
            'meter_code'    => '90000002' . $n,
            'zaehlpunkt_nr' => 'DEMO-MESSE-VERBRAUCHER-' . $n,
        ];
    }
    return $defs;
}

$slugArg = $argv[1] ?? null;
if ($slugArg) {
    $community = DB::fetchOne('SELECT * FROM communities WHERE slug = ? AND active = true', [$slugArg]);
    if (!$community) {
        fwrite(STDERR, "Keine aktive Community mit Slug '$slugArg' gefunden.\n");
        exit(1);
    }
} else {
    $all = DB::fetchAll('SELECT * FROM communities WHERE active = true ORDER BY name');
    if (count($all) === 0) {
        fwrite(STDERR, "Keine aktive Community gefunden.\n");
        exit(1);
    }
    if (count($all) > 1) {
        fwrite(STDERR, "Mehrere aktive Communities gefunden, bitte Slug als Argument angeben:\n");
        foreach ($all as $c) fwrite(STDERR, "  - {$c['slug']} ({$c['name']})\n");
        exit(1);
    }
    $community = $all[0];
}
$communityId = $community['id'];
DB::setCommunity($communityId);

$member = DB::fetchOne('SELECT * FROM members WHERE community_id = ? AND email = ?', [$communityId, MESSE_DEMO_EMAIL]);
if (!$member) {
    DB::execute(
        "INSERT INTO members
            (community_id, first_name, last_name, address, zip, city, email, member_since, status, is_demo)
         VALUES (?, 'Messe-Demo', 'Besucher', 'Messeplatz 1', '9020', 'Klagenfurt', ?, CURRENT_DATE, 'active', true)",
        [$communityId, MESSE_DEMO_EMAIL]
    );
    $member = DB::fetchOne('SELECT * FROM members WHERE community_id = ? AND email = ?', [$communityId, MESSE_DEMO_EMAIL]);
    fwrite(STDERR, "Demo-Mitglied 'Messe-Demo Besucher' angelegt (id {$member['id']}).\n");
} else {
    fwrite(STDERR, "Demo-Mitglied existiert bereits (id {$member['id']}), wird wiederverwendet.\n");
}
$memberId = $member['id'];

$angelegt = 0;
$codes = [];
foreach (messeMeterDefinitions() as $def) {
    $codes[] = $def['meter_code'];
    $existing = DB::fetchOne(
        'SELECT id FROM metering_points WHERE community_id = ? AND zaehlpunkt_nr = ?',
        [$communityId, $def['zaehlpunkt_nr']]
    );
    if ($existing) continue;
    DB::execute(
        'INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, meter_code, type, active, registered_at)
         VALUES (?, ?, ?, ?, ?, true, CURRENT_DATE)',
        [$communityId, $memberId, $def['zaehlpunkt_nr'], $def['meter_code'], $def['type']]
    );
    $angelegt++;
}

fwrite(STDERR, "$angelegt neue(r) Zählpunkt(e) angelegt (von insgesamt 20).\n");
fwrite(STDERR, "Community-Slug für den Simulator: {$community['slug']}\n");
fwrite(STDERR, "Zählernummern (bereits als Default im Simulator hinterlegt):\n");
fwrite(STDERR, implode(',', $codes) . "\n");
fwrite(STDERR, "\nJetzt den Simulator starten, z.B.:\n");
fwrite(STDERR, "  python3 scripts/messe_demo_simulator.py --community {$community['slug']} --host stromfueralle.at --port 8883 --insecure\n");
fwrite(STDERR, "\nNach der Messe aufräumen mit:\n");
fwrite(STDERR, "  docker compose exec -T webapp php scripts/messe_demo_teardown.php {$community['slug']}\n");
