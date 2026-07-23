<?php

declare(strict_types=1);

// Formelle E-Mail-Anrede (mailSalutation).

test('Auto + Herr -> Sehr geehrter Herr', function () {
    $s = mailSalutation(['salutation' => 'Herr', 'last_name' => 'Lorenz', 'email_anrede_mode' => 'auto']);
    assertSame('Sehr geehrter Herr', $s['anrede']);
    assertSame('Lorenz', $s['nachname']);
});

test('Auto + Frau -> Sehr geehrte Frau', function () {
    $s = mailSalutation(['salutation' => 'Frau', 'last_name' => 'Muster', 'email_anrede_mode' => 'auto']);
    assertSame('Sehr geehrte Frau', $s['anrede']);
});

test('Modus familie -> Sehr geehrte Familie (unabhängig vom Geschlecht)', function () {
    $s = mailSalutation(['salutation' => 'Herr', 'last_name' => 'Lorenz', 'email_anrede_mode' => 'familie']);
    assertSame('Sehr geehrte Familie', $s['anrede']);
    assertSame('Lorenz', $s['nachname']);
});

test('Modus frau überschreibt Geschlecht Herr', function () {
    $s = mailSalutation(['salutation' => 'Herr', 'last_name' => 'Lorenz', 'email_anrede_mode' => 'frau']);
    assertSame('Sehr geehrte Frau', $s['anrede']);
});

test('Titel wird dem Nachnamen vorangestellt', function () {
    $s = mailSalutation(['salutation' => 'Herr', 'titel' => 'Dr.', 'last_name' => 'Berg', 'email_anrede_mode' => 'auto']);
    assertSame('Dr. Berg', $s['nachname']);
});

test('Divers/unbekannt -> neutrales Guten Tag', function () {
    $s = mailSalutation(['salutation' => 'Divers', 'last_name' => 'Meier', 'email_anrede_mode' => 'auto']);
    assertSame('Guten Tag', $s['anrede']);
});

test('Firmenmitglied ohne Nachname -> Damen und Herren', function () {
    $s = mailSalutation(['company_name' => 'Muster GmbH', 'last_name' => '', 'email_anrede_mode' => 'auto']);
    assertSame('Sehr geehrte Damen und Herren', $s['anrede']);
    assertSame('', $s['nachname']);
});
