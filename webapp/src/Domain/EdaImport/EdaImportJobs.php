<?php

declare(strict_types=1);

namespace App\Domain\EdaImport;

/**
 * EDA-Import über den Container eda-worker (docs/adr/0005-eda-import-als-worker.md).
 *
 * - Portal-Upload: queue() legt den Job an, die Seite zeigt den Status und lädt nach.
 * - Postfach-Abruf (Cron, CLI): runAndWait() legt den Job an und wartet auf das Ergebnis. Es liefert
 *   dieselbe Struktur wie früher der direkte Parser-Aufruf (stdout/stderr/exit_code), damit
 *   EdaAutoImporter.php unverändert weiterarbeitet.
 */
final class EdaImportJobs
{
    public const KINDS = ['monthly', 'interval'];
    public const SOURCES = ['upload', 'mailbox'];

    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        private readonly EdaImportJobRepository $jobs,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void { usleep($microseconds); };
    }

    public function queue(string $communityId, string $kind, string $source, string $filePath, string $originalName, ?string $requestedBy): string
    {
        if (!in_array($kind, self::KINDS, true) || !in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("Ungültiger Importjob: {$kind}/{$source}");
        }
        $sha = hash_file('sha256', $filePath);
        if ($sha === false) {
            throw new \RuntimeException('Importdatei nicht lesbar.');
        }
        return $this->jobs->enqueue($communityId, $kind, $source, $filePath, $originalName, $sha, $requestedBy);
    }

    /** @return array<string, mixed>|null */
    public function find(string $jobId, string $communityId): ?array
    {
        return $this->jobs->find($jobId, $communityId);
    }

    public static function isFinished(array $job): bool
    {
        return in_array($job['status'] ?? '', ['done', 'failed'], true);
    }

    /**
     * Ergebnis eines fertigen Jobs im früheren Format von EdaParserRunner::run().
     *
     * @param array<string, mixed> $job
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public static function runnerResult(array $job): array
    {
        return [
            'stdout'    => (string) ($job['stdout'] ?? ''),
            'stderr'    => (string) ($job['stderr'] ?? ''),
            'exit_code' => (int) ($job['exit_code'] ?? -1),
        ];
    }

    /**
     * Job anlegen und warten, bis der Worker fertig ist (nur für Hintergrundprozesse, nicht im Web).
     *
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function runAndWait(string $communityId, string $kind, string $filePath, ?string $requestedBy, int $timeoutSeconds = 900): array
    {
        $jobId = $this->queue($communityId, $kind, 'mailbox', $filePath, basename($filePath), $requestedBy);
        $deadline = time() + $timeoutSeconds;
        while (true) {
            $job = $this->jobs->find($jobId, $communityId);
            if ($job !== null && self::isFinished($job)) {
                return self::runnerResult($job);
            }
            if (time() >= $deadline) {
                return [
                    'stdout'    => '',
                    'stderr'    => "Zeitüberschreitung: Der EDA-Worker hat Job {$jobId} nicht innerhalb von {$timeoutSeconds} s abgeschlossen"
                        . ' (läuft der Container eda-worker?). Der Job bleibt eingereiht.',
                    'exit_code' => -1,
                ];
            }
            ($this->sleep)(1_000_000);
        }
    }
}
