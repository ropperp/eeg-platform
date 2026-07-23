<?php

declare(strict_types=1);

// Mahnstufen-Bezeichnung (mahnstufeText).

test('Stufe 0 -> leer (noch nicht gemahnt)', function () {
    assertSame('', mahnstufeText(0));
});
test('Stufe 1 -> Zahlungserinnerung', function () {
    assertSame('Zahlungserinnerung', mahnstufeText(1));
});
test('Stufe 2 -> 1. Mahnung', function () {
    assertSame('1. Mahnung', mahnstufeText(2));
});
test('Stufe 3 -> letzte Mahnung', function () {
    assertSame('2. Mahnung (letzte Aufforderung)', mahnstufeText(3));
});
test('Stufe über 3 -> weiterhin letzte Mahnung', function () {
    assertSame('2. Mahnung (letzte Aufforderung)', mahnstufeText(5));
});
