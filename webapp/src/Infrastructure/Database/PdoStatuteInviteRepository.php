<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Domain\Statutes\StatuteInviteRepository;
use PDO;

/**
 * Statuten-Einladungen und -Entwürfe (Tabellen statute_invites, statute_drafts; plattformweit,
 * ohne RLS). Zugriff nur über Admin-Routen bzw. einen gültigen Token -- die Prüfung dafür liegt
 * im Controller/Service, hier nur SQL.
 */
final class PdoStatuteInviteRepository implements StatuteInviteRepository
{
    /** Spalten, die updateInvite() schreiben darf. */
    private const INVITE_COLUMNS = ['token_hash', 'status', 'expires_at', 'used_at', 'revoked_at'];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function insertInvite(array $data): string
    {
        $row = $this->one(
            'INSERT INTO statute_invites (token_hash, label, prefill, expires_at, created_by)
             VALUES (?, ?, ?::jsonb, ?, ?) RETURNING id',
            [$data['token_hash'], $data['label'], json_encode($data['prefill'], JSON_UNESCAPED_UNICODE),
             $data['expires_at'], $data['created_by']]
        );
        return (string) $row['id'];
    }

    public function findInvite(string $id): ?array
    {
        if (!self::isUuid($id)) {
            return null;
        }
        return $this->one('SELECT * FROM statute_invites WHERE id = ?', [$id]);
    }

    public function findInviteByHash(string $tokenHash): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $tokenHash)) {
            return null;
        }
        return $this->one('SELECT * FROM statute_invites WHERE token_hash = ?', [$tokenHash]);
    }

    public function listInvites(): array
    {
        return $this->all(
            "SELECT i.*, u.first_name AS created_by_first, u.last_name AS created_by_last,
                    (SELECT max(d.version) FROM statute_drafts d WHERE d.invite_id = i.id AND d.version > 0) AS latest_version,
                    (SELECT max(d.generated_at) FROM statute_drafts d WHERE d.invite_id = i.id) AS latest_generated_at
               FROM statute_invites i
               LEFT JOIN users u ON u.id = i.created_by
              ORDER BY i.created_at DESC",
            []
        );
    }

    public function updateInvite(string $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::INVITE_COLUMNS));
        if ($fields === [] || !self::isUuid($id)) {
            return;
        }
        $set = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($fields)));
        $this->run("UPDATE statute_invites SET {$set} WHERE id = ?", [...array_values($fields), $id]);
    }

    public function workingDraft(string $inviteId): ?array
    {
        return $this->one('SELECT * FROM statute_drafts WHERE invite_id = ? AND version = 0', [$inviteId]);
    }

    public function saveWorkingDraft(string $inviteId, array $answers): void
    {
        $this->run(
            'INSERT INTO statute_drafts (invite_id, answers, version) VALUES (?, ?::jsonb, 0)
             ON CONFLICT (invite_id, version) DO UPDATE SET answers = EXCLUDED.answers, created_at = now()',
            [$inviteId, json_encode($answers, JSON_UNESCAPED_UNICODE)]
        );
    }

    public function versions(string $inviteId): array
    {
        return $this->all(
            'SELECT * FROM statute_drafts WHERE invite_id = ? AND version > 0 ORDER BY version DESC',
            [$inviteId]
        );
    }

    public function version(string $inviteId, int $version): ?array
    {
        if ($version < 1 || !self::isUuid($inviteId)) {
            return null;
        }
        return $this->one('SELECT * FROM statute_drafts WHERE invite_id = ? AND version = ?', [$inviteId, $version]);
    }

    public function insertVersion(string $inviteId, int $version, array $answers, string $pdfPath, ?string $generatedBy): string
    {
        $row = $this->one(
            'INSERT INTO statute_drafts (invite_id, answers, version, pdf_path, generated_at, generated_by)
             VALUES (?, ?::jsonb, ?, ?, now(), ?) RETURNING id',
            [$inviteId, json_encode($answers, JSON_UNESCAPED_UNICODE), $version, $pdfPath, $generatedBy]
        );
        return (string) $row['id'];
    }

    public function markReleased(string $draftId, ?string $userId): void
    {
        $this->run('UPDATE statute_drafts SET released_at = now(), released_by = ? WHERE id = ?', [$userId, $draftId]);
    }

    public function staleInvites(string $cutoff): array
    {
        return $this->all(
            "SELECT i.id FROM statute_invites i
              WHERE GREATEST(
                      COALESCE((SELECT max(d.generated_at) FROM statute_drafts d WHERE d.invite_id = i.id), i.expires_at),
                      COALESCE(i.revoked_at, i.created_at)
                    ) < ?",
            [$cutoff]
        );
    }

    public function deleteInvite(string $id): void
    {
        $this->run('DELETE FROM statute_invites WHERE id = ?', [$id]);
    }

    private static function isUuid(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    private function pdo(): PDO
    {
        return $this->pdo ?? \DB::get();
    }

    /** @param list<mixed> $params */
    private function run(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, array $params): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
