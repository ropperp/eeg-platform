<?php

declare(strict_types=1);

/**
 * scripts/assign_demo_member_roles.php -- verknüpft die beiden fiktiven Mitglied-Identitäten
 * ("Verbraucher 1"/"Einspeiser 1", siehe create_demo_members.php) korrekt mit dem EINEN
 * Demo-Login (users.is_demo=true, siehe create_demo_login.sh).
 *
 * Hintergrund (Patrick, 06.09.2026, per Screenshot): platform_admin + manager waren über die
 * Admin-Oberfläche (/admin/users/:id -> "Rolle hinzufügen") schon korrekt zugewiesen, aber die
 * 'member'-Rolle wurde dabei OHNE Mitglied-Identität hinzugefügt (Feld "Mitglied-Identität"
 * blieb leer/"keine (normaler Fall)") -- "Aktuelle Rollen" zeigte deshalb "member" mit
 * Mitglied="--", was nicht auf "Verbraucher 1"/"Einspeiser 1" führt (currentMemberFull() findet
 * dafür keinen members-Datensatz, weil KEIN echter members.user_id auf den Demo-Login zeigt --
 * die Verknüpfung läuft für Demo-Logins ausschließlich über user_roles.member_id, siehe
 * migrate_20260905.sql). Dieses Skript räumt eine solche "nackte" member-Rolle ohne
 * Mitglied-Identität auf und trägt stattdessen zwei saubere 'member'-Rollen ein -- je eine für
 * Verbraucher 1 und Einspeiser 1. Alternative zum manuellen Weg über die Admin-Oberfläche
 * (Rolle "member" wählen -> Feld "Mitglied-Identität" erscheint erst DANN -> dort die jeweilige
 * Identität auswählen -- leicht zu übersehen, da das Feld anfangs ausgeblendet ist).
 *
 * Sicher erneut ausführbar: bereits korrekt gesetzte Rollen werden übersprungen (ON CONFLICT DO
 * NOTHING dank der partiellen Unique-Indizes aus migrate_20260905.sql).
 *
 * Aufruf (im Repo-Root, auf dem Server):
 *   docker compose exec -T webapp php < scripts/assign_demo_member_roles.php
 */

if (!defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }

require '/var/www/html/src/functions.php';
require '/var/www/html/src/DB.php';

$demoUser = DB::fetchOne('SELECT id, email FROM users WHERE is_demo = true');
if (!$demoUser) {
    fwrite(STDERR, "[assign_demo_member_roles] Kein Demo-Login gefunden (users.is_demo=true) -- zuerst scripts/create_demo_login.sh ausführen.\n");
    exit(1);
}
fwrite(STDERR, "[assign_demo_member_roles] Demo-Login: {$demoUser['email']} (id={$demoUser['id']})\n");

$removed = DB::execute(
    "DELETE FROM user_roles WHERE user_id = ? AND role = 'member' AND member_id IS NULL",
    [$demoUser['id']]
);
if ($removed > 0) {
    fwrite(STDERR, "[assign_demo_member_roles] {$removed} 'member'-Rolle(n) ohne Mitglied-Identität entfernt (führten ins Leere).\n");
}

$communities = DB::fetchAll('SELECT id, name FROM communities ORDER BY name');
$targets = [
    ['first_name' => 'Verbraucher', 'last_name' => '1'],
    ['first_name' => 'Einspeiser', 'last_name' => '1'],
];

foreach ($targets as $t) {
    $member = null;
    foreach ($communities as $c) {
        DB::setCommunity($c['id']);
        $member = DB::fetchOne(
            'SELECT id, community_id FROM members WHERE community_id = ? AND first_name = ? AND last_name = ? AND is_demo = true',
            [$c['id'], $t['first_name'], $t['last_name']]
        );
        if ($member) break;
    }
    if (!$member) {
        fwrite(STDERR, "[assign_demo_member_roles] \"{$t['first_name']} {$t['last_name']}\" nicht gefunden -- zuerst scripts/create_demo_members.php ausführen.\n");
        continue;
    }
    $inserted = DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role, member_id) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING',
        [$member['community_id'], $demoUser['id'], 'member', $member['id']]
    );
    fwrite(STDERR, $inserted > 0
        ? "[assign_demo_member_roles] \"{$t['first_name']} {$t['last_name']}\" verknüpft.\n"
        : "[assign_demo_member_roles] \"{$t['first_name']} {$t['last_name']}\" war bereits korrekt verknüpft.\n");
}

fwrite(STDERR, "[assign_demo_member_roles] Fertig. Beim nächsten Login (bzw. nach Neuladen, falls der Demo-Account gerade eingeloggt ist) stehen beide Mitglied-Rollen im Rollen-Dropdown zur Auswahl.\n");
