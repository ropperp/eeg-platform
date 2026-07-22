<?php

declare(strict_types=1);

// ─── IBAN-Validierung (Mod-97 / ISO 7064) ────────────────────────────────────
test('IBAN AT gültig (mit Leerzeichen)', fn() => assertTrue(validateIban('AT61 1904 3002 3457 3201')));
test('IBAN AT gültig (ohne Leerzeichen)', fn() => assertTrue(validateIban('AT611904300234573201')));
test('IBAN DE gültig', fn() => assertTrue(validateIban('DE89 3704 0044 0532 0130 00')));
test('IBAN falsche Prüfsumme', fn() => assertFalse(validateIban('AT611904300234573200')));
test('IBAN zu kurz', fn() => assertFalse(validateIban('AT61')));
test('IBAN leer', fn() => assertFalse(validateIban('')));
test('IBAN mit Buchstaben an falscher Stelle', fn() => assertFalse(validateIban('ATXX1904300234573201')));

// ─── Zählpunktnummer (AT + 31 Zeichen = 33) ──────────────────────────────────
test('Zählpunkt gültig (33 Zeichen)', fn() => assertTrue(validateZaehlpunkt('AT' . str_repeat('0', 31))));
test('Zählpunkt zu kurz', fn() => assertFalse(validateZaehlpunkt('AT' . str_repeat('0', 30))));
test('Zählpunkt ohne AT-Präfix', fn() => assertFalse(validateZaehlpunkt('DE' . str_repeat('0', 31))));
test('Zählpunkt kleingeschrieben wird normalisiert', fn() => assertTrue(validateZaehlpunkt('at' . str_repeat('a', 31))));

// ─── LaTeX-Escaping ──────────────────────────────────────────────────────────
test('texEscape maskiert % und &', fn() => assertSame('50\\% \\& mehr', texEscape('50% & mehr')));
test('texEscape maskiert Unterstrich', fn() => assertSame('a\\_b', texEscape('a_b')));
test('texEscape wandelt Gedankenstrich', fn() => assertSame('a--b', texEscape('a—b')));
test('texEscape lässt harmlosen Text unangetastet', fn() => assertSame('Max Mustermann', texEscape('Max Mustermann')));

// ─── Rechnungs-Zusatzpositionen (4-Spalten-Format) ───────────────────────────
test('Zusatzposition: 4 Zellen, Minusbetrag', function () {
    $out = rechnungExtraItemsLatex([['label' => 'Rabatt', 'quantity' => 1, 'unit' => 'Stk', 'amount_eur' => -6.0]]);
    assertSame('  Rabatt & & & $-$\\,6,00 \\\\', $out);
});
test('Zusatzposition: abweichende Menge wandert in den Text', function () {
    $out = rechnungExtraItemsLatex([['label' => 'Zähler', 'quantity' => 2, 'unit' => 'Stk', 'amount_eur' => 10.0]]);
    assertContains('Zähler (2 Stk)', $out);
    assertContains('& & & 10,00', $out);
});
test('Zusatzposition: leere Liste ergibt leeren String', fn() => assertSame('', rechnungExtraItemsLatex([])));

// ─── Rechnungs-Positionszeilen pro Zählpunkt ─────────────────────────────────
test('Positionszeile mit Zählpunkt-Sublabel', function () {
    $out = rechnungPositionenLatex(
        [['zaehlpunkt_nr' => 'AT001', 'kwh' => 250.0, 'rate_ct_kwh' => 9.8, 'amount_eur' => 24.5]],
        'Energiebezug', false
    );
    assertSame('  Energiebezug\\newline{\\footnotesize\\color{midgray}Zählpunkt: AT001} & 250,00 & 9,8000 & 24,50 \\\\', $out);
});
test('Zwei Zählpunkte werden durch feine Linie getrennt', function () {
    $out = rechnungPositionenLatex([
        ['zaehlpunkt_nr' => 'A', 'kwh' => 1, 'rate_ct_kwh' => 1, 'amount_eur' => 1],
        ['zaehlpunkt_nr' => 'B', 'kwh' => 2, 'rate_ct_kwh' => 2, 'amount_eur' => 2],
    ], 'X', false);
    assertContains('\\arrayrulecolor{rulegray}\\hline', $out);
});
test('Einspeisung: Minuszeichen, kein Sublabel wenn Zählpunkt leer', function () {
    $out = rechnungPositionenLatex(
        [['zaehlpunkt_nr' => '', 'kwh' => 85, 'rate_ct_kwh' => 7.5, 'amount_eur' => -6.38]],
        'Einspeisevergütung', true
    );
    assertContains('$-$\\,6,38', $out);
    assertFalse(str_contains($out, 'Zählpunkt:'), 'kein Zählpunkt-Sublabel bei leerer Nummer');
});
test('Leere Positionsliste ergibt leeren String', fn() => assertSame('', rechnungPositionenLatex([], 'X', false)));
