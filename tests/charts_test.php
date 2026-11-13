<?php

declare(strict_types=1);

// Diagramm-Bausteine des Glas-Designs (webapp/src/charts.php).

test('chartMonthKeys liefert die letzten n Monate, ältester zuerst', function () {
    $keys = chartMonthKeys(3, new DateTimeImmutable('2026-01-15'));
    assertSame(['2025-11', '2025-12', '2026-01'], $keys);
});

test('chartMonthShort nutzt österreichische Kurznamen', function () {
    assertSame('Jän', chartMonthShort('2026-01'));
    assertSame('Dez', chartMonthShort('2025-12'));
});

test('chartNiceScale rundet auf schöne Achsenschritte', function () {
    assertSame([60.0, 20.0], chartNiceScale(55.0));
    assertSame([7.5, 2.5], chartNiceScale(7.0));
    assertSame([3000.0, 1000.0], chartNiceScale(2400.0));
    assertSame([3.0, 1.0], chartNiceScale(0.0));
});

test('renderBarChart blendet ältere Monate aus und skaliert auf den sichtbaren Zeitraum', function () {
    $months = ['2026-01', '2026-02', '2026-03', '2026-04'];
    $html = renderBarChart($months, [['name' => 'Bezug', 'class' => 'c1', 'values' => ['2026-01' => 900, '2026-04' => 30]]], 'kWh', 3, [3, 6]);
    assertSame(4, substr_count($html, 'data-col'));
    assertSame(1, substr_count($html, 'data-col hidden'));      // Jänner (900) außerhalb des Zeitraums
    assertTrue(str_contains($html, '--h:100%'));                 // April 30 von Achse 30 -> 100 %
    assertTrue(str_contains($html, 'data-range="3"'));
    assertFalse(str_contains($html, 'data-range="6"'));          // nur 4 Monate vorhanden
    assertTrue(str_contains($html, 'is-current'));
});

test('renderBarChart escaped Serien-Namen', function () {
    $html = renderBarChart(['2026-01'], [['name' => '<script>', 'class' => 'c1', 'values' => []]], 'kWh', 1, [1]);
    assertFalse(str_contains($html, '<script>'));
});

test('renderRingChart begrenzt auf 0-100 % und beschriftet', function () {
    $html = renderRingChart(140.0, 'aus der Gemeinschaft');
    assertTrue(str_contains($html, '100 %'));
    $html = renderRingChart(42.4, 'Autarkie');
    assertTrue(str_contains($html, '42 %'));
});

test('renderDonutChart zeichnet nur Segmente mit Wert und zeigt alle in der Legende', function () {
    $html = renderDonutChart([
        ['label' => 'Verbraucher', 'value' => 3, 'class' => 'c1'],
        ['label' => 'Einspeiser', 'value' => 0, 'class' => 'c2'],
        ['label' => 'Prosumer', 'value' => 1, 'class' => 'c3'],
    ], '4', 'Zählpunkte');
    assertSame(2, substr_count($html, 'gdonut-seg'));
    assertSame(3, substr_count($html, '<li '));
});
