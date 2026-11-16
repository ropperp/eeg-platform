<?php

declare(strict_types=1);

// PdoEdaImportJobRepository unter der Laufzeit-Rolle (RLS) gegen die echte Datenbank.

use App\Infrastructure\Database\PdoEdaImportJobRepository;

$ejOwner = testPdo('TEST_DB_OWNER');
$ejSuffix = bin2hex(random_bytes(4));
$ejCommunity = function (string $n) use ($ejOwner, $ejSuffix): string {
    $id = $ejOwner->query("SELECT uuid_generate_v4()")->fetchColumn();
    $ejOwner->prepare("INSERT INTO communities (id, name, slug) VALUES (?, ?, ?)")->execute([$id, "EJ {$n}", "ej-{$n}-{$ejSuffix}"]);
    return $id;
};
$ejA = $ejCommunity('a');
$ejB = $ejCommunity('b');
$ejApp = testPdo('TEST_APP_USER');
$ejApp->exec("SET app.community_id = " . $ejApp->quote($ejA));
$ejRepo = new PdoEdaImportJobRepository($ejApp);

test('EDA-Jobs (DB): anlegen, lesen, gleiche Datei läuft nur einmal gleichzeitig', function () use ($ejRepo, $ejA, $ejOwner) {
    $id = $ejRepo->enqueue($ejA, 'monthly', 'upload', '/tmp/a.xlsx', 'a.xlsx', str_repeat('a', 64), null);
    $job = $ejRepo->find($id, $ejA);
    assertSame(['queued', 'monthly', 'upload', 0], [$job['status'], $job['kind'], $job['source'], (int) $job['attempts']]);
    assertSame($id, $ejRepo->enqueue($ejA, 'monthly', 'upload', '/tmp/a2.xlsx', 'a.xlsx', str_repeat('a', 64), null), 'Doppel-Upload');
    assertTrue($id !== $ejRepo->enqueue($ejA, 'interval', 'upload', '/tmp/a.xlsx', 'a.xlsx', str_repeat('a', 64), null), 'andere Art = eigener Job');
    // Nach Abschluss darf dieselbe Datei erneut importiert werden.
    $ejOwner->prepare("UPDATE eda_import_jobs SET status = 'done', result = '{\"records\": 5}' WHERE id = ?")->execute([$id]);
    assertSame(['records' => 5], $ejRepo->find($id, $ejA)['result']);
    assertTrue($id !== $ejRepo->enqueue($ejA, 'monthly', 'upload', '/tmp/a.xlsx', 'a.xlsx', str_repeat('a', 64), null));
    assertSame(null, $ejRepo->find('keine-uuid', $ejA));
});

test('EDA-Jobs (DB): Jobs einer fremden EEG sind unsichtbar und nicht anlegbar', function () use ($ejRepo, $ejOwner, $ejB) {
    $q = $ejOwner->prepare("INSERT INTO eda_import_jobs (community_id, kind, source, file_path, original_name, file_sha256)
        VALUES (?, 'monthly', 'upload', '/tmp/b.xlsx', 'b.xlsx', ?) RETURNING id");
    $q->execute([$ejB, str_repeat('b', 64)]);
    assertSame(null, $ejRepo->find($q->fetchColumn(), $ejB));
    try {
        $ejRepo->enqueue($ejB, 'monthly', 'upload', '/tmp/x.xlsx', 'x.xlsx', str_repeat('c', 64), null);
        throw new Exception('Anlegen für fremde EEG wurde nicht abgelehnt');
    } catch (PDOException $e) {
        assertContains('row-level security', $e->getMessage());
    }
});

foreach ([$ejA, $ejB] as $c) {
    $ejOwner->prepare("DELETE FROM eda_import_jobs WHERE community_id = ?")->execute([$c]);
    $ejOwner->prepare("DELETE FROM communities WHERE id = ?")->execute([$c]);
}
