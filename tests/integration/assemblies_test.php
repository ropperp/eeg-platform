<?php

declare(strict_types=1);

// Versammlungsprotokolle gegen die echte Datenbank, als Laufzeit-Rolle mit RLS: Ablauf
// Entwurf -> Unterschrift (vor Ort + Link) -> abgeschlossen, Sperren, Mandantentrennung.

use App\Domain\Assembly\AssemblyException;
use App\Domain\Assembly\AssemblyPdfRenderer;
use App\Domain\Assembly\AssemblyService;
use App\Domain\Assembly\AssemblySignLinkMailer;
use App\Infrastructure\Database\PdoAssemblyRepository;

$asOwner = testPdo('TEST_DB_OWNER');
$asSuffix = bin2hex(random_bytes(4));
$asCommunity = function (string $name) use ($asOwner, $asSuffix): string {
    $cid = $asOwner->query("SELECT uuid_generate_v4()")->fetchColumn();
    $asOwner->prepare("INSERT INTO communities (id, name, slug) VALUES (?, ?, ?)")->execute([$cid, "AS {$name}", "as-{$name}-{$asSuffix}"]);
    return $cid;
};
$asA = $asCommunity('a');
$asB = $asCommunity('b');

$asApp = testPdo('TEST_APP_USER');
$asApp->exec("SET app.community_id = " . $asApp->quote($asA));
$asRepo = new PdoAssemblyRepository($asApp);

$asRenderer = new class implements AssemblyPdfRenderer {
    public int $calls = 0;
    public bool $fail = false;
    public function render(array $assembly, array $items, array $signers, array $community, bool $draft): string
    {
        $this->calls++;
        if ($this->fail) {
            throw new RuntimeException('latex-service weg');
        }
        return "%PDF-1.4 test " . count($items) . ' ' . count($signers);
    }
};
$asMailer = new class implements AssemblySignLinkMailer {
    public string $lastUrl = '';
    public function send(array $signer, array $assembly, string $communityName, string $url, string $expiresAt): void
    {
        $this->lastUrl = $url;
    }
};
$asStorage = sys_get_temp_dir() . '/asm-test-' . $asSuffix;
$asService = new AssemblyService($asRepo, $asRenderer, $asMailer, $asStorage);
$asPng = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 20));
$asCommunityRow = ['name' => 'AS a'];

test('Versammlung: Entwurf bearbeiten, Punkte sortieren, freigeben sperrt den Inhalt', function () use ($asRepo, $asService, $asA) {
    $id = $asService->create($asA, null, ['held_on' => '2026-11-14', 'attendees_count' => '12']);
    $a = $asRepo->find($id, $asA);
    assertSame('draft', $a['status']);
    $p1 = $asService->addItem($a, ['title' => 'Begrüßung']);
    $p2 = $asService->addItem($a, ['title' => 'Kassabericht', 'votes_yes' => '10']);
    $asService->moveItem($a, $p2, 'up');
    assertSame(['Kassabericht', 'Begrüßung'], array_map(fn($i) => $i['title'], $asRepo->items($id)));
    $asService->updateItem($a, $p1, ['title' => 'Begrüßung und Feststellung der Beschlussfähigkeit']);
    try {
        $asService->startSigning($a);
        throw new Exception('Freigabe ohne Unterzeichner wurde nicht abgelehnt');
    } catch (AssemblyException $e) {
        assertContains('unterschreibt', $e->getMessage());
    }
    $asService->addSigner($a, ['name' => 'Obfrau Test', 'role_label' => 'Obfrau']);
    $asService->startSigning($a);
    $a = $asRepo->find($id, $asA);
    assertSame('signing', $a['status']);
    try {
        $asService->addItem($a, ['title' => 'nachträglich']);
        throw new Exception('Bearbeiten nach Freigabe wurde nicht abgelehnt');
    } catch (AssemblyException $e) {
        assertContains('nicht mehr bearbeitet', $e->getMessage());
    }
    $asService->backToDraft($a);
    assertSame('draft', $asRepo->find($id, $asA)['status']);
});

test('Versammlung: vor Ort + per Link unterschreiben, danach PDF abgelegt und gesperrt', function () use ($asRepo, $asService, $asA, $asApp, $asRenderer, $asMailer, $asPng, $asCommunityRow) {
    $id = $asService->create($asA, null, ['held_on' => '2026-11-15']);
    $a = $asRepo->find($id, $asA);
    $asService->addItem($a, ['title' => 'TOP 1']);
    $s1 = $asService->addSigner($a, ['name' => 'Obmann', 'role_label' => 'Obmann']);
    $s2 = $asService->addSigner($a, ['name' => 'Schriftführerin', 'role_label' => 'Schriftführerin', 'email' => 'sf@example.invalid']);
    $asService->startSigning($a);
    $a = $asRepo->find($id, $asA);

    assertFalse($asService->signOnSite($a, $s1, $asPng, '127.0.0.1', $asCommunityRow), 'noch nicht alle');
    try {
        $asService->backToDraft($a);
        throw new Exception('Zurück in den Entwurf trotz Unterschrift');
    } catch (AssemblyException $e) {
        assertContains('bereits unterschrieben', $e->getMessage());
    }

    $asService->sendSignLink($a, $s2, 'https://portal.example.invalid', 'AS a');
    $token = basename($asMailer->lastUrl);
    assertTrue((bool) preg_match('/^[0-9a-f]{64}$/', $token), 'Token im Link');
    $stored = $asRepo->findSigner($s2, $id)['sign_token_hash'];
    assertSame(hash('sha256', $token), $stored, 'nur der Hash ist gespeichert');

    // Öffentlicher Aufruf: ohne app.community_id liefert RLS nichts -- die Token-Funktion schon.
    $asApp->exec("RESET app.community_id");
    $link = $asService->resolveLink($token);
    assertSame($id, $link['assembly_id']);
    assertSame(null, $asService->resolveLink(str_repeat('0', 64)));
    assertSame(null, $asService->resolveLink('kein-token'));
    $asApp->exec("SET app.community_id = " . $asApp->quote($link['community_id']));

    $asRenderer->fail = true;
    try {
        $asService->signByLink($link, $asPng, '127.0.0.1', $asCommunityRow);
        throw new Exception('PDF-Fehler wurde verschluckt');
    } catch (RuntimeException $e) {
        assertContains('latex-service', $e->getMessage());
    }
    $a = $asRepo->find($id, $asA);
    assertSame('signing', $a['status'], 'ohne PDF bleibt es offen');
    assertTrue($asRepo->findSigner($s2, $id)['signed_at'] !== null, 'Unterschrift trotzdem gespeichert');
    assertSame(null, $asService->resolveLink($token), 'Link nach Benutzung ungültig');

    $asRenderer->fail = false;
    assertTrue($asService->finalizeIfComplete($a, $asCommunityRow));
    $a = $asRepo->find($id, $asA);
    assertSame('final', $a['status']);
    assertSame("%PDF-1.4 test 1 2", file_get_contents($asService->pdfFile($a)));
    assertFalse(AssemblyService::isDeletable($a), 'unterschriebenes Protokoll bleibt');
    assertSame(1, count(array_filter($asRepo->publishedForCommunity($asA), fn($x) => $x['id'] === $id)));
    $asService->setVisibility($a, false);
    assertSame(0, count(array_filter($asRepo->publishedForCommunity($asA), fn($x) => $x['id'] === $id)));
});

test('Versammlung: hochgeladenes PDF wird geprüft, ist sofort abgeschlossen und löschbar', function () use ($asRepo, $asService, $asA, $asSuffix) {
    $tmp = tempnam(sys_get_temp_dir(), 'asm');
    file_put_contents($tmp, 'kein pdf');
    try {
        $asService->uploadFinal($asA, null, ['held_on' => '2025-11-10'], $tmp, filesize($tmp));
        throw new Exception('Nicht-PDF angenommen');
    } catch (AssemblyException $e) {
        assertContains('kein PDF', $e->getMessage());
    }
    file_put_contents($tmp, "%PDF-1.7\nscan");
    $id = $asService->uploadFinal($asA, null, ['held_on' => '2025-11-10'], $tmp, filesize($tmp));
    $a = $asRepo->find($id, $asA);
    assertSame(['final', 'upload'], [$a['status'], $a['source']]);
    $file = $asService->pdfFile($a);
    assertTrue($file !== null);
    $asService->delete($a);
    assertSame(null, $asRepo->find($id, $asA));
    assertFalse(is_file($file), 'Datei mitgelöscht');
    unlink($tmp);
});

test('Versammlung: Protokolle einer fremden Community sind unsichtbar und nicht anlegbar', function () use ($asRepo, $asOwner, $asB) {
    $q = $asOwner->prepare("INSERT INTO assemblies (community_id, held_on) VALUES (?, '2026-01-01') RETURNING id");
    $q->execute([$asB]);
    $foreign = $q->fetchColumn();
    assertSame(null, $asRepo->find($foreign, $asB));
    assertSame([], $asRepo->forCommunity($asB));
    assertSame([], $asRepo->items($foreign));
    try {
        $asRepo->create($asB, null, ['held_on' => '2026-01-02']);
        throw new Exception('Anlegen in fremder Community wurde nicht abgelehnt');
    } catch (PDOException $e) {
        assertContains('row-level security', $e->getMessage());
    }
});
