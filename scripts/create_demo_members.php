<?php

declare(strict_types=1);

/**
 * scripts/create_demo_members.php -- legt die zwei fiktiven Mitglied-Identitäten für den
 * Demo-Login an (Patrick, 05.09.2026: "Am besten machen wir auch zwei Mitglieder: einen
 * Einspeiser und einen Produzierer[sic, gemeint: Verbraucher]. Am besten nimmst du da einfach
 * Stephanie Schweiger ... und Daniel Ropper ... Verwende andere Namen ... Zählpunktnummer oder
 * andere Daten mit Sternchen versehen oder [...] beim Namen austauschen").
 *
 * Sucht die beiden unten konfigurierten ECHTEN Mitglieder, kopiert deren aktive Zählpunkte +
 * komplette EDA-Messreihen (eda_measurements, eda_interval_data) 1:1 auf zwei NEUE, komplett
 * fiktive Mitglied-Datensätze ("Verbraucher 1"/"Einspeiser 1") in DERSELBEN EEG -- gleiche
 * Verbrauchszahlen, aber keine echte Identität mehr erkennbar (neuer Name, neue
 * Zählpunktnummer, neue member_id, is_demo=true). Die neuen Zählpunktnummern beginnen bewusst
 * NICHT mit "AT" (echtes EDA-Format), damit ein künftiger echter EDA-Import sie unter keinen
 * Umständen versehentlich treffen kann.
 *
 * is_demo=true auf members schließt diese beiden Datensätze automatisch von echten
 * Abrechnungsläufen aus (siehe Billing.php) -- unabhängig davon, ob/wie sie später über
 * user_roles.member_id mit einem Login verknüpft werden (siehe create_demo_login.sh).
 *
 * Sicher erneut ausführbar: bereits vorhandene Ziel-Mitglieder (Name + is_demo=true in
 * derselben EEG) werden übersprungen, nichts wird doppelt angelegt.
 *
 * Aufruf (im Repo-Root, auf dem Server):
 *   docker compose exec -T webapp php < scripts/create_demo_members.php
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';

// ─── Konfiguration: welche echten Mitglieder als Vorlage dienen, und wie die fiktive
// Identität heißen/aussehen soll. address/zip/city/email/phone/geburtsdatum sind bewusst
// komplett erfunden (nicht von den echten Vorlage-Mitgliedern abgeleitet) -- Patrick,
// 05.09.2026: "damit was personenbezogen sein kann, unkennbar oder unlesbar ist".
$DEMO_DEFINITIONS = [
    [
        'source_first_name' => 'Stephanie',
        'source_last_name'  => 'Schweiger',
        'first_name'        => 'Verbraucher',
        'last_name'         => '1',
        'email'             => 'verbraucher1.demo@stromfueralle.at',
        'phone'             => '+43 660 *** ** 11',
        'geburtsdatum'      => '1975-03-01',
        'address'           => 'Musterweg 1',
        'zip'               => '9999',
        'city'              => 'Musterort',
        'zaehlpunkt_prefix' => 'DEMO-VERBRAUCHER1',
        'meter_code_prefix' => 'DEMO-V1',
    ],
    [
        'source_first_name' => 'Daniel',
        'source_last_name'  => 'Ropper',
        'first_name'        => 'Einspeiser',
        'last_name'         => '1',
        'email'             => 'einspeiser1.demo@stromfueralle.at',
        'phone'             => '+43 664 *** ** 22',
        'geburtsdatum'      => '1978-11-01',
        'address'           => 'Musterweg 2',
        'zip'               => '9999',
        'city'              => 'Musterort',
        'zaehlpunkt_prefix' => 'DEMO-EINSPEISER1',
        'meter_code_prefix' => 'DEMO-E1',
    ],
];

$communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');

foreach ($DEMO_DEFINITIONS as $def) {
    // members hat RLS -- die Quelle ist community-unbekannt, deshalb jede EEG einzeln
    // durchprobieren (gleiches Muster wie resolveAppMemberships() in index.php).
    $source = null;
    foreach ($communities as $c) {
        DB::setCommunity($c['id']);
        $source = DB::fetchOne(
            'SELECT * FROM members WHERE community_id = ? AND first_name = ? AND last_name = ? AND is_demo = false LIMIT 1',
            [$c['id'], $def['source_first_name'], $def['source_last_name']]
        );
        if ($source) break;
    }
    if (!$source) {
        fwrite(STDERR, "[create_demo_members] Vorlage-Mitglied \"{$def['source_first_name']} {$def['source_last_name']}\" nicht gefunden -- übersprungen.\n");
        continue;
    }
    DB::setCommunity($source['community_id']);

    $existing = DB::fetchOne(
        'SELECT id FROM members WHERE community_id = ? AND first_name = ? AND last_name = ? AND is_demo = true',
        [$source['community_id'], $def['first_name'], $def['last_name']]
    );
    if ($existing) {
        fwrite(STDERR, "[create_demo_members] \"{$def['first_name']} {$def['last_name']}\" existiert bereits (id={$existing['id']}) -- übersprungen.\n");
        continue;
    }

    $newMember = DB::fetchOne(
        "INSERT INTO members (
            community_id, first_name, last_name, address, zip, city, email, phone,
            geburtsdatum, member_since, status, is_demo
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', true)
         RETURNING id",
        [
            $source['community_id'], $def['first_name'], $def['last_name'], $def['address'],
            $def['zip'], $def['city'], $def['email'], $def['phone'], $def['geburtsdatum'],
            $source['member_since'],
        ]
    );
    $newMemberId = $newMember['id'];
    fwrite(STDERR, "[create_demo_members] \"{$def['first_name']} {$def['last_name']}\" angelegt (id={$newMemberId}), Vorlage: {$source['first_name']} {$source['last_name']}.\n");

    $sourceMps = DB::fetchAll(
        'SELECT * FROM metering_points WHERE member_id = ? AND active = true',
        [$source['id']]
    );
    $n = 0;
    foreach ($sourceMps as $mp) {
        $n++;
        $fakeZaehlpunkt = $def['zaehlpunkt_prefix'] . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $fakeMeterCode  = $def['meter_code_prefix'] . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $newMp = DB::fetchOne(
            "INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, meter_code, type, active, registered_at)
             VALUES (?, ?, ?, ?, ?, true, ?)
             RETURNING id",
            [$source['community_id'], $newMemberId, $fakeZaehlpunkt, $fakeMeterCode, $mp['type'], $mp['registered_at']]
        );
        $newMpId = $newMp['id'];

        $copiedEda = DB::execute(
            "INSERT INTO eda_measurements (time, community_id, metering_point_id, meter_code, kwh_erzeugung, kwh_teilnahme, kwh_ueberschuss, kwh_restueberschuss, quality, completeness)
             SELECT time, community_id, ?, ?, kwh_erzeugung, kwh_teilnahme, kwh_ueberschuss, kwh_restueberschuss, quality, completeness
             FROM eda_measurements WHERE metering_point_id = ?",
            [$newMpId, $fakeMeterCode, $mp['id']]
        );
        $copiedInterval = DB::execute(
            "INSERT INTO eda_interval_data (time, community_id, metering_point_id, energy_direction, kwh_messung, kwh_gemeinschaft, quality)
             SELECT time, community_id, ?, energy_direction, kwh_messung, kwh_gemeinschaft, quality
             FROM eda_interval_data WHERE metering_point_id = ?",
            [$newMpId, $mp['id']]
        );
        fwrite(STDERR, "  Zählpunkt {$fakeZaehlpunkt} ({$mp['type']}) angelegt: {$copiedEda} eda_measurements-Zeilen, {$copiedInterval} eda_interval_data-Zeilen kopiert.\n");
    }
}

fwrite(STDERR, "[create_demo_members] Fertig. Jetzt im Platform-Admin-Backoffice die Rollen des Demo-Logins zuweisen (siehe scripts/create_demo_login.sh).\n");
