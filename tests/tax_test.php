<?php

declare(strict_types=1);

// USt-Aufteilung netto/brutto (taxBreakdown).

test('Kleinunternehmer: netto = brutto, keine USt', function () {
    $t = taxBreakdown(49.04, 'kleinunternehmer', null);
    assertSame(49.04, $t['netto']);
    assertSame(0.0, $t['ust']);
    assertSame(49.04, $t['brutto']);
    assertSame(0.0, $t['rate']);
});

test('Standard 20%: USt und Brutto korrekt aufgeschlagen', function () {
    $t = taxBreakdown(100.00, 'standard', 20);
    assertSame(100.00, $t['netto']);
    assertSame(20.0, $t['ust']);
    assertSame(120.0, $t['brutto']);
});

test('Standard: Satz als Komma-String wird akzeptiert', function () {
    $t = taxBreakdown(50.00, 'standard', '20,00');
    assertSame(10.0, $t['ust']);
    assertSame(60.0, $t['brutto']);
});

test('Standard: fehlender Satz -> Default 20%', function () {
    $t = taxBreakdown(10.00, 'standard', null);
    assertSame(2.0, $t['ust']);
    assertSame(12.0, $t['brutto']);
});

test('Negatives Netto (Guthaben): USt und Brutto vorzeichenrichtig', function () {
    $t = taxBreakdown(-30.00, 'standard', 20);
    assertSame(-6.0, $t['ust']);
    assertSame(-36.0, $t['brutto']);
});

test('Unbekanntes Modell fällt auf Kleinunternehmer zurück', function () {
    $t = taxBreakdown(80.00, null, 20);
    assertSame('kleinunternehmer', $t['model']);
    assertSame(80.0, $t['brutto']);
});

test('Rundung auf zwei Nachkommastellen (krummer Satz)', function () {
    $t = taxBreakdown(33.33, 'standard', 20);
    assertSame(6.67, $t['ust']);     // 6.666 -> 6.67
    assertSame(40.0, $t['brutto']);
});
