<?php

declare(strict_types=1);

// Betrags-Formatierung auf der Rechnung -- einheitliches Minuszeichen über Summenzeile
// (Bindestrich) und Positionszeilen (Unicode-Minus), siehe rechnungBetragBindestrich()/
// rechnungBetragUnicode() in functions.php (Patrick, 18.10.2026: "-28,70" vs. "− 30,25").

test('rechnungBetragBindestrich: positiver Betrag', function () {
    assertSame('57,74', rechnungBetragBindestrich(57.74));
});

test('rechnungBetragBindestrich: negativer Betrag (Bindestrich)', function () {
    assertSame('-28,70', rechnungBetragBindestrich(-28.70));
});

test('rechnungBetragUnicode: positiver Betrag', function () {
    assertSame('30,25', rechnungBetragUnicode(30.25));
});

test('rechnungBetragUnicode: negativer Betrag (Unicode-Minus mit Abstand)', function () {
    assertSame('$-$\\,30,25', rechnungBetragUnicode(-30.25));
});

// countCalendarMonths()/mitgliedsbeitragLabelSuffix() -- "(anteilig, X Monate)"-Label bei
// unterjährigem Beitritt, siehe invoicePdfPayload() (Patrick, 18.10.2026).

test('countCalendarMonths: volles Quartal = 3 Monate', function () {
    assertSame(3, countCalendarMonths('2026-07-01', '2026-09-30'));
});

test('countCalendarMonths: ganzes Jahr = 12 Monate', function () {
    assertSame(12, countCalendarMonths('2026-01-01', '2026-12-31'));
});

test('countCalendarMonths: einzelner Monat = 1', function () {
    assertSame(1, countCalendarMonths('2026-08-01', '2026-08-31'));
});

test('mitgliedsbeitragLabelSuffix: voller Zeitraum -> kein Zusatz', function () {
    assertSame('', mitgliedsbeitragLabelSuffix(3, 3));
});

test('mitgliedsbeitragLabelSuffix: aliquoter Zeitraum -> "(anteilig, X Monate)"', function () {
    assertSame(' (anteilig, 2 Monate)', mitgliedsbeitragLabelSuffix(2, 3));
});
