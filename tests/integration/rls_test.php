<?php

declare(strict_types=1);

// Mandantentrennung: Die Laufzeitrolle der Webapp sieht und ändert nur Daten ihrer Community.

$owner = testPdo('TEST_DB_OWNER');
$suffix = bin2hex(random_bytes(4));

/** Legt eine Community mit einem Mitglied und einem Zählpunkt an (als Schema-Besitzer). */
$seed = function (string $name) use ($owner, $suffix): array {
    $cid = $owner->query("SELECT uuid_generate_v4()")->fetchColumn();
    $owner->prepare("INSERT INTO communities (id, name, slug) VALUES (?, ?, ?)")
        ->execute([$cid, "RLS-Test {$name}", "rls-{$name}-{$suffix}"]);
    $stmt = $owner->prepare(
        "INSERT INTO members (community_id, first_name, last_name, address, zip, city, email)
         VALUES (?, 'Test', ?, 'Teststraße 1', '1000', 'Teststadt', ?) RETURNING id"
    );
    $stmt->execute([$cid, $name, "rls-{$name}-{$suffix}@example.invalid"]);
    $mid = $stmt->fetchColumn();
    $stmt = $owner->prepare(
        "INSERT INTO metering_points (community_id, zaehlpunkt_nr, type) VALUES (?, ?, 'consumer') RETURNING id"
    );
    $stmt->execute([$cid, 'AT00' . strtoupper($name) . $suffix]);
    return ['community' => $cid, 'member' => $mid, 'mp' => $stmt->fetchColumn()];
};

$a = $seed('a');
$b = $seed('b');

$app = testPdo('TEST_APP_USER');
$app->exec("SET app.community_id = " . $app->quote($a['community']));

test('RLS: Laufzeitrolle ist weder Superuser noch BYPASSRLS noch Tabellenbesitzer', function () use ($app) {
    assertSame([], DB::runtimeRoleProblems($app));
});

test('RLS: Prüfung erkennt Superuser und Dienstkonto als ungeeignet', function () use ($owner) {
    assertTrue(count(DB::runtimeRoleProblems($owner)) > 0, 'Schema-Besitzer');
    $worker = testPdo('TEST_WORKER_USER');
    assertContains('BYPASSRLS', implode(' ', DB::runtimeRoleProblems($worker)));
});

test('RLS: Community A liest nur eigene Mitglieder und Zählpunkte', function () use ($app, $a, $b) {
    $ids = $app->query("SELECT id FROM members")->fetchAll(PDO::FETCH_COLUMN);
    assertTrue(in_array($a['member'], $ids, true), 'eigenes Mitglied fehlt');
    assertFalse(in_array($b['member'], $ids, true), 'fremdes Mitglied sichtbar');
    $stmt = $app->prepare("SELECT count(*) FROM metering_points WHERE id = ?");
    $stmt->execute([$b['mp']]);
    assertSame(0, (int) $stmt->fetchColumn());
});

test('RLS: Community A kann Daten von B weder ändern noch löschen', function () use ($app, $owner, $b) {
    $stmt = $app->prepare("UPDATE members SET last_name = 'gehackt' WHERE id = ?");
    $stmt->execute([$b['member']]);
    assertSame(0, $stmt->rowCount(), 'UPDATE');
    $stmt = $app->prepare("DELETE FROM metering_points WHERE id = ?");
    $stmt->execute([$b['mp']]);
    assertSame(0, $stmt->rowCount(), 'DELETE');
    $check = $owner->prepare("SELECT last_name FROM members WHERE id = ?");
    $check->execute([$b['member']]);
    assertSame('b', $check->fetchColumn());
});

test('RLS: Community A kann keine Zeile für Community B anlegen', function () use ($app, $b) {
    try {
        $app->prepare(
            "INSERT INTO members (community_id, first_name, last_name, address, zip, city, email)
             VALUES (?, 'X', 'X', 'X', '1', 'X', 'x@example.invalid')"
        )->execute([$b['community']]);
        throw new Exception('INSERT in fremde Community wurde nicht abgelehnt');
    } catch (PDOException $e) {
        assertContains('row-level security', $e->getMessage());
    }
});

test('RLS: ohne gesetzte Community sieht die Laufzeitrolle nichts', function () {
    $fresh = testPdo('TEST_APP_USER');
    assertSame(0, (int) $fresh->query("SELECT count(*) FROM members")->fetchColumn());
});

test('Dienstkonto: schreibt community-übergreifend Messwerte (auch neuer Hypertable-Chunk)', function () use ($a, $b) {
    $worker = testPdo('TEST_WORKER_USER');
    $ins = $worker->prepare(
        "INSERT INTO esp_measurements (time, community_id, metering_point_id, power_bezug_w)
         VALUES (now() + interval '400 days', ?, ?, 100)"
    );
    $ins->execute([$a['community'], $a['mp']]);
    $ins->execute([$b['community'], $b['mp']]);
    $stmt = $worker->prepare("SELECT count(*) FROM esp_measurements WHERE metering_point_id IN (?, ?)");
    $stmt->execute([$a['mp'], $b['mp']]);
    assertSame(2, (int) $stmt->fetchColumn());
});

test('Dienstkonto: darf kein Schema ändern', function () {
    $worker = testPdo('TEST_WORKER_USER');
    try {
        $worker->exec("ALTER TABLE members ADD COLUMN rls_test_spalte int");
        throw new Exception('ALTER TABLE wurde nicht abgelehnt');
    } catch (PDOException $e) {
        assertContains('must be owner', $e->getMessage());
    }
});

// Aufräumen (Schema-Besitzer; Messwerte/Zählpunkte/Mitglieder hängen per Fremdschlüssel dran).
foreach ([$a, $b] as $c) {
    $owner->prepare("DELETE FROM esp_measurements WHERE community_id = ?")->execute([$c['community']]);
    $owner->prepare("DELETE FROM metering_points WHERE community_id = ?")->execute([$c['community']]);
    $owner->prepare("DELETE FROM members WHERE community_id = ?")->execute([$c['community']]);
    $owner->prepare("DELETE FROM communities WHERE id = ?")->execute([$c['community']]);
}
