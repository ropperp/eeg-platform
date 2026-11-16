<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Regeln des Support-Ticket-Systems (Tabellen siehe migrate_20260821.sql). Gemeinsam genutzt von
 * Web-Portal (SupportController) und Mitglieder-App (Api\SupportApiController), damit Anlegen und
 * Antworten nicht mehr doppelt implementiert sind.
 *
 * - Kategorie ist "feature" oder "problem" (alles andere wird "problem").
 * - Antwortet ein Mitglied, geht das Ticket zurück auf "offen" -- sonst ginge die Antwort im Blick
 *   des Obmanns leicht unter.
 * - Antwortet die Verwaltung, kann sie den Status setzen; ohne Statuswechsel zählt die Antwort als
 *   Aktualisierung (updated_at).
 */
final class SupportTicketService
{
    public const STATUSES = ['offen', 'in_bearbeitung', 'geschlossen'];

    public function __construct(
        private readonly SupportTicketRepository $tickets,
        private readonly SupportNotifier $notifier,
    ) {
    }

    public static function normalizeCategory(mixed $category): string
    {
        return $category === 'feature' ? 'feature' : 'problem';
    }

    public static function isValidStatus(mixed $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    /** @param array<string, mixed> $member */
    public static function memberLabel(array $member): string
    {
        return trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
    }

    /**
     * Legt ein Ticket mit erster Nachricht an und benachrichtigt die Verwaltung.
     *
     * @param array<string, mixed> $member Zeile aus members (id, community_id, Namen, email)
     * @return string Ticket-ID
     * @throws SupportValidationException wenn Betreff oder Nachricht fehlen
     */
    public function openByMember(array $member, string $subject, string $message, mixed $category): string
    {
        $subject = trim($subject);
        $message = trim($message);
        $category = self::normalizeCategory($category);
        if ($subject === '' || $message === '') {
            throw new SupportValidationException('Bitte Betreff und Nachricht ausfüllen.');
        }
        $ticketId = $this->tickets->create((string) $member['community_id'], (string) $member['id'], $subject, $category);
        $this->tickets->addMessage($ticketId, self::memberLabel($member), false, $message);
        $this->notifier->ticketCreated($ticketId, $member, $subject, $category, $message);
        return $ticketId;
    }

    /**
     * Antwort eines Mitglieds auf sein eigenes Ticket. Leere Nachricht: nichts passiert.
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $member
     * @return bool ob eine Nachricht gespeichert wurde
     */
    public function replyByMember(array $ticket, array $member, string $message): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }
        $this->tickets->addMessage((string) $ticket['id'], self::memberLabel($member), false, $message);
        $this->tickets->setStatus((string) $ticket['id'], 'offen');
        return true;
    }

    /**
     * Antwort und/oder Statuswechsel durch die Verwaltung.
     *
     * @param array<string, mixed> $ticket
     */
    public function replyByStaff(array $ticket, string $authorLabel, string $message, mixed $newStatus): void
    {
        $ticketId = (string) $ticket['id'];
        $message = trim($message);
        if ($message !== '') {
            $this->tickets->addMessage($ticketId, $authorLabel !== '' ? $authorLabel : 'Verwaltung', true, $message);
        }
        if (self::isValidStatus($newStatus)) {
            $this->tickets->setStatus($ticketId, (string) $newStatus);
        } elseif ($message !== '') {
            $this->tickets->touch($ticketId);
        }
    }
}
