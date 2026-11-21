<?php

declare(strict_types=1);

namespace App\Domain\Statutes;

/**
 * Speicher für Statuten-Einladungen und -Entwürfe (plattformweit, ohne community_id).
 * Umsetzung: Infrastructure\Database\PdoStatuteInviteRepository; in Unit-Tests ein Fake.
 */
interface StatuteInviteRepository
{
    /** @param array{token_hash: string, label: string, prefill: array<string, mixed>, expires_at: string, created_by: ?string} $data */
    public function insertInvite(array $data): string;

    /** @return array<string, mixed>|null */
    public function findInvite(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findInviteByHash(string $tokenHash): ?array;

    /** Alle Einladungen, neueste zuerst, mit latest_version und latest_generated_at. @return list<array<string, mixed>> */
    public function listInvites(): array;

    /** @param array<string, mixed> $fields  token_hash, status, expires_at, used_at, revoked_at */
    public function updateInvite(string $id, array $fields): void;

    /** Arbeitsstand (Version 0). @return array<string, mixed>|null */
    public function workingDraft(string $inviteId): ?array;

    /** @param array<string, mixed> $answers */
    public function saveWorkingDraft(string $inviteId, array $answers): void;

    /** Erzeugte Fassungen (Version >= 1), neueste zuerst. @return list<array<string, mixed>> */
    public function versions(string $inviteId): array;

    /** @return array<string, mixed>|null */
    public function version(string $inviteId, int $version): ?array;

    /** @param array<string, mixed> $answers */
    public function insertVersion(string $inviteId, int $version, array $answers, string $pdfPath, ?string $generatedBy): string;

    public function markReleased(string $draftId, ?string $userId): void;

    /**
     * Einladungen, deren letzte Aktivität (letzte Erzeugung, sonst Ablauf bzw. Widerruf) vor
     * $cutoff liegt -- Kandidaten für das Löschkonzept (12 Monate).
     *
     * @return list<array{id: string}>
     */
    public function staleInvites(string $cutoff): array;

    public function deleteInvite(string $id): void;
}
