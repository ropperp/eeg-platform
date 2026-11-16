<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Domain\Support\SupportTicketRepository;
use PDO;

/**
 * Support-Tickets in PostgreSQL. Ohne Argument nutzt es die Verbindung der Webapp (\DB, RLS-Rolle);
 * Tests übergeben eine eigene PDO-Verbindung.
 */
final class PdoSupportTicketRepository implements SupportTicketRepository
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function forMember(string $memberId): array
    {
        return $this->all('SELECT * FROM support_tickets WHERE member_id = ? ORDER BY updated_at DESC', [$memberId]);
    }

    public function findForMember(string $ticketId, string $memberId): ?array
    {
        return $this->one('SELECT * FROM support_tickets WHERE id = ? AND member_id = ?', [$ticketId, $memberId]);
    }

    public function forCommunity(string $communityId, ?string $status): array
    {
        $where = 't.community_id = ?';
        $params = [$communityId];
        if ($status !== null) {
            $where .= ' AND t.status = ?';
            $params[] = $status;
        }
        return $this->all(
            "SELECT t.*, m.first_name, m.last_name, m.is_demo,
                    EXISTS(
                        SELECT 1 FROM support_ticket_messages sm
                        WHERE sm.ticket_id = t.id AND sm.is_staff = false
                          AND sm.created_at > COALESCE(t.manager_read_at, '-infinity'::timestamptz)
                    ) AS hat_ungelesen
             FROM support_tickets t JOIN members m ON m.id = t.member_id
             WHERE $where ORDER BY (t.status = 'offen') DESC, t.updated_at DESC",
            $params
        );
    }

    public function findForCommunityWithMember(string $ticketId, string $communityId): ?array
    {
        return $this->one(
            'SELECT t.*, m.first_name, m.last_name, m.email, m.is_demo
             FROM support_tickets t JOIN members m ON m.id = t.member_id
             WHERE t.id = ? AND t.community_id = ?',
            [$ticketId, $communityId]
        );
    }

    public function findForCommunity(string $ticketId, string $communityId): ?array
    {
        return $this->one('SELECT * FROM support_tickets WHERE id = ? AND community_id = ?', [$ticketId, $communityId]);
    }

    public function messages(string $ticketId): array
    {
        return $this->all('SELECT * FROM support_ticket_messages WHERE ticket_id = ? ORDER BY created_at ASC', [$ticketId]);
    }

    public function create(string $communityId, string $memberId, string $subject, string $category): string
    {
        $row = $this->one(
            'INSERT INTO support_tickets (community_id, member_id, subject, category) VALUES (?, ?, ?, ?) RETURNING id',
            [$communityId, $memberId, $subject, $category]
        );
        return (string) ($row['id'] ?? '');
    }

    public function addMessage(string $ticketId, string $authorLabel, bool $isStaff, string $message): void
    {
        $this->run(
            'INSERT INTO support_ticket_messages (ticket_id, author_label, is_staff, message) VALUES (?, ?, ?, ?)',
            [$ticketId, $authorLabel, $isStaff ? 'true' : 'false', $message]
        );
    }

    public function setStatus(string $ticketId, string $status): void
    {
        $this->run('UPDATE support_tickets SET status = ?, updated_at = now() WHERE id = ?', [$status, $ticketId]);
    }

    public function touch(string $ticketId): void
    {
        $this->run('UPDATE support_tickets SET updated_at = now() WHERE id = ?', [$ticketId]);
    }

    public function markReadByManager(string $ticketId): void
    {
        $this->run('UPDATE support_tickets SET manager_read_at = now() WHERE id = ?', [$ticketId]);
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
