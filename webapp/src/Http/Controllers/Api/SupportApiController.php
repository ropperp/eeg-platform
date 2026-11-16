<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Support\SupportTicketRepository;
use App\Domain\Support\SupportTicketService;
use App\Domain\Support\SupportValidationException;
use App\Http\Responses\JsonResponse;

/**
 * Support-Tickets für die Mitglieder-App (/api/v1/support, Format siehe docs/APP_API.md).
 * Authentifizierung und Mitglieds-Prüfung übernimmt die Route (AppApiAuth::requireAppAuth(),
 * requireAppMemberId()); die Methoden bekommen den geprüften Kontext.
 */
final class SupportApiController
{
    public function __construct(
        private readonly SupportTicketRepository $tickets,
        private readonly SupportTicketService $service,
    ) {
    }

    /** @param array{member_id: string, community_id: string} $ctx */
    public function index(array $ctx): JsonResponse
    {
        return new JsonResponse(['tickets' => array_map(
            [self::class, 'ticket'],
            $this->tickets->forMember($ctx['member_id'])
        )]);
    }

    /**
     * @param array{member_id: string, community_id: string} $ctx
     * @param array<string, mixed> $body
     */
    public function create(array $ctx, array $body): JsonResponse
    {
        $member = self::member($ctx);
        if (!$member) {
            return JsonResponse::error('Mitglied nicht gefunden.', 404);
        }
        try {
            $ticketId = $this->service->openByMember(
                $member,
                (string) ($body['subject'] ?? ''),
                (string) ($body['message'] ?? ''),
                $body['category'] ?? ''
            );
        } catch (SupportValidationException $e) {
            return JsonResponse::error($e->getMessage(), 400);
        }
        return new JsonResponse(['id' => $ticketId, 'status' => 'ok']);
    }

    /** @param array{member_id: string, community_id: string} $ctx */
    public function show(array $ctx, string $ticketId): JsonResponse
    {
        $ticket = $this->tickets->findForMember($ticketId, $ctx['member_id']);
        if (!$ticket) {
            return JsonResponse::error('Ticket nicht gefunden.', 404);
        }
        return new JsonResponse([
            'ticket'   => self::ticket($ticket),
            'messages' => array_map([self::class, 'message'], $this->tickets->messages($ticket['id'])),
        ]);
    }

    /**
     * @param array{member_id: string, community_id: string} $ctx
     * @param array<string, mixed> $body
     */
    public function reply(array $ctx, string $ticketId, array $body): JsonResponse
    {
        $member = self::member($ctx);
        if (!$member) {
            return JsonResponse::error('Mitglied nicht gefunden.', 404);
        }
        $ticket = $this->tickets->findForMember($ticketId, $ctx['member_id']);
        if (!$ticket) {
            return JsonResponse::error('Ticket nicht gefunden.', 404);
        }
        if (!$this->service->replyByMember($ticket, $member, (string) ($body['message'] ?? ''))) {
            return JsonResponse::error('Nachricht darf nicht leer sein.', 400);
        }
        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, mixed>
     */
    public static function ticket(array $t): array
    {
        return [
            'id'         => $t['id'],
            'subject'    => $t['subject'],
            'category'   => $t['category'],
            'status'     => $t['status'],
            'created_at' => appDate($t['created_at']),
            'updated_at' => appDate($t['updated_at']),
        ];
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    public static function message(array $m): array
    {
        return [
            'id'           => $m['id'],
            'author_label' => $m['author_label'],
            'is_staff'     => (bool) $m['is_staff'],
            'message'      => $m['message'],
            'created_at'   => appDate($m['created_at']),
        ];
    }

    /**
     * @param array{member_id: string, community_id: string} $ctx
     * @return array<string, mixed>|null
     */
    private static function member(array $ctx): ?array
    {
        return \DB::fetchOne('SELECT * FROM members WHERE id = ? AND community_id = ?', [$ctx['member_id'], $ctx['community_id']]);
    }
}
