<?php

declare(strict_types=1);

// Selbst anordnenbare Startseiten-Kästen (webapp/src/dashboard_widgets.php).

test('dashboardMergeLayout: ohne gespeicherte Anordnung gilt die Standard-Reihenfolge', function () {
    assertSame(['order' => ['a', 'b', 'c'], 'hidden' => []], dashboardMergeLayout(['a', 'b', 'c'], null));
});

test('dashboardMergeLayout: gespeicherte Reihenfolge zuerst, neue Kästen hinten angehängt', function () {
    $r = dashboardMergeLayout(['a', 'b', 'c', 'neu'], ['order' => ['c', 'a', 'b']]);
    assertSame(['c', 'a', 'b', 'neu'], $r['order']);
});

test('dashboardMergeLayout: nicht mehr vorhandene Kästen fallen weg (auch bei ausgeblendet)', function () {
    $r = dashboardMergeLayout(['a', 'b'], ['order' => ['weg', 'b', 'a'], 'hidden' => ['weg', 'a']]);
    assertSame(['b', 'a'], $r['order']);
    assertSame(['a'], $r['hidden']);
});

test('dashboardSanitizeLayout: nur gültige Kennungen, keine Duplikate', function () {
    $r = dashboardSanitizeLayout(['order' => ['a', 'a', '<x>', 5, 'b-2'], 'hidden' => 'kaputt']);
    assertSame(['a', 'b-2'], $r['order']);
    assertSame(['kaputt'], $r['hidden']);
});

test('DashboardWidgets: leere Kästen werden übersprungen, Reihenfolge aus dem Layout', function () {
    $dw = new DashboardWidgets('member', ['order' => ['b', 'a'], 'hidden' => ['a']]);
    $dw->start('a', 'Kasten A'); echo 'Inhalt A'; $dw->end();
    $dw->start('leer', 'Leer'); echo "  \n"; $dw->end();
    $dw->start('b', 'Kasten B'); echo 'Inhalt B'; $dw->end();
    $html = $dw->render();
    assertFalse(str_contains($html, 'data-widget="leer"'));
    assertTrue(strpos($html, 'data-widget="b"') < strpos($html, 'data-widget="a"'));
    assertTrue(str_contains($html, 'is-hidden-widget" data-widget="a"'));
});
