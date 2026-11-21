<?php

declare(strict_types=1);

// Statuten-Generator gegen die echte Datenbank, als Laufzeit-Rolle eeg_app (plattformweite
// Tabellen ohne RLS): Zugriff ohne bzw. mit falschem Token, Zustände, Löschkonzept.

use App\Domain\Statutes\StatuteException;
use App\Domain\Statutes\StatuteInviteService;
use App\Domain\Statutes\StatutePdfRenderer;
use App\Infrastructure\Database\PdoStatuteInviteRepository;

$stApp = testPdo('TEST_APP_USER');
$stRepo = new PdoStatuteInviteRepository($stApp);
$stDir = sys_get_temp_dir() . '/statute-int-' . bin2hex(random_bytes(4));
mkdir($stDir);
$stRenderer = new class implements StatutePdfRenderer {
    public function render(string $document, array $answers, int $version, bool $draft): string
    {
        return '%PDF-1.4 ' . $document;
    }
};
$stNow = new \DateTimeImmutable('now');
$stClock = function () use (&$stNow): \DateTimeImmutable { return $stNow; };
$stSvc = new StatuteInviteService($stRepo, $stRenderer, $stDir, \Closure::fromCallable($stClock));
$stInput = [
    'name' => 'Erneuerbare-Energie-Gemeinschaft Integration', 'seat' => 'Testort', 'state' => 'kaernten',
    'grid_operator' => 'Testnetz GmbH', 'founders_count' => 2, 'area' => 'lokal',
];

test('Statuten-DB: App-Rolle legt Einladung an, gespeichert wird nur der Hash', function () use ($stSvc, $stApp) {
    $r = $stSvc->create('Integration A', 30, ['area' => 'lokal'], null);
    $row = $stApp->query('SELECT * FROM statute_invites WHERE id = ' . $stApp->quote($r['id']))->fetch();
    assertSame(hash('sha256', $r['token']), $row['token_hash']);
    assertSame('open', $row['status']);
    assertFalse(str_contains(json_encode($row), $r['token']));
    assertSame('lokal', json_decode($row['prefill'], true)['area']);
});

test('Statuten-DB: ohne Token, mit falschem oder manipuliertem Token kein Zugriff', function () use ($stSvc, $stRepo) {
    $r = $stSvc->create('Integration B', 30, [], null);
    assertSame(null, $stSvc->resolve(''));
    assertSame(null, $stSvc->resolve(StatuteInviteService::newToken()));
    assertSame(null, $stSvc->resolve(substr($r['token'], 0, 42) . (substr($r['token'], -1) === 'A' ? 'B' : 'A')));
    assertSame(null, $stSvc->resolve("' OR 1=1 --"));
    assertSame(null, $stRepo->findInviteByHash("' OR '1'='1"));
    assertSame(null, $stRepo->findInvite('kein-uuid'));
    assertSame(null, $stRepo->version('kein-uuid', 1));
    assertSame($r['id'], $stSvc->resolve($r['token'])['id']);
});

test('Statuten-DB: offen → erzeugt → nur Download, Fassungen und Freigabe', function () use ($stSvc, $stRepo, $stInput, $stDir) {
    $r = $stSvc->create('Integration C', 30, [], null);
    $inv = $stSvc->resolve($r['token']);
    $stSvc->saveByLink($inv, $stInput + ['founders' => "Person X; Obmann"]);
    assertSame('Testort', $stSvc->currentAnswers($inv)['seat']);
    assertSame(1, $stSvc->generateByLink($inv, $stInput));
    $inv = $stSvc->resolve($r['token']);
    assertSame('generated', $stSvc->state($inv));
    assertTrue($inv['used_at'] !== null);
    $v = $stRepo->versions($r['id']);
    assertSame(1, (int) $v[0]['version']);
    assertTrue(is_file($stDir . '/' . $v[0]['pdf_path'] . '/statuten.pdf'));
    $threw = false;
    try { $stSvc->saveByLink($inv, $stInput); } catch (StatuteException) { $threw = true; }
    assertTrue($threw, 'Fragebogen nach dem Erzeugen gesperrt');
    $stSvc->release($r['id'], 1, null);
    assertTrue($stRepo->version($r['id'], 1)['released_at'] !== null);
});

test('Statuten-DB: widerrufen und neuer Link', function () use ($stSvc) {
    $r = $stSvc->create('Integration D', 30, [], null);
    $new = $stSvc->rotate($r['id']);
    assertSame(null, $stSvc->resolve($r['token']));
    $stSvc->revoke($r['id']);
    assertSame('revoked', $stSvc->state($stSvc->resolve($new)));
});

test('Statuten-DB: Löschkonzept entfernt Einladungen nach 12 Monaten samt Entwürfen', function () use ($stSvc, $stApp, &$stNow, $stInput) {
    $r = $stSvc->create('Integration E', 30, [], null);
    $stSvc->generateByLink($stSvc->resolve($r['token']), $stInput);
    $before = (int) $stApp->query('SELECT count(*) FROM statute_drafts')->fetchColumn();
    assertTrue($before > 0);
    $stNow = $stNow->modify('+14 months'); // 12 Monate nach der letzten Aktivität (Ablauf bis +30 Tage)
    assertTrue($stSvc->purgeStale() >= 1);
    assertSame(0, (int) $stApp->query('SELECT count(*) FROM statute_invites')->fetchColumn());
    assertSame(0, (int) $stApp->query('SELECT count(*) FROM statute_drafts')->fetchColumn());
});
