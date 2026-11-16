<?php

declare(strict_types=1);

namespace App\Domain\EdaImport;

/** Zugriff auf eda_import_jobs (RLS: Aufrufer setzt vorher DB::setCommunity()). */
interface EdaImportJobRepository
{
    /**
     * Legt einen Job an. Läuft dieselbe Datei (gleicher SHA-256) für dieselbe EEG schon, wird
     * dessen ID zurückgegeben statt eines zweiten Jobs.
     */
    public function enqueue(
        string $communityId,
        string $kind,
        string $source,
        string $filePath,
        string $originalName,
        string $sha256,
        ?string $requestedBy,
    ): string;

    /** @return array<string, mixed>|null */
    public function find(string $jobId, string $communityId): ?array;
}
