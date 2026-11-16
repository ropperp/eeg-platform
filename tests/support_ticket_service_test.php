<?php

declare(strict_types=1);

// SupportTicketService: Regeln für Anlegen/Antworten, unabhängig von Datenbank und Mailversand.

use App\Domain\Support\SupportNotifier;
use App\Domain\Support\SupportTicketRepository;
use App\Domain\Support\SupportTicketService;
use App\Domain\Support\SupportValidationException;

/** Merkt sich alle Schreibaufrufe, statt eine Datenbank zu benutzen. */
final class FakeSupportTickets implements SupportTicketRepository
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];
    public function forMember(string $memberId): array { return []; }
    public function findForMember(string $ticketId, string $memberId): ?array { return null; }
    public function forCommunity(string $communityId, ?string $status): array { return []; }
    public function findForCommunityWithMember(string $ticketId, string $communityId): ?array { return null; }
    public function findForCommunity(string $ticketId, string $communityId): ?array { return null; }
    public function messages(string $ticketId): array { return []; }
    public function create(string $communityId, string $memberId, string $subject, string $category): string
    {
        $this->calls[] = ['create', $communityId, $memberId, $subject, $category];
        return 't-1';
    }
    public function addMessage(string $ticketId, string $authorLabel, bool $isStaff, string $message): void
    {
        $this->calls[] = ['message', $ticketId, $authorLabel, $isStaff, $message];
    }
    public function setStatus(string $ticketId, string $status): void { $this->calls[] = ['status', $ticketId, $status]; }
    public function touch(string $ticketId): void { $this->calls[] = ['touch', $ticketId]; }
    public function markReadByManager(string $ticketId): void { $this->calls[] = ['read', $ticketId]; }
}

final class FakeSupportNotifier implements SupportNotifier
{
    /** @var list<array<int, mixed>> */
    public array $sent = [];
    public function ticketCreated(string $ticketId, array $member, string $subject, string $category, string $message): void
    {
        $this->sent[] = [$ticketId, $subject, $category];
    }
}

$supportMember = ['id' => 'm-1', 'community_id' => 'c-1', 'first_name' => 'Anna', 'last_name' => 'Muster ', 'email' => 'a@example.invalid'];

test('Support: neues Ticket legt Ticket + erste Nachricht an und benachrichtigt', function () use ($supportMember) {
    $repo = new FakeSupportTickets(); $mail = new FakeSupportNotifier();
    $id = (new SupportTicketService($repo, $mail))->openByMember($supportMember, '  Zähler  ', " Geht nicht \n", 'feature');
    assertSame('t-1', $id);
    assertSame([
        ['create', 'c-1', 'm-1', 'Zähler', 'feature'],
        ['message', 't-1', 'Anna Muster', false, 'Geht nicht'],
    ], $repo->calls);
    assertSame([['t-1', 'Zähler', 'feature']], $mail->sent);
});

test('Support: unbekannte Kategorie wird "problem"', function () use ($supportMember) {
    $repo = new FakeSupportTickets();
    (new SupportTicketService($repo, new FakeSupportNotifier()))->openByMember($supportMember, 'a', 'b', 'irgendwas');
    assertSame('problem', $repo->calls[0][4]);
    assertSame('problem', SupportTicketService::normalizeCategory(null));
});

test('Support: ohne Betreff oder Nachricht -> Fehlermeldung, nichts gespeichert', function () use ($supportMember) {
    foreach ([['', 'x'], ['x', '   ']] as [$subject, $message]) {
        $repo = new FakeSupportTickets(); $mail = new FakeSupportNotifier();
        try {
            (new SupportTicketService($repo, $mail))->openByMember($supportMember, $subject, $message, 'problem');
            throw new Exception('hätte abgelehnt werden müssen');
        } catch (SupportValidationException $e) {
            assertSame('Bitte Betreff und Nachricht ausfüllen.', $e->getMessage());
        }
        assertSame([], $repo->calls);
        assertSame([], $mail->sent);
    }
});

test('Support: Mitglied-Antwort setzt Ticket zurück auf "offen", leere Antwort tut nichts', function () use ($supportMember) {
    $repo = new FakeSupportTickets();
    $svc = new SupportTicketService($repo, new FakeSupportNotifier());
    assertFalse($svc->replyByMember(['id' => 't-9'], $supportMember, "  \n"));
    assertSame([], $repo->calls);
    assertTrue($svc->replyByMember(['id' => 't-9'], $supportMember, ' Danke '));
    assertSame([['message', 't-9', 'Anna Muster', false, 'Danke'], ['status', 't-9', 'offen']], $repo->calls);
});

test('Support: Verwaltung antwortet mit/ohne Statuswechsel', function () {
    $repo = new FakeSupportTickets();
    $svc = new SupportTicketService($repo, new FakeSupportNotifier());
    $svc->replyByStaff(['id' => 't-2'], 'Obmann', 'Erledigt', 'geschlossen');
    $svc->replyByStaff(['id' => 't-3'], '', 'Schaue ich mir an', '');
    $svc->replyByStaff(['id' => 't-4'], 'Obmann', '', 'in_bearbeitung');
    $svc->replyByStaff(['id' => 't-5'], 'Obmann', '', 'ungültig');
    assertSame([
        ['message', 't-2', 'Obmann', true, 'Erledigt'], ['status', 't-2', 'geschlossen'],
        ['message', 't-3', 'Verwaltung', true, 'Schaue ich mir an'], ['touch', 't-3'],
        ['status', 't-4', 'in_bearbeitung'],
    ], $repo->calls);
});
