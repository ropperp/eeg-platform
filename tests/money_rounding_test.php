<?php

declare(strict_types=1);

// Eurobeträge runden (functions.php: moneyRound()). Die Erwartungswerte sind die Ergebnisse des
// bisherigen round($x, 2) von PHP bis 8.3 -- läuft die Suite unter PHP 8.4 (siehe CI), muss
// moneyRound() trotz geänderter round()-Implementierung exakt dasselbe liefern.

test('moneyRound: halbe Cent werden auf, auch bei nicht exakt darstellbaren Gleitkommazahlen', function () {
    $erwartet = [
        [0.285, 0.29], [1.005, 1.01], [2.675, 2.68], [5.055, 5.06], [0.125, 0.13], [1.115, 1.12],
        [8.325, 8.33], [0.145, 0.15], [4.35, 4.35], [10.0, 10.0], [0.0, 0.0], [0.004, 0.0], [0.005, 0.01],
        [19.995, 20.0], [123.455, 123.46], [0.015, 0.02], [0.575, 0.58],
    ];
    foreach ($erwartet as [$in, $out]) {
        assertSame($out, moneyRound($in), "moneyRound({$in})");
    }
});

test('moneyRound: negative Beträge (Gutschrift) spiegelbildlich, halbe Cent weg von null', function () {
    foreach ([[-0.285, -0.29], [-2.675, -2.68], [-1.005, -1.01], [-0.004, 0.0]] as [$in, $out]) {
        assertSame($out, moneyRound($in) + 0.0, "moneyRound({$in})");
    }
    mt_srand(3);
    for ($i = 0; $i < 2000; $i++) {
        $v = mt_rand(1, 900000) / 1000 * mt_rand(1, 6000) / 100 / 100;
        assertSame(moneyRound($v), -moneyRound(-$v) + 0.0);
    }
});

test('moneyRound: nie weiter als ein halber Cent vom Betrag entfernt, wiederholte Rundung ändert nichts', function () {
    mt_srand(11);
    for ($i = 0; $i < 5000; $i++) {
        $v = mt_rand(1, 900000) / 1000 * mt_rand(1, 6000) / 100 / 100;
        $r = moneyRound($v);
        assertTrue(abs($r - $v) <= 0.005 + 1e-9, "Abstand bei {$v}");
        assertSame($r, moneyRound($r));
    }
});

test('taxBreakdown: Netto/USt/Brutto auf Cent, Kleinunternehmer ohne USt', function () {
    $t = taxBreakdown(10.005, 'standard', 20);
    assertSame(10.01, $t['netto']);
    assertSame(2.0, $t['ust']);
    assertSame(12.01, $t['brutto']);
    $k = taxBreakdown(33.335, 'kleinunternehmer', null);
    assertSame(0.0, $k['ust']);
    assertSame($k['netto'], $k['brutto']);
});
