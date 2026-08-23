<?php

declare(strict_types=1);

/**
 * scripts/assign_demo_member_roles.php -- stellt sicher, dass der EINE Demo-Login
 * (users.is_demo=true, siehe create_demo_login.sh) alle VIER vorgesehenen Rollen sauber
 * zugewiesen hat: platform_admin, manager, und die beiden fiktiven Mitglied-Identitäten
 * ("Verbraucher 1"/"Einspeiser 1", siehe create_demo_members.php).
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
 * Ergänzt seit 06.09.2026 (Patrick: "Admin und Obmann gibt es noch immer nicht als Demo Rolle"):
 * legt platform_admin + manager jetzt selbst mit an, falls sie fehlen sollten, statt nur
 * darauf zu vertrauen, dass sie schon über die Admin-Oberfläche angelegt wurden -- und gibt am
 * Ende den TATSÄCHLICHEN, aus der DB gelesenen Rollenstand aus, damit sich "ist eine Rolle da
 * oder nicht" nicht mehr erraten lässt.
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

$demoCommunityId = null;
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
    $demoCommunityId = $member['community_id'];
    $inserted = DB::execute(
        'INSERT INTO user_roles (community_id, user_id, role, member_id) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING',
        [$member['community_id'], $demoUser['id'], 'member', $member['id']]
    );
    fwrite(STDERR, $inserted > 0
        ? "[assign_demo_member_roles] \"{$t['first_name']} {$t['last_name']}\" verknüpft.\n"
        : "[assign_demo_member_roles] \"{$t['first_name']} {$t['last_name']}\" war bereits korrekt verknüpft.\n");
}

// platform_admin + manager selbst anlegen, falls sie (noch) fehlen. Bewusst erst per SELECT
// prüfen (nicht blind INSERT ... ON CONFLICT DO NOTHING mit einer selbst gewählten
// community_id) -- die bestehende Zeile könnte (z.B. weil schon über die Admin-Oberfläche
// angelegt) eine ANDERE community_id tragen, was sonst zu einer zweiten, funktional
// überflüssigen Rollenzeile führen würde (der Unique-Index erlaubt platform_admin/manager
// grundsätzlich je EEG, nicht plattformweit nur einmal).
//
// community_id für platform_admin bewusst NICHT NULL: rein funktional bräuchte
// Auth::isPlatformAdmin() das nicht (prüft nur die Rolle, keine Community), ABER
// manager_dashboard.php (dorthin führt /portal/dashboard für JEDEN platform_admin, weil
// Auth::isManager() auch für platform_admin true ist) braucht zwingend eine aktive Community
// und stürzt sonst mit einem Fatal Error ab (Patrick, 06.09.2026, per Screenshot -- exakt dieser
// Fall, weil ein früherer Lauf dieses Skripts community_id=NULL gesetzt hatte). Genau wie beim
// manuellen Anlegen über die Admin-Oberfläche deshalb dieselbe Community wie die
// Mitglied-Identitäten verwenden.
$managerCommunityId = $demoCommunityId ?? ($communities[0]['id'] ?? null);
if (!$managerCommunityId) {
    fwrite(STDERR, "[assign_demo_member_roles] Keine EEG in der Datenbank gefunden -- platform_admin/manager können ohne mindestens eine EEG nicht sinnvoll geprüft werden.\n");
} else {
    $adminRole = DB::fetchOne("SELECT id, community_id FROM user_roles WHERE user_id = ? AND role = 'platform_admin' LIMIT 1", [$demoUser['id']]);
    if ($adminRole && $adminRole['community_id'] === null) {
        // Reparatur eines früheren, fehlerhaften Laufs (community_id=NULL -> Absturz).
        DB::execute('UPDATE user_roles SET community_id = ? WHERE id = ?', [$managerCommunityId, $adminRole['id']]);
        fwrite(STDERR, "[assign_demo_member_roles] Rolle \"platform_admin\" hatte keine EEG zugewiesen (führte zum Absturz von /portal/dashboard) -- repariert.\n");
    } elseif ($adminRole) {
        fwrite(STDERR, "[assign_demo_member_roles] Rolle \"platform_admin\" war bereits vorhanden.\n");
    } else {
        DB::execute('INSERT INTO user_roles (community_id, user_id, role) VALUES (?, ?, ?)', [$managerCommunityId, $demoUser['id'], 'platform_admin']);
        fwrite(STDERR, "[assign_demo_member_roles] Rolle \"platform_admin\" angelegt.\n");
    }

    $hasManager = DB::fetchOne("SELECT 1 AS x FROM user_roles WHERE user_id = ? AND role = 'manager'", [$demoUser['id']]);
    if ($hasManager) {
        fwrite(STDERR, "[assign_demo_member_roles] Rolle \"manager\" war bereits vorhanden.\n");
    } else {
        DB::execute('INSERT INTO user_roles (community_id, user_id, role) VALUES (?, ?, ?)', [$managerCommunityId, $demoUser['id'], 'manager']);
        fwrite(STDERR, "[assign_demo_member_roles] Rolle \"manager\" angelegt.\n");
    }
}

// Diagnose: den TATSÄCHLICHEN Rollenstand aus der DB ausgeben, statt ihn zu erraten.
$finalRoles = DB::fetchAll(
    'SELECT ur.role, ur.member_id, c.name AS community_name
     FROM user_roles ur LEFT JOIN communities c ON c.id = ur.community_id
     WHERE ur.user_id = ? ORDER BY ur.role',
    [$demoUser['id']]
);
fwrite(STDERR, "[assign_demo_member_roles] Aktueller Rollenstand für {$demoUser['email']}:\n");
foreach ($finalRoles as $r) {
    $extra = $r['member_id'] ? " (member_id={$r['member_id']})" : '';
    fwrite(STDERR, "  - {$r['role']} @ " . ($r['community_name'] ?? '(keine EEG)') . "{$extra}\n");
}

fwrite(STDERR, "[assign_demo_member_roles] Fertig. Beim nächsten Login (bzw. nach Neuladen, falls der Demo-Account gerade eingeloggt ist) stehen alle vier Rollen im Rollen-Dropdown zur Auswahl.\n");
