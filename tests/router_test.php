<?php

declare(strict_types=1);

// Router::buildPattern() -- wandelt ":param"-Platzhalter in ein preg-Muster um.

test('Einzelner lowercase-Parameter wird korrekt erfasst', function () {
    $pattern = Router::buildPattern('/portal/members/:id');
    assertSame(1, preg_match($pattern, '/portal/members/abc-123', $m));
    assertSame('abc-123', $m['id']);
});

test('camelCase-Parameter (:itemId) wird VOLLSTÄNDIG erfasst, nicht nur bis zum Großbuchstaben', function () {
    // Vorfall 18.10.2026: [a-z_]+ erfasste bei ":itemId" nur "item", der Rest ("Id") blieb als
    // literaler Text im Muster stehen -- die Route matchte dadurch NIE, unabhängig vom Wert im Pfad.
    $pattern = Router::buildPattern('/portal/billing/invoices/:id/items/:itemId/update');
    $uri = '/portal/billing/invoices/06d6d81b-37eb-4737-820d-9052443d3bfc/items/eccafccd-c9de-4568-a6c6-a68227a5051a/update';
    assertSame(1, preg_match($pattern, $uri, $m), 'Route mit camelCase-Parameter sollte matchen');
    assertSame('06d6d81b-37eb-4737-820d-9052443d3bfc', $m['id']);
    assertSame('eccafccd-c9de-4568-a6c6-a68227a5051a', $m['itemId']);
});

test('Mehrere camelCase-Parameter in derselben Route (:invoiceId)', function () {
    $pattern = Router::buildPattern('/portal/billing/gutschriften/:invoiceId/erledigt');
    assertSame(1, preg_match($pattern, '/portal/billing/gutschriften/xyz-789/erledigt', $m));
    assertSame('xyz-789', $m['invoiceId']);
});

test('Falscher letzter Pfadabschnitt matcht nicht', function () {
    $pattern = Router::buildPattern('/portal/members/:id/edit');
    assertSame(0, preg_match($pattern, '/portal/members/abc-123/delete'));
});
