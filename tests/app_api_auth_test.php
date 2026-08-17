<?php

declare(strict_types=1);

// AppApiAuth -- Token-Logik der Mitglieder-App (Login/Zugriffstoken/Tickets, siehe
// webapp/src/AppApiAuth.php). Getestet werden nur die DB-freien Methoden (selbst-signierte
// Access-Token + Tickets); die DB-gestützten Refresh-Token-Methoden sind hier bewusst nicht
// enthalten, da tests/run.php ohne Datenbankverbindung läuft (siehe Kommentar dort).

putenv('APP_SECRET=test-secret-fuer-app-api-auth-tests');

test('issueAccessToken()/verifyAccessToken() Round-Trip liefert member_id/community_id zurück', function () {
    $token = AppApiAuth::issueAccessToken('member-123', 'community-abc');
    $ctx = AppApiAuth::verifyAccessToken($token);
    assertSame('member-123', $ctx['member_id'] ?? null);
    assertSame('community-abc', $ctx['community_id'] ?? null);
});

test('verifyAccessToken() lehnt ein manipuliertes Token ab (Payload verändert, Signatur alt)', function () {
    $token = AppApiAuth::issueAccessToken('member-123', 'community-abc');
    [$payload, $sig] = explode('.', $token);
    $tampered = $payload . 'X.' . $sig;
    assertSame(null, AppApiAuth::verifyAccessToken($tampered));
});

test('verifyAccessToken() lehnt Tokens mit falschem Format ab', function () {
    assertSame(null, AppApiAuth::verifyAccessToken('kein-gueltiges-token'));
    assertSame(null, AppApiAuth::verifyAccessToken(''));
    assertSame(null, AppApiAuth::verifyAccessToken('zu.viele.punkte.hier'));
});

test('verifyAccessToken() lehnt ein korrekt signiertes, aber abgelaufenes Token ab', function () {
    // Manuell ein Token mit exp in der Vergangenheit bauen (gleiche HMAC-Herleitung wie
    // AppApiAuth::key('access'), siehe dortiger Kommentar zur Schlüsseltrennung je Zweck).
    $key = hash('sha256', 'test-secret-fuer-app-api-auth-tests|access', true);
    $b64url = fn($raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $payload = $b64url(json_encode(['mid' => 'm1', 'cid' => 'c1', 'exp' => time() - 10]));
    $sig = $b64url(hash_hmac('sha256', $payload, $key, true));
    assertSame(null, AppApiAuth::verifyAccessToken($payload . '.' . $sig));
});

test('issueTicket()/verifyTicket() Round-Trip liefert die übergebenen Claims zurück', function () {
    $ticket = AppApiAuth::issueTicket('totp_pending', ['uid' => 'user-1']);
    $claims = AppApiAuth::verifyTicket('totp_pending', $ticket);
    assertSame('user-1', $claims['uid'] ?? null);
});

test('verifyTicket() lehnt ein Ticket mit falschem Typ ab (Typ-Trennung zwischen 2FA/Community-Auswahl)', function () {
    $ticket = AppApiAuth::issueTicket('totp_pending', ['uid' => 'user-1']);
    assertSame(null, AppApiAuth::verifyTicket('community_pending', $ticket));
});

test('Access-Token und Ticket desselben Inhalts sind wegen getrennter Schlüssel NICHT austauschbar', function () {
    // Ein Ticket darf nicht versehentlich als Access-Token akzeptiert werden (unterschiedliche
    // HMAC-Schlüssel-Labels in AppApiAuth::key()).
    $ticket = AppApiAuth::issueTicket('totp_pending', ['mid' => 'member-123', 'cid' => 'community-abc']);
    assertSame(null, AppApiAuth::verifyAccessToken($ticket));
});

test('accessTokenTtl() liefert 900 Sekunden (15 Minuten)', function () {
    assertSame(900, AppApiAuth::accessTokenTtl());
});
