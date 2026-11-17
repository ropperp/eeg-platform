<?php

declare(strict_types=1);

/**
 * Schlanker, abhängigkeitsfreier Test-Runner für die EEG-Plattform.
 *
 * Bewusst ohne PHPUnit/Composer: die Testsuite läuft dadurch überall mit einem einzigen
 * Befehl (`php tests/run.php`, siehe Makefile-Ziel `test` und .github/workflows/ci.yml),
 * ohne Install-Schritt. Getestet werden die reinen (seiteneffektfreien) Funktionen der App --
 * IBAN-/Zählpunkt-Validierung, LaTeX-Escaping, Rechnungs-Positionsformatierung und die
 * anteilige Mitgliedsbeitragsberechnung. Datenbank-/HTTP-Logik wird hier nicht getestet.
 *
 * Neue Tests: einfach eine weitere Datei tests/<name>_test.php mit test(...)-Aufrufen anlegen.
 */

require __DIR__ . '/lib.php';

// Zu testender Code (reine Funktionen/Methoden, kein DB-/HTTP-Kontext nötig).
require __DIR__ . '/../webapp/src/functions.php';
require __DIR__ . '/../webapp/src/consent_texts.php';
require __DIR__ . '/../webapp/src/Billing.php';
// Nur die DB-freien Methoden (Token-Signieren/-Prüfen) werden hier getestet -- DB wird erst zur
// Laufzeit innerhalb der DB-gestützten Methoden aufgelöst, ein require reicht ohne DB-Verbindung.
require __DIR__ . '/../webapp/src/AppApiAuth.php';
// Nur die DB-freien Methoden (JWT-Bau/Signatur-Konvertierung) werden hier getestet -- sendOne()/
// sendPending() brauchen echte Netzwerk-/DB-Zugriffe und werden hier bewusst nicht getestet.
require __DIR__ . '/../webapp/src/Push.php';
// Nur Router::buildPattern() (reine Pattern-Logik) wird hier getestet -- dispatch() braucht
// echten Request-/Superglobals-Kontext (CSRF/Demo-Check/Routing) und wird hier bewusst nicht
// aufgerufen.
require __DIR__ . '/../webapp/src/Router.php';
require __DIR__ . '/../webapp/src/EdaAutoImporter.php';
require __DIR__ . '/../webapp/src/WebAuthn.php';
require __DIR__ . '/../webapp/src/DB.php';
require __DIR__ . '/../webapp/src/AppConfig.php';
require __DIR__ . '/../webapp/src/autoload.php';
// Steuerzentrale (konsole/, eigener Container): Klassen im Namensraum Konsole\.
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Konsole\\')) {
        require __DIR__ . '/../konsole/src/' . substr($class, 8) . '.php';
    }
});

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    echo "\n" . basename($file) . "\n";
    require $file;
}

testSummaryAndExit();
