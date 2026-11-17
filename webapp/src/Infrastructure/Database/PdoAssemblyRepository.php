<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Domain\Assembly\AssemblyRepository;
use PDO;

final class PdoAssemblyRepository implements AssemblyRepository
{
    /** Spalten, die update() schreiben darf (Schutz gegen frei zusammengesetzte Spaltennamen). */
    private const ASSEMBLY_COLUMNS = [
        'title', 'kind', 'held_on', 'time_from', 'time_to', 'location', 'attendees_count', 'voting_count',
        'chair_name', 'minutes_taker', 'quorum', 'notes', 'status', 'visible_to_members', 'pdf_path',
        'finalized_at', 'source',
    ];
    private const ITEM_COLUMNS = ['title', 'body', 'resolution', 'votes_yes', 'votes_no', 'votes_abstain'];
    private const SIGNER_COLUMNS = ['name', 'role_label', 'email'];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function forCommunity(string $communityId): array
    {
        return $this->all(
            "SELECT a.*,
                    (SELECT count(*) FROM assembly_signers s WHERE s.assembly_id = a.id) AS signers_total,
                    (SELECT count(*) FROM assembly_signers s WHERE s.assembly_id = a.id AND s.signed_at IS NOT NULL) AS signers_done
               FROM assemblies a
              WHERE a.community_id = ?
              ORDER BY a.held_on DESC, a.created_at DESC",
            [$communityId]
        );
    }

    public function publishedForCommunity(string $communityId): array
    {
        return $this->all(
            "SELECT id, title, kind, held_on, finalized_at FROM assemblies
              WHERE community_id = ? AND status = 'final' AND visible_to_members AND pdf_path IS NOT NULL
              ORDER BY held_on DESC",
            [$communityId]
        );
    }

    public function find(string $id, string $communityId): ?array
    {
        if (!self::isUuid($id)) {
            return null;
        }
        return $this->one('SELECT * FROM assemblies WHERE id = ? AND community_id = ?', [$id, $communityId]);
    }

    public function create(string $communityId, ?string $userId, array $fields): string
    {
        $fields = self::only($fields, self::ASSEMBLY_COLUMNS);
        $cols = array_merge(['community_id', 'created_by'], array_keys($fields));
        $vals = array_merge([$communityId, $userId], array_map(self::dbValue(...), array_values($fields)));
        $row = $this->one(
            'INSERT INTO assemblies (' . implode(', ', $cols) . ') VALUES (' . self::placeholders(count($cols)) . ') RETURNING id',
            $vals
        );
        return (string) ($row['id'] ?? '');
    }

    public function update(string $id, array $fields): void
    {
        $fields = self::only($fields, self::ASSEMBLY_COLUMNS);
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($fields)));
        $this->run(
            "UPDATE assemblies SET $set, updated_at = now() WHERE id = ?",
            array_merge(array_map(self::dbValue(...), array_values($fields)), [$id])
        );
    }

    public function delete(string $id): void
    {
        $this->run('DELETE FROM assemblies WHERE id = ?', [$id]);
    }

    public function items(string $assemblyId): array
    {
        return $this->all('SELECT * FROM assembly_items WHERE assembly_id = ? ORDER BY position, created_at', [$assemblyId]);
    }

    public function findItem(string $itemId, string $assemblyId): ?array
    {
        if (!self::isUuid($itemId)) {
            return null;
        }
        return $this->one('SELECT * FROM assembly_items WHERE id = ? AND assembly_id = ?', [$itemId, $assemblyId]);
    }

    public function addItem(string $assemblyId, string $communityId, array $fields): string
    {
        $fields = self::only($fields, self::ITEM_COLUMNS);
        $cols = array_merge(['assembly_id', 'community_id', 'position'], array_keys($fields));
        $row = $this->one(
            'INSERT INTO assembly_items (' . implode(', ', $cols) . ')
             VALUES (?, ?, (SELECT COALESCE(max(position), 0) + 1 FROM assembly_items WHERE assembly_id = ?)'
                . str_repeat(', ?', count($fields)) . ') RETURNING id',
            array_merge([$assemblyId, $communityId, $assemblyId], array_map(self::dbValue(...), array_values($fields)))
        );
        return (string) ($row['id'] ?? '');
    }

    public function updateItem(string $itemId, array $fields): void
    {
        $fields = self::only($fields, self::ITEM_COLUMNS);
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($fields)));
        $this->run("UPDATE assembly_items SET $set WHERE id = ?", array_merge(array_map(self::dbValue(...), array_values($fields)), [$itemId]));
    }

    public function deleteItem(string $itemId): void
    {
        $this->run('DELETE FROM assembly_items WHERE id = ?', [$itemId]);
    }

    public function swapItems(string $itemIdA, string $itemIdB): void
    {
        $this->run(
            'UPDATE assembly_items a SET position = b.position
               FROM assembly_items b
              WHERE (a.id, b.id) IN ((?::uuid, ?::uuid), (?::uuid, ?::uuid))',
            [$itemIdA, $itemIdB, $itemIdB, $itemIdA]
        );
    }

    public function signers(string $assemblyId): array
    {
        return $this->all('SELECT * FROM assembly_signers WHERE assembly_id = ? ORDER BY position, created_at', [$assemblyId]);
    }

    public function findSigner(string $signerId, string $assemblyId): ?array
    {
        if (!self::isUuid($signerId)) {
            return null;
        }
        return $this->one('SELECT * FROM assembly_signers WHERE id = ? AND assembly_id = ?', [$signerId, $assemblyId]);
    }

    public function addSigner(string $assemblyId, string $communityId, array $fields): string
    {
        $fields = self::only($fields, self::SIGNER_COLUMNS);
        $cols = array_merge(['assembly_id', 'community_id', 'position'], array_keys($fields));
        $row = $this->one(
            'INSERT INTO assembly_signers (' . implode(', ', $cols) . ')
             VALUES (?, ?, (SELECT COALESCE(max(position), 0) + 1 FROM assembly_signers WHERE assembly_id = ?)'
                . str_repeat(', ?', count($fields)) . ') RETURNING id',
            array_merge([$assemblyId, $communityId, $assemblyId], array_map(self::dbValue(...), array_values($fields)))
        );
        return (string) ($row['id'] ?? '');
    }

    public function deleteSigner(string $signerId): void
    {
        $this->run('DELETE FROM assembly_signers WHERE id = ?', [$signerId]);
    }

    public function setSignerToken(string $signerId, string $tokenHash, string $expiresAt): void
    {
        $this->run('UPDATE assembly_signers SET sign_token_hash = ?, sign_token_expires = ? WHERE id = ?', [$tokenHash, $expiresAt, $signerId]);
    }

    public function saveSignature(string $signerId, string $dataUri, string $via, string $ip): void
    {
        // Link wird mit der Unterschrift ungültig (Hash gelöscht); "signed_at IS NULL" verhindert,
        // dass eine bereits geleistete Unterschrift überschrieben wird.
        $this->run(
            'UPDATE assembly_signers
                SET signature_image = ?, signed_at = now(), signed_via = ?, signer_ip = ?,
                    sign_token_hash = NULL, sign_token_expires = NULL
              WHERE id = ? AND signed_at IS NULL',
            [$dataUri, $via, $ip, $signerId]
        );
    }

    public function resetSignatures(string $assemblyId): void
    {
        $this->run(
            'UPDATE assembly_signers
                SET signature_image = NULL, signed_at = NULL, signed_via = NULL, signer_ip = NULL,
                    sign_token_hash = NULL, sign_token_expires = NULL
              WHERE assembly_id = ?',
            [$assemblyId]
        );
    }

    public function resolveToken(string $tokenHash): ?array
    {
        $row = $this->one('SELECT signer_id, assembly_id, community_id FROM assembly_signer_by_token(?)', [$tokenHash]);
        if (!$row) {
            return null;
        }
        return [
            'signer_id'    => (string) $row['signer_id'],
            'assembly_id'  => (string) $row['assembly_id'],
            'community_id' => (string) $row['community_id'],
        ];
    }

    // ─── Hilfen ─────────────────────────────────────────────────────────────

    private static function isUuid(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private static function only(array $fields, array $allowed): array
    {
        return array_intersect_key($fields, array_flip($allowed));
    }

    private static function dbValue(mixed $v): mixed
    {
        return is_bool($v) ? ($v ? 'true' : 'false') : $v;
    }

    private static function placeholders(int $n): string
    {
        return implode(', ', array_fill(0, $n, '?'));
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
