<?php

declare(strict_types=1);

// sepaExecutionDateRange() -- 8-Tage-Anzeigezeitraum für die Ausführungsdatum-Auswahl im
// SEPA-Download-Modal (Aufgabe 18.10.2026).

test('Normalfall: Rechnungsdatum 18.10.2026 -> Zeitraum 01.-08.11., wählbar ab Mo 02.11.', function () {
    // Beispiel direkt aus der Aufgabe: 18.10.2026 + 14 Tage = 01.11.2026 (Sonntag + Allerheiligen)
    // -- angezeigt wird trotzdem ab 01.11., aber das erste WÄHLBARE Datum ist erst Montag 02.11.
    $r = sepaExecutionDateRange('2026-10-18', SEPA_PRENOTIFICATION_DAYS, '2026-10-18');
    assertSame('2026-11-01', $r['range_start']);
    assertSame('2026-11-08', $r['range_end']);
    assertSame('2026-11-02', $r['earliest']);
});

test('SEPA_PRENOTIFICATION_DAYS ist 14', function () {
    assertSame(14, SEPA_PRENOTIFICATION_DAYS);
});

test('SEPA_TRANSFER_MIN_DAYS ist 14 (eigene Konstante, Überweisung)', function () {
    assertSame(14, SEPA_TRANSFER_MIN_DAYS);
});

test('Frühestes Datum liegt in der Vergangenheit -> Fallback auf heute + 2 Bankarbeitstage', function () {
    // Rechnungsdatum 01.09.2026 + 14 Tage = 15.09.2026, das liegt vor "heute" (18.10.2026) --
    // der Zeitraum darf nicht rückwirkend beginnen, sondern erst ab heute + 2 Bankarbeitstagen.
    $r = sepaExecutionDateRange('2026-09-01', SEPA_PRENOTIFICATION_DAYS, '2026-10-18');
    assertSame('2026-10-20', $r['range_start']); // So 18.10. -> Mo 19. (1) -> Di 20. (2)
    assertSame('2026-10-27', $r['range_end']);
    assertSame('2026-10-20', $r['earliest']); // Dienstag ist selbst ein normaler Bankarbeitstag
});

test('Frühestes Datum ist bereits ein Bankarbeitstag -> keine Verschiebung der Vorauswahl', function () {
    // 01.07.2026 + 14 Tage = 15.07.2026 (Mittwoch, normaler Werktag).
    $r = sepaExecutionDateRange('2026-07-01', SEPA_PRENOTIFICATION_DAYS, '2026-06-01');
    assertSame('2026-07-15', $r['range_start']);
    assertSame('2026-07-22', $r['range_end']);
    assertSame('2026-07-15', $r['earliest']);
});

test('Grenzfall: frühestes Datum ist exakt heute -> keine Vergangenheits-Behandlung', function () {
    $r = sepaExecutionDateRange('2026-07-01', SEPA_PRENOTIFICATION_DAYS, '2026-07-15');
    assertSame('2026-07-15', $r['range_start']);
});

// sepaExecutionDateTiles() -- die 8 Kacheln fürs Modal.

test('sepaExecutionDateTiles: 8 Kacheln, gesperrte Tage mit Grund (Beispiel aus der Aufgabe)', function () {
    $t = sepaExecutionDateTiles('2026-10-18', SEPA_PRENOTIFICATION_DAYS, '2026-10-18');
    assertSame(8, count($t['tiles']));
    assertSame('2026-11-01', $t['tiles'][0]['date']);
    assertTrue($t['tiles'][0]['blocked']);
    assertSame('Sonntag', $t['tiles'][0]['reason']); // Sonntag UND Allerheiligen -- Wochenend-Grund wird zuerst geprüft/angezeigt
    assertSame('So 01.11.2026', $t['tiles'][0]['label']);
    assertSame('2026-11-02', $t['selected']); // erster wählbarer Tag
    assertFalse($t['tiles'][1]['blocked']); // Mo 02.11.
    assertTrue($t['tiles'][6]['blocked']); // Sa 07.11.
    assertTrue($t['tiles'][7]['blocked']); // So 08.11.
});

test('sepaExecutionDateTiles: letztes benutztes Datum wird vorausgewählt, wenn noch gültig', function () {
    $t = sepaExecutionDateTiles('2026-07-01', SEPA_PRENOTIFICATION_DAYS, '2026-06-01', '2026-07-17');
    assertSame('2026-07-17', $t['selected']);
});

test('sepaExecutionDateTiles: veraltetes letztes Datum (außerhalb des Zeitraums) wird ignoriert', function () {
    $t = sepaExecutionDateTiles('2026-07-01', SEPA_PRENOTIFICATION_DAYS, '2026-06-01', '2026-01-01');
    assertSame('2026-07-15', $t['selected']); // fällt auf "earliest" zurück
});

// validateSepaExecutionDate() -- Server-seitige Prüfung, unabhängig vom Frontend.

test('validateSepaExecutionDate: gültiges Datum -> kein Fehler', function () {
    assertSame(null, validateSepaExecutionDate('2026-11-02', '2026-10-18', SEPA_PRENOTIFICATION_DAYS, '2026-10-18'));
});

test('validateSepaExecutionDate: gesperrter Tag (Allerheiligen) -> Fehlermeldung', function () {
    $err = validateSepaExecutionDate('2026-11-01', '2026-10-18', SEPA_PRENOTIFICATION_DAYS, '2026-10-18');
    assertTrue($err !== null);
    assertContains('01.11.2026', $err);
});

test('validateSepaExecutionDate: Datum außerhalb des Zeitraums -> Fehlermeldung', function () {
    $err = validateSepaExecutionDate('2026-12-24', '2026-10-18', SEPA_PRENOTIFICATION_DAYS, '2026-10-18');
    assertTrue($err !== null);
    assertContains('außerhalb', $err);
});

test('validateSepaExecutionDate: ungültiges Format -> Fehlermeldung', function () {
    assertTrue(validateSepaExecutionDate('02.11.2026', '2026-10-18', SEPA_PRENOTIFICATION_DAYS, '2026-10-18') !== null);
});
