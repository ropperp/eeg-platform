<?php

declare(strict_types=1);

// Passwort-Hashing und Reset-Token-Hash (functions.php: hashPassword(), passwordNeedsRehash(),
// hashResetToken()), 15.11.2026.

test('hashPassword: Hash passt zum Passwort, falsches Passwort passt nicht', function () {
    $h = hashPassword('Geheim-123!');
    assertTrue(password_verify('Geheim-123!', $h));
    assertFalse(password_verify('geheim-123!', $h));
});

test('hashPassword: gleiches Passwort ergibt durch das Salt zwei verschiedene Hashes', function () {
    assertTrue(hashPassword('gleich') !== hashPassword('gleich'));
});

test('hashPassword: der Klartext steckt nicht im Hash', function () {
    assertFalse(str_contains(hashPassword('PasswortImKlartext'), 'PasswortImKlartext'));
});

test('hashPassword: Argon2id wenn verfügbar, sonst bcrypt', function () {
    $h = hashPassword('x');
    if (defined('PASSWORD_ARGON2ID')) {
        assertTrue(str_starts_with($h, '$argon2id$'));
    } else {
        assertTrue(str_starts_with($h, '$2y$12$'));
    }
});

test('passwordNeedsRehash: aktueller Hash nein, schwacher bcrypt-Hash ja', function () {
    assertFalse(passwordNeedsRehash(hashPassword('x')));
    $schwach = password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]);
    assertTrue(passwordNeedsRehash($schwach));
});

test('passwordNeedsRehash: alter bcrypt-12-Hash wird auf Argon2id umgestellt (falls verfügbar)', function () {
    $alt = password_hash('x', PASSWORD_BCRYPT, ['cost' => 12]);
    assertSame(defined('PASSWORD_ARGON2ID'), passwordNeedsRehash($alt));
    // ... und bleibt bis dahin gültig
    assertTrue(password_verify('x', $alt));
});

test('hashResetToken: 64 Hex-Zeichen, deterministisch, nicht der Token selbst', function () {
    $t = bin2hex(random_bytes(32));
    $h = hashResetToken($t);
    assertSame(64, strlen($h));
    assertSame($h, hashResetToken($t));
    assertTrue($h !== $t);
    assertTrue(hashResetToken($t) !== hashResetToken($t . 'x'));
});
