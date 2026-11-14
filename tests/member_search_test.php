<?php

declare(strict_types=1);

// Mitgliedersuche (functions.php: memberSearchText()) und Sperrliste der Kundenansicht
// (Router::blockedWhileImpersonating()), 14.11.2026.

test('memberSearchText enthält Name, Adresse, KdNr, Zählpunkt und Geburtsdatum in mehreren Schreibweisen', function () {
    $t = memberSearchText([
        'first_name' => 'Anna', 'last_name' => 'Huber', 'kundennummer' => '1007', 'address' => 'Hauptstraße 5',
        'zip' => '9560', 'city' => 'Feldkirchen', 'email' => 'Anna@Example.test', 'geburtsdatum' => '1980-03-07',
        'zaehlpunkte' => 'AT0070000000000000000000000001234',
    ]);
    foreach (['anna huber', 'hauptstraße 5', '9560', 'feldkirchen', '1007', 'anna@example.test',
              '07.03.1980', '7.3.1980', '1980-03-07', 'at0070000000000000000000000001234'] as $needle) {
        assertTrue(str_contains($t, $needle), "fehlt: {$needle}");
    }
});

test('memberSearchText ohne Geburtsdatum und mit leeren Feldern', function () {
    $t = memberSearchText(['first_name' => 'Max', 'last_name' => 'Muster', 'company_name' => null, 'geburtsdatum' => null]);
    assertSame('max muster', $t);
});

test('Kundenansicht sperrt Unterschrift, Passwort, Profil, API-Schlüssel und Support im Namen des Mitglieds', function () {
    foreach (['/portal/my/contract/bezug/sign', '/portal/password', '/portal/profile', '/portal/profile/2fa/enable',
              '/portal/my/api-keys', '/portal/my/api-keys/abc/revoke', '/portal/my/support', '/portal/my/support/1/reply',
              '/portal/dashboard-layout', '/portal/members/abc/impersonate'] as $uri) {
        assertTrue(Router::blockedWhileImpersonating($uri), "nicht gesperrt: {$uri}");
    }
});

test('Kundenansicht erlaubt die übrigen Mitglied-Funktionen und das Beenden', function () {
    foreach (['/portal/impersonation/stop', '/portal/switch-role', '/portal/my/einspeisung', '/portal/invoices/1/pdf',
              '/portal/profiles-export'] as $uri) {
        assertTrue(!Router::blockedWhileImpersonating($uri), "fälschlich gesperrt: {$uri}");
    }
});
