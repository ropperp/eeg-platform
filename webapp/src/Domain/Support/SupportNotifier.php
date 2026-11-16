<?php

declare(strict_types=1);

namespace App\Domain\Support;

/** Informiert die Verwaltung über ein neues Ticket. Darf nie eine Ausnahme werfen. */
interface SupportNotifier
{
    /** @param array<string, mixed> $member */
    public function ticketCreated(string $ticketId, array $member, string $subject, string $category, string $message): void;
}
