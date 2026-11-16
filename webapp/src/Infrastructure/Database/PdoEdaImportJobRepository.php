<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Domain\EdaImport\EdaImportJobRepository;
use PDO;

final class PdoEdaImportJobRepository implements EdaImportJobRepository
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function enqueue(string $communityId, string $kind, string $source, string $filePath, string $originalName, string $sha256, ?string $requestedBy): string
    {
        // ON CONFLICT auf den Teilindex "aktive Jobs je Datei": läuft die Datei schon, deren ID nehmen.
        $stmt = $this->pdo()->prepare(
            "INSERT INTO eda_import_jobs (community_id, kind, source, file_path, original_name, file_sha256, requested_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (community_id, kind, file_sha256) WHERE status IN ('queued', 'running') DO NOTHING
             RETURNING id"
        );
        $stmt->execute([$communityId, $kind, $source, $filePath, $originalName, $sha256, $requestedBy]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (string) $id;
        }
        $stmt = $this->pdo()->prepare(
            "SELECT id FROM eda_import_jobs WHERE community_id = ? AND kind = ? AND file_sha256 = ?
               AND status IN ('queued', 'running') ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$communityId, $kind, $sha256]);
        return (string) $stmt->fetchColumn();
    }

    public function find(string $jobId, string $communityId): ?array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $jobId)) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM eda_import_jobs WHERE id = ? AND community_id = ?');
        $stmt->execute([$jobId, $communityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['result'] = $row['result'] !== null ? json_decode((string) $row['result'], true) : null;
        return $row;
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? \DB::get();
    }
}
