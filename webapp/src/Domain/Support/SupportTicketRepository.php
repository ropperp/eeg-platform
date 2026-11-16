<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Datenzugriff für Support-Tickets. Alle Abfragen laufen unter Row-Level Security: Aufrufer setzen
 * vorher DB::setCommunity() (Controller), die Repository-Methoden schränken zusätzlich per
 * member_id bzw. community_id ein.
 */
interface SupportTicketRepository
{
    /** @return list<array<string, mixed>> */
    public function forMember(string $memberId): array;

    /** @return array<string, mixed>|null */
    public function findForMember(string $ticketId, string $memberId): ?array;

    /** @return list<array<string, mixed>> Tickets mit Mitgliedsname und Ungelesen-Kennzeichen */
    public function forCommunity(string $communityId, ?string $status): array;

    /** @return array<string, mixed>|null Ticket mit Name, E-Mail und Demo-Kennzeichen des Mitglieds */
    public function findForCommunityWithMember(string $ticketId, string $communityId): ?array;

    /** @return array<string, mixed>|null */
    public function findForCommunity(string $ticketId, string $communityId): ?array;

    /** @return list<array<string, mixed>> */
    public function messages(string $ticketId): array;

    /** @return string ID des neuen Tickets */
    public function create(string $communityId, string $memberId, string $subject, string $category): string;

    public function addMessage(string $ticketId, string $authorLabel, bool $isStaff, string $message): void;

    public function setStatus(string $ticketId, string $status): void;

    public function touch(string $ticketId): void;

    public function markReadByManager(string $ticketId): void;
}
