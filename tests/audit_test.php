<?php

declare(strict_types=1);

// Audit-Diff (auditNormalizeValue, auditDiff, auditChangesText).

test('Normalisierung: null/bool/DB-Bool/Zahl', function () {
    assertSame('', auditNormalizeValue(null));
    assertSame('ja', auditNormalizeValue(true));
    assertSame('nein', auditNormalizeValue(false));
    assertSame('ja', auditNormalizeValue('t'));
    assertSame('nein', auditNormalizeValue('f'));
    assertSame('42', auditNormalizeValue(42));
    assertSame('Text', auditNormalizeValue('  Text  '));
});

test('auditDiff erkennt nur geänderte, gelabelte Felder', function () {
    $before = ['name' => 'Alt', 'iban' => '', 'secret' => 'x', 'egal' => '1'];
    $after  = ['name' => 'Neu', 'iban' => 'AT123', 'secret' => 'y', 'egal' => '2'];
    $labels = ['name' => 'Name', 'iban' => 'IBAN', 'secret' => 'Secret'];
    $changes = auditDiff($before, $after, $labels, ['secret']);
    // 'egal' nicht in labels -> ignoriert; 'secret' in ignore -> ignoriert
    assertSame(2, count($changes));
    assertSame('Name', $changes['name']['label']);
    assertSame('Alt', $changes['name']['von']);
    assertSame('Neu', $changes['name']['auf']);
    assertSame('', $changes['iban']['von']);
    assertSame('AT123', $changes['iban']['auf']);
});

test('auditDiff: keine Änderung -> leer', function () {
    $changes = auditDiff(['a' => '1'], ['a' => '1'], ['a' => 'A']);
    assertSame(0, count($changes));
});

test('auditDiff: bool vs DB-Bool gilt als gleich', function () {
    $changes = auditDiff(['aktiv' => 't'], ['aktiv' => true], ['aktiv' => 'Aktiv']);
    assertSame(0, count($changes));
});

test('auditChangesText formatiert leere Werte als Gedankenstrich', function () {
    $changes = auditDiff(['iban' => ''], ['iban' => 'AT99'], ['iban' => 'IBAN']);
    assertContains('IBAN: „—" → „AT99"', auditChangesText($changes));
});
