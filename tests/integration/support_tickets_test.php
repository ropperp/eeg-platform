<?php

declare(strict_types=1);

// PdoSupportTicketRepository gegen die echte Datenbank, als Laufzeit-Rolle mit RLS.

use App\Infrastructure\Database\PdoSupportTicketRepository;

$stOwner = testPdo('TEST_DB_OWNER');
$stSuffix = bin2hex(random_bytes(4));
$stSeed = function (string $name) use ($stOwner, $stSuffix): array {
    $cid = $stOwner->query("SELECT uuid_generate_v4()")->fetchColumn();
    $stOwner->prepare("INSERT INTO communities (id, name, slug) VALUES (?, ?, ?)")->execute([$cid, "ST {$name}", "st-{$name}-{$stSuffix}"]);
    $q = $stOwner->prepare("INSERT INTO members (community_id, first_name, last_name, address, zip, city, email)
        VALUES (?, 'Test', ?, 'Weg 1', '1000', 'Ort', ?) RETURNING id");
    $q->execute([$cid, $name, "st-{$name}-{$stSuffix}@example.invalid"]);
    return ['community' => $cid, 'member' => $q->fetchColumn()];
};
$stA = $stSeed('a');
$stB = $stSeed('b');

$stApp = testPdo('TEST_APP_USER');
$stApp->exec("SET app.community_id = " . $stApp->quote($stA['community']));
$stRepo = new PdoSupportTicketRepository($stApp);

test('Support-Repository: Ticket anlegen, Nachrichten, Status, Lesestatus (als RLS-Rolle)', function () use ($stRepo, $stA) {
    $id = $stRepo->create($stA['community'], $stA['member'], 'Betreff', 'problem');
    assertTrue($id !== '');
    $stRepo->addMessage($id, 'Test a', false, 'Hallo');
    $stRepo->addMessage($id, 'Obmann', true, 'Antwort');
    $msgs = $stRepo->messages($id);
    assertSame(2, count($msgs));
    assertSame([false, true], [(bool) $msgs[0]['is_staff'], (bool) $msgs[1]['is_staff']]);
    assertSame(1, count($stRepo->forMember($stA['member'])));
    $list = $stRepo->forCommunity($stA['community'], null);
    assertSame(1, count($list));
    assertTrue((bool) $list[0]['hat_ungelesen'], 'Mitglied-Nachricht noch ungelesen');
    $stRepo->markReadByManager($id);
    assertFalse((bool) $stRepo->forCommunity($stA['community'], null)[0]['hat_ungelesen']);
    $stRepo->setStatus($id, 'geschlossen');
    assertSame(0, count($stRepo->forCommunity($stA['community'], 'offen')));
    assertSame(1, count($stRepo->forCommunity($stA['community'], 'geschlossen')));
    assertSame('Test', $stRepo->findForCommunityWithMember($id, $stA['community'])['first_name']);
});

test('Support-Repository: Tickets einer fremden Community sind unsichtbar und nicht anlegbar', function () use ($stRepo, $stOwner, $stB) {
    $q = $stOwner->prepare("INSERT INTO support_tickets (community_id, member_id, subject, category) VALUES (?, ?, 'fremd', 'problem') RETURNING id");
    $q->execute([$stB['community'], $stB['member']]);
    $foreign = $q->fetchColumn();
    assertSame(null, $stRepo->findForCommunity($foreign, $stB['community']));
    assertSame(null, $stRepo->findForMember($foreign, $stB['member']));
    assertSame([], $stRepo->forCommunity($stB['community'], null));
    try {
        $stRepo->create($stB['community'], $stB['member'], 'x', 'problem');
        throw new Exception('Anlegen in fremder Community wurde nicht abgelehnt');
    } catch (PDOException $e) {
        assertContains('row-level security', $e->getMessage());
    }
});

foreach ([$stA, $stB] as $c) {
    $stOwner->prepare("DELETE FROM support_ticket_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE community_id = ?)")->execute([$c['community']]);
    $stOwner->prepare("DELETE FROM support_tickets WHERE community_id = ?")->execute([$c['community']]);
    $stOwner->prepare("DELETE FROM members WHERE community_id = ?")->execute([$c['community']]);
    $stOwner->prepare("DELETE FROM communities WHERE id = ?")->execute([$c['community']]);
}
