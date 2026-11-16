<?php

declare(strict_types=1);

// EdaImportJobs: Jobs anlegen und (für den Postfach-Abruf) auf das Ergebnis warten.

use App\Domain\EdaImport\EdaImportJobRepository;
use App\Domain\EdaImport\EdaImportJobs;

final class FakeEdaJobs implements EdaImportJobRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $jobs = [];
    /** @var list<array<string, mixed>> Zustände, die find() nacheinander liefert */
    public array $states = [];
    public function enqueue(string $communityId, string $kind, string $source, string $filePath, string $originalName, string $sha256, ?string $requestedBy): string
    {
        $id = 'job-' . (count($this->jobs) + 1);
        $this->jobs[$id] = compact('communityId', 'kind', 'source', 'filePath', 'originalName', 'sha256', 'requestedBy');
        return $id;
    }
    public function find(string $jobId, string $communityId): ?array
    {
        return array_shift($this->states) ?? ['status' => 'queued'];
    }
}

function edaJobsTmpFile(string $content): string
{
    $f = tempnam(sys_get_temp_dir(), 'eda');
    file_put_contents($f, $content);
    return $f;
}

test('EDA-Jobs: Upload legt Job mit SHA-256 der Datei an', function () {
    $repo = new FakeEdaJobs();
    $f = edaJobsTmpFile('xlsx-inhalt');
    $id = (new EdaImportJobs($repo))->queue('c-1', 'interval', 'upload', $f, 'Mai.xlsx', 'u-1');
    assertSame('job-1', $id);
    assertSame(hash('sha256', 'xlsx-inhalt'), $repo->jobs['job-1']['sha256']);
    assertSame(['interval', 'upload', 'Mai.xlsx', 'u-1'], [$repo->jobs['job-1']['kind'], $repo->jobs['job-1']['source'], $repo->jobs['job-1']['originalName'], $repo->jobs['job-1']['requestedBy']]);
    unlink($f);
});

test('EDA-Jobs: ungültige Art oder Quelle wird abgelehnt', function () {
    $f = edaJobsTmpFile('x');
    foreach ([['quartal', 'upload'], ['monthly', 'ftp']] as [$kind, $source]) {
        try {
            (new EdaImportJobs(new FakeEdaJobs()))->queue('c', $kind, $source, $f, 'a.xlsx', null);
            throw new Exception("{$kind}/{$source} hätte abgelehnt werden müssen");
        } catch (InvalidArgumentException $e) {
            assertContains('Ungültiger Importjob', $e->getMessage());
        }
    }
    unlink($f);
});

test('EDA-Jobs: runAndWait wartet bis "done" und liefert stdout/stderr wie früher der Parser', function () {
    $repo = new FakeEdaJobs();
    $repo->states = [['status' => 'queued'], ['status' => 'running'], ['status' => 'done', 'stdout' => '{"records":3}', 'stderr' => 'INFO', 'exit_code' => 0]];
    $sleeps = 0;
    $f = edaJobsTmpFile('x');
    $r = (new EdaImportJobs($repo, function () use (&$sleeps) { $sleeps++; }))->runAndWait('c', 'monthly', $f, null);
    assertSame(['stdout' => '{"records":3}', 'stderr' => 'INFO', 'exit_code' => 0], $r);
    assertSame(2, $sleeps);
    assertSame('mailbox', $repo->jobs['job-1']['source']);
    unlink($f);
});

test('EDA-Jobs: runAndWait meldet Zeitüberschreitung als Fehler (json_decode schlägt fehl)', function () {
    $f = edaJobsTmpFile('x');
    $r = (new EdaImportJobs(new FakeEdaJobs(), fn() => null))->runAndWait('c', 'monthly', $f, null, 0);
    assertSame('', $r['stdout']);
    assertContains('Zeitüberschreitung', $r['stderr']);
    assertSame(null, json_decode($r['stdout'], true));
    unlink($f);
});

test('EDA-Jobs: isFinished/runnerResult', function () {
    assertTrue(EdaImportJobs::isFinished(['status' => 'failed']));
    assertFalse(EdaImportJobs::isFinished(['status' => 'running']));
    assertSame(['stdout' => '', 'stderr' => '', 'exit_code' => -1], EdaImportJobs::runnerResult([]));
});
