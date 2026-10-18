<?php

declare(strict_types=1);

// Betrags-Formatierung auf der Rechnung -- einheitliches Unicode-Minuszeichen über Summenzeile
// UND Positionszeilen, siehe rechnungBetragUnicode() in functions.php (Patrick, 18.10.2026: die
// Summenzeile zeigte zunächst noch einen einfachen Bindestrich statt desselben Formatters).

test('rechnungBetragUnicode: positiver Betrag', function () {
    assertSame('30,25', rechnungBetragUnicode(30.25));
});

test('rechnungBetragUnicode: negativer Betrag (Unicode-Minus mit Abstand)', function () {
    assertSame('$-$\\,30,25', rechnungBetragUnicode(-30.25));
});

test('rechnungBetragUnicode: Null -- kein Vorzeichen', function () {
    assertSame('0,00', rechnungBetragUnicode(0.0));
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

test('mitgliedsbeitragLabelSuffix: voller Zeitraum (3 von 3 Monaten) -> kein Zusatz', function () {
    assertSame('', mitgliedsbeitragLabelSuffix(3, 3));
});

test('mitgliedsbeitragLabelSuffix: anteilig 1 von 3 Monaten -> Einzahl "(anteilig, 1 Monat)"', function () {
    assertSame(' (anteilig, 1 Monat)', mitgliedsbeitragLabelSuffix(1, 3));
});

test('mitgliedsbeitragLabelSuffix: anteilig 2 von 3 Monaten -> "(anteilig, 2 Monate)"', function () {
    assertSame(' (anteilig, 2 Monate)', mitgliedsbeitragLabelSuffix(2, 3));
});
