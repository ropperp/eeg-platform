<?php

declare(strict_types=1);

// Sicherheitsnetz gegen rohe Vorlagen-Platzhalter in ausgehenden Mails.

test('Unbefüllter Platzhalter wird entfernt (inkl. führendem Leerzeichen)', function () {
    assertSame('<p>,</p>', stripUnresolvedPlaceholders('<p>{{anrede}} {{nachname}},</p>'));
});
test('Befüllte Texte bleiben unverändert', function () {
    assertSame('<p>Sehr geehrter Herr Lorenz,</p>', stripUnresolvedPlaceholders('<p>Sehr geehrter Herr Lorenz,</p>'));
});
test('Platzhalter mit Leerzeichen innen wird auch entfernt', function () {
    assertSame('Betreff', stripUnresolvedPlaceholders('Betreff {{ unbekannt }}'));
});
test('Geschweifte Klammern ohne Platzhalter bleiben stehen', function () {
    assertSame('Preis {netto}', stripUnresolvedPlaceholders('Preis {netto}'));
});

// Icon-Helfer (Phosphor-Sprite).

test('icon() referenziert das Sprite mit ph-Präfix', function () {
    $html = icon('lightning');
    assertContains('#ph-lightning', $html);
    assertContains('class="icon"', $html);
});
test('icon() hängt zusätzliche Klassen an', function () {
    assertContains('class="icon icon-lg"', icon('moon', 'icon-lg'));
});
test('icon() escaped den Namen (kein Markup-Einbruch)', function () {
    assertContains('#ph-x&quot;&gt;&lt;script&gt;', icon('x"><script>'));
});
