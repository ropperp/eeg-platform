<?php

declare(strict_types=1);

// Anteiliger Mitgliedsbeitrag (Billing::mitgliedsbeitragAnteilig).
// Jahresbeitrag 24 EUR => Quartal 6 EUR, Monat 2 EUR.

test('Voll dabei (Beitritt vor dem Quartal) -> voller Quartalsbeitrag', function () {
    $r = Billing::mitgliedsbeitragAnteilig('2026-01-01', '2026-03-31', '2025-06-01', 24.0);
    assertSame(3, $r['months']);
    assertSame(6.0, $r['amount']);
});
test('Beitritt genau zum Quartalsbeginn -> voller Beitrag', function () {
    $r = Billing::mitgliedsbeitragAnteilig('2026-01-01', '2026-03-31', '2026-01-01', 24.0);
    assertSame(3, $r['months']);
    assertSame(6.0, $r['amount']);
});
test('Beitritt im 2. Quartalsmonat -> 2 Monate / 4 EUR', function () {
    $r = Billing::mitgliedsbeitragAnteilig('2026-01-01', '2026-03-31', '2026-02-10', 24.0);
    assertSame(2, $r['months']);
    assertSame(4.0, $r['amount']);
});
test('Beitritt im 3. Quartalsmonat -> 1 Monat / 2 EUR', function () {
    $r = Billing::mitgliedsbeitragAnteilig('2026-01-01', '2026-03-31', '2026-03-20', 24.0);
    assertSame(1, $r['months']);
    assertSame(2.0, $r['amount']);
});
test('Beitritt nach dem Zeitraum -> 0', function () {
    $r = Billing::mitgliedsbeitragAnteilig('2026-01-01', '2026-03-31', '2026-04-01', 24.0);
    assertSame(0, $r['months']);
    assertSame(0.0, $r['amount']);
});
test('Anderes Quartal (Q2), Beitritt Monat 2 -> 4 EUR', function () {
    $r = Billing::mitgliedsbeitragAnteilig('2026-04-01', '2026-06-30', '2026-05-15', 24.0);
    assertSame(2, $r['months']);
    assertSame(4.0, $r['amount']);
});
