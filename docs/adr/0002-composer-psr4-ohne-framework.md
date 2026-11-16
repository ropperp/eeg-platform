# ADR 0002: Composer/PSR-4 und schrittweise Extraktion statt Framework

**Status:** angenommen (16.11.2026); ersetzt den Abschnitt „Kein PHP-Framework / kein Composer“ in
`docs/PROJEKTSTAND.md`.

## Kontext
`public/index.php` war auf knapp 12.000 Zeilen gewachsen (273 Routen als Closures, dazwischen
Hilfsfunktionen). Ein Framework (Laravel, Slim) nachträglich überzustülpen wäre ein Big-Bang-Umbau.

## Entscheidung
- Routen liegen in `webapp/routes/{public,portal,admin,api}.php`; `index.php` ist nur noch Bootstrap.
  Die Reihenfolge der Dateien ist Teil des Verhaltens (erste passende Route gewinnt).
- Neuer Code im Namensraum `App\` nach PSR-4 (`composer.json`: `App\` → `src/`), geschichtet in
  `Http` (Controller, Responses), `Domain` (Regeln, Schnittstellen), `Infrastructure` (SQL, Mail).
- Composer wird nur für das Autoloading-Mapping genutzt, es gibt keine Laufzeit-Abhängigkeiten.
  `src/autoload.php` nimmt `vendor/autoload.php`, wenn vorhanden, sonst einen kleinen PSR-4-Loader
  mit identischer Zuordnung -- Tests und Container brauchen dadurch keinen Installationsschritt.
  Die CI prüft `composer validate` und lässt die Tests einmal mit dem echten Composer-Autoloader laufen.
- Der eigene Router bleibt vorerst. Ein Wechsel (z. B. FastRoute) lohnt sich erst, wenn die Closures
  größtenteils durch Controller ersetzt sind.
- Bestehende Bereiche werden nur bei Berührung extrahiert; Vorlage ist der Bereich Support-Tickets.

## Folgen
- Fachregeln sind ohne Datenbank testbar (Fake-Repository), SQL unter echter RLS (Integrationstest).
- Zwei Stile existieren eine Weile nebeneinander; `docs/ARCHITEKTUR.md` beschreibt, welcher für
  neuen Code gilt.
- Kommt eine echte Abhängigkeit dazu, wird `composer install` Teil des Docker-Builds.
