<?php

declare(strict_types=1);

// Statuten-Generator: Token und Zustände der Einladung (offen → erzeugt → nur Download;
// abgelaufen; widerrufen), mit Fake-Repository und Fake-PDF-Renderer (ohne DB/latex-service).

use App\Domain\Statutes\StatuteException;
use App\Domain\Statutes\StatuteInviteRepository;
use App\Domain\Statutes\StatuteInviteService as SIS;
use App\Domain\Statutes\StatutePdfRenderer;
use App\Domain\Statutes\StatuteQuestionnaire as SQ2;
use App\Infrastructure\Pdf\LatexStatutePdfRenderer;

final class FakeStatuteRepo implements StatuteInviteRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $invites = [];
    /** @var list<array<string, mixed>> */
    public array $drafts = [];
    private int $seq = 0;

    public function insertInvite(array $data): string
    {
        $id = sprintf('00000000-0000-4000-8000-%012d', ++$this->seq);
        $this->invites[$id] = $data + ['id' => $id, 'status' => 'open', 'used_at' => null, 'revoked_at' => null,
            'created_at' => date(DATE_ATOM), 'prefill' => json_encode($data['prefill'])];
        $this->invites[$id]['prefill'] = json_encode($data['prefill']);
        return $id;
    }
    public function findInvite(string $id): ?array { return $this->invites[$id] ?? null; }
    public function findInviteByHash(string $h): ?array
    {
        foreach ($this->invites as $i) {
            if ($i['token_hash'] === $h) {
                return $i;
            }
        }
        return null;
    }
    public function listInvites(): array { return array_values($this->invites); }
    public function updateInvite(string $id, array $fields): void { $this->invites[$id] = $fields + $this->invites[$id]; }
    public function workingDraft(string $inviteId): ?array
    {
        foreach ($this->drafts as $d) {
            if ($d['invite_id'] === $inviteId && $d['version'] === 0) {
                return $d;
            }
        }
        return null;
    }
    public function saveWorkingDraft(string $inviteId, array $answers): void
    {
        foreach ($this->drafts as &$d) {
            if ($d['invite_id'] === $inviteId && $d['version'] === 0) {
                $d['answers'] = json_encode($answers);
                return;
            }
        }
        $this->drafts[] = ['id' => 'w' . $inviteId, 'invite_id' => $inviteId, 'version' => 0, 'answers' => json_encode($answers), 'pdf_path' => null];
    }
    public function versions(string $inviteId): array
    {
        $v = array_values(array_filter($this->drafts, fn($d) => $d['invite_id'] === $inviteId && $d['version'] > 0));
        usort($v, fn($a, $b) => $b['version'] <=> $a['version']);
        return $v;
    }
    public function version(string $inviteId, int $version): ?array
    {
        foreach ($this->versions($inviteId) as $d) {
            if ($d['version'] === $version) {
                return $d;
            }
        }
        return null;
    }
    public function insertVersion(string $inviteId, int $version, array $answers, string $pdfPath, ?string $by): string
    {
        $id = 'd' . count($this->drafts);
        $this->drafts[] = ['id' => $id, 'invite_id' => $inviteId, 'version' => $version, 'answers' => json_encode($answers),
            'pdf_path' => $pdfPath, 'generated_by' => $by, 'generated_at' => '2026-11-22T10:00:00+00:00', 'released_at' => null];
        return $id;
    }
    public function markReleased(string $draftId, ?string $userId): void
    {
        foreach ($this->drafts as &$d) {
            if ($d['id'] === $draftId) {
                $d['released_at'] = date(DATE_ATOM);
            }
        }
    }
    public function staleInvites(string $cutoff): array
    {
        // wie die SQL-Fassung: letzte Erzeugung, sonst Ablaufdatum
        $last = function (array $i): string {
            $gen = array_filter(array_map(fn($d) => $d['invite_id'] === $i['id'] ? ($d['generated_at'] ?? null) : null, $this->drafts));
            return $gen ? max($gen) : $i['expires_at'];
        };
        return array_values(array_map(fn($i) => ['id' => $i['id']], array_filter($this->invites, fn($i) => $last($i) < $cutoff)));
    }
    public function deleteInvite(string $id): void
    {
        unset($this->invites[$id]);
        $this->drafts = array_values(array_filter($this->drafts, fn($d) => $d['invite_id'] !== $id));
    }
}

final class FakeStatuteRenderer implements StatutePdfRenderer
{
    /** @var list<string> */
    public array $calls = [];
    public function render(string $document, array $answers, int $version, bool $draft): string
    {
        $this->calls[] = $document . ($draft ? ':draft' : ':final');
        return '%PDF-fake ' . $document;
    }
}

$statuteEnv = function (string $now = '2026-11-22 10:00:00') {
    $repo = new FakeStatuteRepo();
    $renderer = new FakeStatuteRenderer();
    $dir = sys_get_temp_dir() . '/statute-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $clock = new class ($now) {
        public \DateTimeImmutable $now;
        public function __construct(string $n) { $this->now = new \DateTimeImmutable($n); }
    };
    $svc = new SIS($repo, $renderer, $dir, fn() => $clock->now);
    return [$svc, $repo, $renderer, $clock, $dir];
};
$statuteInput = fn(array $over = []) => array_merge([
    'name' => 'Erneuerbare-Energie-Gemeinschaft Musterdorf', 'seat' => 'Musterdorf', 'state' => 'kaernten',
    'grid_operator' => 'Musternetz GmbH', 'founders_count' => 2, 'area' => 'regional',
], $over);

test('Token: 256 Bit base64url, nur der Hash wird gespeichert', function () use ($statuteEnv) {
    [$svc, $repo] = $statuteEnv();
    $r = $svc->create('Interessent A', 30, ['area' => 'lokal', 'name' => 'nicht erlaubt'], null);
    assertSame(43, strlen($r['token']));
    assertTrue(SIS::wellFormed($r['token']));
    $stored = $repo->invites[$r['id']];
    assertSame(hash('sha256', $r['token']), $stored['token_hash']);
    assertFalse(str_contains(json_encode($stored), $r['token']), 'Klartext-Token darf nicht gespeichert werden');
    assertSame(['area' => 'lokal'], json_decode($stored['prefill'], true));
});

test('Token: unbekannt, falsch geformt oder leer ergibt null', function () use ($statuteEnv) {
    [$svc] = $statuteEnv();
    $svc->create('A', 30, [], null);
    assertSame(null, $svc->resolve(''));
    assertSame(null, $svc->resolve('../../etc/passwd'));
    assertSame(null, $svc->resolve(SIS::newToken()));
});

test('Zustand: offen → erzeugt (nur Download, 90 Tage) → abgelaufen', function () use ($statuteEnv, $statuteInput) {
    [$svc, $repo, $renderer, $clock, $dir] = $statuteEnv();
    $r = $svc->create('A', 30, [], null);
    $inv = $svc->resolve($r['token']);
    assertSame('open', $svc->state($inv));

    $svc->saveByLink($inv, $statuteInput(['name' => '']));        // Zwischenspeichern ohne Pflichtfelder geht
    assertSame('', $svc->currentAnswers($inv)['name']);

    $version = $svc->generateByLink($inv, $statuteInput());
    assertSame(1, $version);
    assertSame(['statuten:draft', 'anzeige:draft', 'protokoll:draft'], $renderer->calls);
    $inv = $svc->resolve($r['token']);
    assertSame('generated', $svc->state($inv));
    assertTrue($inv['used_at'] !== null);
    assertSame('2027-02-20', substr($inv['expires_at'], 0, 10));
    assertTrue($svc->pdfFile($repo->versions($inv['id'])[0], 'statuten') !== null);
    assertSame(null, $svc->pdfFile($repo->versions($inv['id'])[0], '../x'));

    // Fragebogen über den Link gesperrt
    $threw = false;
    try { $svc->saveByLink($inv, $statuteInput()); } catch (StatuteException) { $threw = true; }
    assertTrue($threw, 'Speichern nach dem Erzeugen muss scheitern');
    $threw = false;
    try { $svc->generateByLink($inv, $statuteInput()); } catch (StatuteException) { $threw = true; }
    assertTrue($threw, 'Erneutes Erzeugen über den Link muss scheitern');

    $clock->now = new \DateTimeImmutable('2027-02-21 00:00:00');
    assertSame('expired', $svc->state($svc->resolve($r['token'])));
});

test('Zustand: offene Einladung läuft nach Ablaufdatum ab', function () use ($statuteEnv, $statuteInput) {
    [$svc, , , $clock] = $statuteEnv();
    $r = $svc->create('A', 30, [], null);
    $clock->now = new \DateTimeImmutable('2026-12-23 00:00:00');
    $inv = $svc->resolve($r['token']);
    assertSame('expired', $svc->state($inv));
    $threw = false;
    try { $svc->saveByLink($inv, $statuteInput()); } catch (StatuteException) { $threw = true; }
    assertTrue($threw);
});

test('Zustand: widerrufen sperrt Link, Download und Admin-Bearbeitung', function () use ($statuteEnv, $statuteInput) {
    [$svc] = $statuteEnv();
    $r = $svc->create('A', 30, [], null);
    $svc->generateByLink($svc->resolve($r['token']), $statuteInput());
    $svc->revoke($r['id']);
    assertSame('revoked', $svc->state($svc->resolve($r['token'])));
    $threw = false;
    try { $svc->generateByAdmin($r['id'], $statuteInput(), 'admin'); } catch (StatuteException) { $threw = true; }
    assertTrue($threw);
    $threw = false;
    try { $svc->rotate($r['id']); } catch (StatuteException) { $threw = true; }
    assertTrue($threw);
});

test('Erzeugen scheitert an Prüfregeln, der Stand bleibt gespeichert', function () use ($statuteEnv, $statuteInput) {
    [$svc, , $renderer] = $statuteEnv();
    $r = $svc->create('A', 30, [], null);
    $inv = $svc->resolve($r['token']);
    try {
        $svc->generateByLink($inv, $statuteInput(['board_size' => 1, 'seat' => 'Gespeichert']));
        throw new Exception('Fehler erwartet');
    } catch (StatuteException $e) {
        assertTrue(count($e->errors) >= 1);
    }
    assertSame([], $renderer->calls);
    assertSame('open', $svc->state($svc->resolve($r['token'])));
    assertSame('Gespeichert', $svc->currentAnswers($inv)['seat']);
});

test('Neuer Link: alter Token ungültig, neuer gültig', function () use ($statuteEnv) {
    [$svc] = $statuteEnv();
    $r = $svc->create('A', 30, [], null);
    $new = $svc->rotate($r['id']);
    assertSame(null, $svc->resolve($r['token']));
    assertTrue($svc->resolve($new) !== null);
});

test('Admin: neue Fassung und Freigabe ohne Entwurfs-Kopfzeile', function () use ($statuteEnv, $statuteInput) {
    [$svc, $repo, $renderer] = $statuteEnv();
    $r = $svc->create('A', 30, [], null);
    $svc->generateByLink($svc->resolve($r['token']), $statuteInput());
    assertSame(2, $svc->generateByAdmin($r['id'], $statuteInput(['board_size' => 3]), 'admin'));
    $inv = $svc->resolve($r['token']);
    assertSame('generated', $svc->state($inv));
    assertSame(2, $repo->versions($r['id'])[0]['version']);
    $renderer->calls = [];
    $svc->release($r['id'], 2, 'admin');
    assertSame(['statuten:final', 'anzeige:final', 'protokoll:final'], $renderer->calls);
    assertTrue($repo->version($r['id'], 2)['released_at'] !== null);
});

test('Löschkonzept: Einladungen 12 Monate nach letzter Aktivität samt Dateien löschen', function () use ($statuteEnv, $statuteInput) {
    [$svc, $repo, , $clock, $dir] = $statuteEnv();
    $r = $svc->create('A', 30, [], null);
    $svc->generateByLink($svc->resolve($r['token']), $statuteInput());
    assertTrue(is_dir($dir . '/' . $r['id']));
    $clock->now = new \DateTimeImmutable('2027-12-01 00:00:00');
    assertSame(1, $svc->purgeStale());
    assertSame([], $repo->invites);
    assertFalse(is_dir($dir . '/' . $r['id']));
});

test('LaTeX: Statuten-Variablen escaped, Entwurfs-Kopfzeile, Rechtsgrundlage, Querverweise nur per \\ref', function () {
    $a = SQ2::normalize(['name' => 'EEG A & B_%', 'seat' => 'Musterdorf', 'state' => 'kaernten', 'grid_operator' => 'Netz $ GmbH',
        'founders_count' => 2, 'board_size' => 2, 'has_secretary' => '', 'deputy_chair' => '']);
    $v = LatexStatutePdfRenderer::statuteVars($a, 1, true);
    assertSame('Entwurf -- keine Rechtsberatung', $v['RAW_ENTWURF']);
    assertSame('', LatexStatutePdfRenderer::statuteVars($a, 1, false)['RAW_ENTWURF']);
    assertContains('der Kassier', $v['RAW_STELLVERTRETUNG']);
    assertContains('\\ref{abs:vieraugen}', $v['RAW_STELLVERTRETUNG']);
    foreach ($v as $k => $val) {
        if (str_starts_with($k, 'RAW_')) {
            // Gesetzeszitate (z. B. "§ 6 Abs 4 VerG") sind erlaubt, Verweise auf eigene Paragraphen nicht.
            $rest = preg_replace('/§+[^§]*?(VerG|UStG|ElWG|EAG|BAO|DSGVO|DSG|KSchG|ZPO)/u', '', $val);
            assertFalse((bool) preg_match('/§|Absatz\s*~?\d|Abs\s*~?\d/u', (string) $rest), "händische Nummer in {$k}: {$val}");
        }
    }
    // Deckblatt: Typ und Name getrennt, ID/Netzebene, Rechtsgrundlage nach ElWG (nicht ElWOG 2010)
    $c = LatexStatutePdfRenderer::statuteVars(SQ2::normalize(['name' => 'Erneuerbare-Energie-Gemeinschaft Sonnental', 'area' => 'regional',
        'area_id' => '99R1', 'grid_level' => '5']), 1, true);
    assertSame('Sonnental', $c['DECKBLATT_NAME']);
    assertSame('Regionale', $c['NAHEBEREICH_ART']);
    assertContains('Regionale ID: 99R1', $c['RAW_DECKBLATT_ID']);
    assertContains('Netzebene~5 (Mittelspannung)', $c['RAW_DECKBLATT_ID']);
    assertSame('EEG A & B_%', $v['DECKBLATT_NAME']); // kein RAW_: escaped der latex-service
    assertSame('', LatexStatutePdfRenderer::statuteVars(SQ2::normalize(['name' => 'X', 'area' => 'lokal']), 1, true)['RAW_DECKBLATT_ID']);
    $tex = (string) file_get_contents(__DIR__ . '/../latex-service/templates/statuten.tex');
    assertContains('Erneuerbare-Energie-Gemeinschaft gemäß § 79 EAG und §§ 66 ff ElWG', $tex);
    assertFalse(str_contains($tex, 'ElWOG'), 'Vorlage verweist noch auf ElWOG 2010');
    preg_match_all('/\\\\ref\{([a-z:]+)\}/', $tex . implode(' ', $v), $refs);
    preg_match_all('/\\\\label\{([a-z:]+)\}/', $tex, $labels);
    foreach (array_unique($refs[1]) as $ref) {
        assertTrue(in_array($ref, $labels[1], true), "\\ref{{$ref}} ohne \\label");
    }
    $c = LatexStatutePdfRenderer::checklistVars(SQ2::normalize(['vat_mode' => 'regel', 'founders' => "X; Obmann"]), 1, true);
    assertContains('U12', $c['RAW_UST']);
    assertContains('X', $c['RAW_GRUENDER']);
});
