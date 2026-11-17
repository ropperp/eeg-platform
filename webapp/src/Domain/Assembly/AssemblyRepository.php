<?php

declare(strict_types=1);

namespace App\Domain\Assembly;

/**
 * Datenzugriff für Protokolle von Mitgliederversammlungen (Tabellen siehe migrate_20261119.sql).
 * Alle Abfragen laufen unter RLS (app.community_id); die community_id-Bedingungen sind zusätzlich
 * ausgeschrieben, damit auch ohne RLS nie fremde Daten auftauchen.
 */
interface AssemblyRepository
{
    /** @return list<array<string, mixed>> neueste zuerst, mit Anzahl Unterschriften */
    public function forCommunity(string $communityId): array;

    /** @return list<array<string, mixed>> nur abgeschlossene und für Mitglieder freigegebene */
    public function publishedForCommunity(string $communityId): array;

    /** @return array<string, mixed>|null */
    public function find(string $id, string $communityId): ?array;

    /** @param array<string, mixed> $fields */
    public function create(string $communityId, ?string $userId, array $fields): string;

    /** @param array<string, mixed> $fields nur Spalten aus AssemblyService::HEADER_FIELDS bzw. Status/PDF */
    public function update(string $id, array $fields): void;

    public function delete(string $id): void;

    /** @return list<array<string, mixed>> */
    public function items(string $assemblyId): array;

    /** @return array<string, mixed>|null */
    public function findItem(string $itemId, string $assemblyId): ?array;

    /** @param array<string, mixed> $fields */
    public function addItem(string $assemblyId, string $communityId, array $fields): string;

    /** @param array<string, mixed> $fields */
    public function updateItem(string $itemId, array $fields): void;

    public function deleteItem(string $itemId): void;

    /** Vertauscht die Position zweier Tagesordnungspunkte. */
    public function swapItems(string $itemIdA, string $itemIdB): void;

    /** @return list<array<string, mixed>> */
    public function signers(string $assemblyId): array;

    /** @return array<string, mixed>|null */
    public function findSigner(string $signerId, string $assemblyId): ?array;

    /** @param array<string, mixed> $fields */
    public function addSigner(string $assemblyId, string $communityId, array $fields): string;

    public function deleteSigner(string $signerId): void;

    public function setSignerToken(string $signerId, string $tokenHash, string $expiresAt): void;

    public function saveSignature(string $signerId, string $dataUri, string $via, string $ip): void;

    /** Löscht alle Unterschriften und Links (zurück in den Entwurf). */
    public function resetSignatures(string $assemblyId): void;

    /**
     * Löst einen Unterschrifts-Link auf (über alle Communities, siehe assembly_signer_by_token()).
     *
     * @return array{signer_id: string, assembly_id: string, community_id: string}|null
     */
    public function resolveToken(string $tokenHash): ?array;
}
