<?php

declare(strict_types=1);

/**
 * scripts/create_demo_members.php -- legt die zwei fiktiven Mitglied-Identitäten für den
 * Demo-Login an UND hält sie danach synchron (Patrick, 05.09.2026: "Am besten machen wir auch
 * zwei Mitglieder: einen Einspeiser und einen Produzierer[sic, gemeint: Verbraucher]. Am besten
 * nimmst du da einfach [die echten Vorlage-Mitglieder] ... Verwende andere Namen ...
 * Zählpunktnummer oder andere Daten mit Sternchen versehen oder [...] beim Namen austauschen".
 * Später, 05.09.2026: "Die Daten sollen immer gleich sein mit den aktuell gültigen Daten" --
 * deshalb KEIN Einmal-Skript, sondern ein SYNC: bei jedem Lauf werden die Messdaten der beiden
 * fiktiven Identitäten komplett neu aus den aktuellen Daten der Vorlage-Mitglieder aufgebaut.
 *
 * Sucht die beiden unten konfigurierten ECHTEN Mitglieder, kopiert deren aktive Zählpunkte +
 * komplette EDA-Messreihen (eda_measurements, eda_interval_data) 1:1 auf zwei fiktive
 * Mitglied-Datensätze ("Verbraucher 1"/"Einspeiser 1") in DERSELBEN EEG -- gleiche
 * Verbrauchszahlen, aber keine echte Identität mehr erkennbar (neuer Name, neue
 * Zählpunktnummer, neue member_id, is_demo=true). Die fiktiven Zählpunktnummern beginnen bewusst
 * NICHT mit "AT" (echtes EDA-Format), damit ein künftiger echter EDA-Import sie unter keinen
 * Umständen versehentlich treffen kann.
 *
 * is_demo=true auf members schließt diese beiden Datensätze automatisch von echten
 * Abrechnungsläufen aus (siehe Billing.php) -- unabhängig davon, ob/wie sie über
 * user_roles.member_id mit einem Login verknüpft werden (siehe create_demo_login.sh).
 *
 * SICHER ERNEUT AUSFÜHRBAR UND DAFÜR GEDACHT: der Mitglied-Datensatz selbst (Name, Adresse,
 * Kundennummer, ...) wird nur beim ALLERERSTEN Lauf angelegt und danach wiederverwendet
 * (stabile member_id/Zählpunktnummer, u.a. weil Rollenzuweisungen im Admin-Backoffice auf die
 * member_id zeigen). Die Zählpunkte + ALLE Messdaten (eda_measurements, eda_interval_data)
 * werden dagegen bei JEDEM Lauf komplett neu aus dem aktuellen Stand des Vorlage-Mitglieds
 * aufgebaut (erst gelöscht, dann frisch kopiert) -- so bleibt die Demo-Identität nach jedem
 * neuen EDA-Import automatisch aktuell, ohne dass Zählpunkt-IDs/Rollenzuweisungen invalidiert
 * werden. Empfehlung: als täglichen Cron-Job einrichten (siehe CLAUDE.md), damit "immer
 * synchron" auch ohne manuelles Nachtriggern gilt -- unabhängig davon, ob die Vorlage-Daten
 * gerade per Auto-Import oder manuellem Upload aktualisiert wurden.
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
// Zusatzfelder (IBAN/BIC/Kontoinhaber/Zustimmungen/Stromlieferant/Speicher) sind bewusst
// vollständig ausgefüllt statt leer -- Patrick, 05.09.2026: "dass man in den 4 Rollen schon
// alle Funktionen und Felder, sowie Button sieht. Es soll ein richtiger DEMO-Acc sein." Die
// IBAN ist eine erkennbare Platzhalter-IBAN (keine echte Bankverbindung) -- unbedenklich, weil
// is_demo=true diese Mitglieder ohnehin von JEDEM Abrechnungslauf ausschließt (Billing.php)
// und dadurch nie eine invoices-Zeile für sie entsteht, an die ein SEPA-Export anknüpfen könnte.
$DEMO_DEFINITIONS = [
    [
        'source_first_name' => 'Stefanie',
        'source_last_name'  => 'Schwaiger',
        'first_name'        => 'Verbraucher',
        'last_name'         => '1',
        'email'             => 'verbraucher1.demo@stromfueralle.at',
        'phone'             => '+43 660 *** ** 11',
        'geburtsdatum'      => '1975-03-01',
        'address'           => 'Musterweg 1',
        'zip'               => '9999',
        'city'              => 'Musterort',
        'iban'              => 'AT00 0000 0000 0000 0001',
        'bic'               => 'DEMOATWW',
        'stromlieferant'    => 'Demo Energie AG',
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
        'iban'              => 'AT00 0000 0000 0000 0002',
        'bic'               => 'DEMOATWW',
        'stromlieferant'    => 'Demo Energie AG',
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

    $target = DB::fetchOne(
        'SELECT id FROM members WHERE community_id = ? AND first_name = ? AND last_name = ? AND is_demo = true',
        [$source['community_id'], $def['first_name'], $def['last_name']]
    );

    if ($target) {
        $newMemberId = $target['id'];
        fwrite(STDERR, "[create_demo_members] \"{$def['first_name']} {$def['last_name']}\" existiert bereits (id={$newMemberId}) -- synchronisiere Messdaten neu.\n");
    } else {
        // kundennummer ist PLATTFORMWEIT eindeutig (uq_members_kundennummer, siehe
        // migrate_20260723.sql) -- MAX(kundennummer)+1 vergeben, exakt dasselbe Muster wie bei
        // einer echten Mitglied-Neuanlage in index.php. Wird nur beim allerersten Lauf vergeben
        // und danach beibehalten (stabile member_id/Kundennummer über spätere Sync-Läufe hinweg).
        $kundennummer = (int)DB::fetchOne('SELECT COALESCE(MAX(kundennummer), 10000) + 1 AS next FROM members')['next'];
        $mandatsreferenz = 'S00000F' . date('Y') . 'A' . $kundennummer;
        $kontoinhaber = trim($def['first_name'] . ' ' . $def['last_name']);

        $newMember = DB::fetchOne(
            "INSERT INTO members (
                community_id, first_name, last_name, address, zip, city, email, phone,
                geburtsdatum, member_since, beitrittsdatum, status, is_demo, kundennummer,
                member_iban, member_bic, kontoinhaber, konto_adresse, mandatsreferenz,
                stromlieferant, speicher_status,
                zustimmung_mitgliedschaft, zustimmung_vollmacht, zustimmung_widerrufsfrist,
                zustimmung_email_kommunikation, zustimmung_datenschutz, zustimmung_agb
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', true, ?, ?, ?, ?, ?, ?, ?, 'nein',
                       true, true, true, true, true, true)
             RETURNING id",
            [
                $source['community_id'], $def['first_name'], $def['last_name'], $def['address'],
                $def['zip'], $def['city'], $def['email'], $def['phone'], $def['geburtsdatum'],
                $source['member_since'], $source['member_since'], $kundennummer,
                $def['iban'], $def['bic'], $kontoinhaber, $def['address'], $mandatsreferenz,
                $def['stromlieferant'],
            ]
        );
        $newMemberId = $newMember['id'];
        fwrite(STDERR, "[create_demo_members] \"{$def['first_name']} {$def['last_name']}\" neu angelegt (id={$newMemberId}), Vorlage: {$source['first_name']} {$source['last_name']}.\n");
    }

    // Stabile Reihenfolge nötig, damit derselbe Vorlage-Zählpunkt bei jedem Sync-Lauf auf
    // denselben fiktiven Zählpunkt (Index n) abgebildet wird.
    $sourceMps = DB::fetchAll(
        'SELECT * FROM metering_points WHERE member_id = ? AND active = true ORDER BY registered_at, id',
        [$source['id']]
    );
    $n = 0;
    foreach ($sourceMps as $mp) {
        $n++;
        $fakeZaehlpunkt = $def['zaehlpunkt_prefix'] . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
        $fakeMeterCode  = $def['meter_code_prefix'] . '-' . str_pad((string)$n, 3, '0', STR_PAD_LEFT);

        $existingMp = DB::fetchOne(
            'SELECT id FROM metering_points WHERE community_id = ? AND zaehlpunkt_nr = ?',
            [$source['community_id'], $fakeZaehlpunkt]
        );
        if ($existingMp) {
            $newMpId = $existingMp['id'];
            // Typ/Registrierdatum können sich am Vorlage-Zählpunkt geändert haben -- mitziehen.
            DB::execute(
                'UPDATE metering_points SET type = ?, registered_at = ?, active = true WHERE id = ?',
                [$mp['type'], $mp['registered_at'], $newMpId]
            );
        } else {
            $newMp = DB::fetchOne(
                "INSERT INTO metering_points (community_id, member_id, zaehlpunkt_nr, meter_code, type, active, registered_at)
                 VALUES (?, ?, ?, ?, ?, true, ?)
                 RETURNING id",
                [$source['community_id'], $newMemberId, $fakeZaehlpunkt, $fakeMeterCode, $mp['type'], $mp['registered_at']]
            );
            $newMpId = $newMp['id'];
        }

        // Messdaten komplett neu aufbauen (löschen + frisch kopieren), damit dieser Lauf immer
        // exakt den aktuellen Stand des Vorlage-Zählpunkts widerspiegelt -- Patrick, 05.09.2026:
        // "Die Daten sollen immer gleich sein mit den aktuell gültigen Daten".
        DB::execute('DELETE FROM eda_measurements WHERE metering_point_id = ?', [$newMpId]);
        $copiedEda = DB::execute(
            "INSERT INTO eda_measurements (time, community_id, metering_point_id, meter_code, kwh_erzeugung, kwh_teilnahme, kwh_ueberschuss, kwh_restueberschuss, quality, completeness)
             SELECT time, community_id, ?, ?, kwh_erzeugung, kwh_teilnahme, kwh_ueberschuss, kwh_restueberschuss, quality, completeness
             FROM eda_measurements WHERE metering_point_id = ?",
            [$newMpId, $fakeMeterCode, $mp['id']]
        );
        DB::execute('DELETE FROM eda_interval_data WHERE metering_point_id = ?', [$newMpId]);
        $copiedInterval = DB::execute(
            "INSERT INTO eda_interval_data (time, community_id, metering_point_id, energy_direction, kwh_messung, kwh_gemeinschaft, quality)
             SELECT time, community_id, ?, energy_direction, kwh_messung, kwh_gemeinschaft, quality
             FROM eda_interval_data WHERE metering_point_id = ?",
            [$newMpId, $mp['id']]
        );
        fwrite(STDERR, "  Zählpunkt {$fakeZaehlpunkt} ({$mp['type']}) synchronisiert: {$copiedEda} eda_measurements-Zeilen, {$copiedInterval} eda_interval_data-Zeilen.\n");
    }
}

fwrite(STDERR, "[create_demo_members] Fertig. Beim allerersten Lauf jetzt im Platform-Admin-Backoffice die Rollen des Demo-Logins zuweisen (siehe scripts/create_demo_login.sh).\n");
