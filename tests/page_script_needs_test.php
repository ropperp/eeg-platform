<?php

declare(strict_types=1);

// pageScriptNeeds(): optionale Skripte nur laden, wenn die Seite sie braucht.

test('pageScriptNeeds: Startseite mit Hero lädt Animationen', function () {
    assertTrue(pageScriptNeeds('<section class="hero hero-home">')['animations']);
    assertTrue(pageScriptNeeds('<div class="x hero">')['animations']);
    assertFalse(pageScriptNeeds('<div class="hero-banner">')['animations'], 'hero-banner ist kein Hero');
    assertFalse(pageScriptNeeds('<p>superhero</p>')['animations']);
});

test('pageScriptNeeds: Passwort- und Unterschrift-Skript nur bei Bedarf', function () {
    $n = pageScriptNeeds('<form><input type="password" name="password"></form>');
    assertTrue($n['password']);
    assertFalse($n['signature']);
    assertTrue(pageScriptNeeds('<script>trimSignatureCanvas(c)</script>')['signature']);
    assertSame(['animations' => false, 'password' => false, 'signature' => false], pageScriptNeeds('<p>Impressum</p>'));
});
