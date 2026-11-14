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

test('dashboardSanitizeLayout: Breiten nur 4/6/8 (12 = Standard, wird nicht gespeichert)', function () {
    $r = dashboardSanitizeLayout(['order' => ['a'], 'sizes' => ['a' => 6, 'b' => 7, 'c' => 12, '<x>' => 4, 'd' => '8']]);
    assertSame(['a' => 6, 'd' => 8], $r['sizes']);
});

test('dashboardDeviceLayouts: alte gerätelose Anordnung gilt für Laptop und Handy', function () {
    $r = dashboardDeviceLayouts(['a', 'b'], ['order' => ['b', 'a'], 'hidden' => ['a']]);
    assertSame(['b', 'a'], $r['desktop']['order']);
    assertSame(['b', 'a'], $r['mobile']['order']);
    assertSame(['a'], $r['mobile']['hidden']);
});

test('dashboardDeviceLayouts: Laptop und Handy getrennt, Breiten nur am Laptop', function () {
    $r = dashboardDeviceLayouts(['a', 'b', 'c'], [
        'desktop' => ['order' => ['c', 'a', 'b'], 'sizes' => ['a' => 6, 'weg' => 4]],
        'mobile' => ['order' => ['b'], 'hidden' => ['c']],
    ]);
    assertSame(['c', 'a', 'b'], $r['desktop']['order']);
    assertSame(['a' => 6], $r['desktop']['sizes']);
    assertSame(['b', 'a', 'c'], $r['mobile']['order']);
    assertSame(['c'], $r['mobile']['hidden']);
    assertFalse(isset($r['mobile']['sizes']));
});

test('dashboardStoreDevice: speichert nur das eine Gerät, teilt alte Anordnung auf, Zurücksetzen je Gerät', function () {
    $legacy = ['order' => ['b', 'a'], 'hidden' => []];
    $r = dashboardStoreDevice($legacy, 'mobile', ['order' => ['a', 'b'], 'hidden' => [], 'sizes' => ['a' => 6]]);
    assertSame(['b', 'a'], $r['desktop']['order']);
    assertSame(['a', 'b'], $r['mobile']['order']);
    assertFalse(isset($r['mobile']['sizes']));
    $r = dashboardStoreDevice($r, 'desktop', null);
    assertFalse(isset($r['desktop']));
    assertSame(null, dashboardStoreDevice($r, 'mobile', null));
});

test('DashboardWidgets: Breite und Handy-Anordnung landen im HTML', function () {
    $dw = new DashboardWidgets('admin', ['desktop' => ['order' => ['a', 'b'], 'sizes' => ['b' => 6]], 'mobile' => ['order' => ['b', 'a']]]);
    $dw->start('a', 'A'); echo 'x'; $dw->end();
    $dw->start('b', 'B'); echo 'y'; $dw->end();
    $html = $dw->render();
    assertTrue(str_contains($html, 'data-widget="b" data-span="6" style="--span:6"'));
    assertTrue(str_contains($html, 'data-layouts="'));
    assertTrue(str_contains($html, 'class="dash-resize"'));
});
