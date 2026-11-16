<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Support\SupportTicketRepository;
use App\Domain\Support\SupportTicketService;
use App\Domain\Support\SupportValidationException;
use App\Http\Responses\HtmlResponse;
use App\Http\Responses\RedirectResponse;
use App\Http\Responses\Response;

/**
 * Support-Tickets im Web-Portal: Mitglieder unter /portal/my/support, Manager/Platform-Admin
 * unter /portal/support (alle Tickets der aktiven Community).
 */
final class SupportController
{
    public function __construct(
        private readonly SupportTicketRepository $tickets,
        private readonly SupportTicketService $service,
    ) {
    }

    // ─── Mitglied ───────────────────────────────────────────────────────────

    public function memberIndex(): Response
    {
        \Auth::requireLogin();
        $member = currentMemberFull();
        if (!$member) {
            return self::noMember();
        }
        return new HtmlResponse('pages/my_support', ['tickets' => $this->tickets->forMember($member['id'])]);
    }

    /** @param array<string, mixed> $post */
    public function memberCreate(array $post): Response
    {
        \Auth::requireLogin();
        $member = currentMemberFull();
        if (!$member) {
            return self::noMember();
        }
        try {
            $ticketId = $this->service->openByMember(
                $member,
                (string) ($post['subject'] ?? ''),
                (string) ($post['message'] ?? ''),
                $post['category'] ?? ''
            );
        } catch (SupportValidationException $e) {
            return new RedirectResponse('/portal/my/support?error=' . urlencode($e->getMessage()));
        }
        return new RedirectResponse('/portal/my/support/' . $ticketId);
    }

    public function memberShow(string $ticketId): Response
    {
        \Auth::requireLogin();
        $member = currentMemberFull();
        if (!$member) {
            return self::noMember();
        }
        $ticket = $this->tickets->findForMember($ticketId, $member['id']);
        if (!$ticket) {
            return self::ticketNotFound();
        }
        return new HtmlResponse('pages/my_support_detail', [
            'ticket'   => $ticket,
            'messages' => $this->tickets->messages($ticket['id']),
        ]);
    }

    /** @param array<string, mixed> $post */
    public function memberReply(string $ticketId, array $post): Response
    {
        \Auth::requireLogin();
        $member = currentMemberFull();
        if (!$member) {
            return self::noMember();
        }
        $ticket = $this->tickets->findForMember($ticketId, $member['id']);
        if (!$ticket) {
            return self::ticketNotFound();
        }
        $this->service->replyByMember($ticket, $member, (string) ($post['message'] ?? ''));
        return new RedirectResponse('/portal/my/support/' . $ticket['id']);
    }

    // ─── Verwaltung (Manager/Platform-Admin) ────────────────────────────────

    /** @param array<string, mixed> $query */
    public function staffIndex(array $query): Response
    {
        $communityId = self::requireManagerCommunity();
        $statusFilter = $query['status'] ?? '';
        $tickets = $this->tickets->forCommunity(
            $communityId,
            SupportTicketService::isValidStatus($statusFilter) ? (string) $statusFilter : null
        );
        // Namen echter Mitglieder maskieren, damit der Demo-Account das Ticketsystem herzeigen kann,
        // ohne fremde Namen preiszugeben; eigene Demo-Tickets (is_demo=true) bleiben unmaskiert.
        return new HtmlResponse('pages/support_tickets', [
            'tickets'      => demoMaskMembers($tickets, \Auth::isDemo()),
            'statusFilter' => $statusFilter,
        ]);
    }

    public function staffShow(string $ticketId): Response
    {
        $communityId = self::requireManagerCommunity();
        $ticket = $this->tickets->findForCommunityWithMember($ticketId, $communityId);
        if (!$ticket) {
            return self::ticketNotFound();
        }
        $ticketMemberIsDemo = (bool) ($ticket['is_demo'] ?? false);
        $ticket = demoMaskMember($ticket, \Auth::isDemo());
        $messages = demoMaskSupportMessages($this->tickets->messages($ticket['id']), \Auth::isDemo(), $ticketMemberIsDemo);
        // Öffnen der Detailseite gilt als "gelesen" (Ungelesen-Badge in der Sidebar).
        $this->tickets->markReadByManager($ticket['id']);
        return new HtmlResponse('pages/support_ticket_detail', ['ticket' => $ticket, 'messages' => $messages]);
    }

    /** @param array<string, mixed> $post */
    public function staffReply(string $ticketId, array $post): Response
    {
        $communityId = self::requireManagerCommunity();
        $ticket = $this->tickets->findForCommunity($ticketId, $communityId);
        if (!$ticket) {
            return self::ticketNotFound();
        }
        $this->service->replyByStaff(
            $ticket,
            (string) (\Auth::userName() ?: 'Verwaltung'),
            (string) ($post['message'] ?? ''),
            $post['status'] ?? ''
        );
        return new RedirectResponse('/portal/support/' . $ticket['id']);
    }

    private static function requireManagerCommunity(): string
    {
        \Auth::requireLogin();
        \Auth::requireRole('manager');
        $communityId = \Auth::activeCommunityId();
        \DB::setCommunity($communityId);
        return $communityId;
    }

    private static function noMember(): Response
    {
        return HtmlResponse::text('Kein Mitgliedskonto in dieser EEG.', 404);
    }

    private static function ticketNotFound(): Response
    {
        return HtmlResponse::text('Ticket nicht gefunden.', 404);
    }
}
