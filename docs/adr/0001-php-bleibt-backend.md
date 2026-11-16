# ADR 0001: PHP bleibt Backend

**Status:** angenommen (16.11.2026)

## Kontext
Die Plattform ist überwiegend formular-, datenbank- und dokumentorientiert und läuft auf einem
Raspberry Pi 5. Vorgeschlagen wurden u. a. ein Umstieg auf Python (Django/FastAPI), Laravel oder eine
JavaScript-SPA.

## Entscheidung
PHP 8.4 mit PHP-FPM und nginx bleibt das Backend, Seiten werden serverseitig als PHP-Templates
gerendert. Python bleibt dort, wo es heute ist (MQTT, EDA-Import), Node.js nur für den LaTeX-Dienst.

## Folgen
- Kein Neuaufbau: kein Migrationsrisiko, kein zusätzlicher RAM-Bedarf, Wissen bleibt nutzbar.
- Wartbarkeit wird stattdessen durch Struktur verbessert (ADR 0002), nicht durch einen Technologiewechsel.
- JavaScript nur für gezielte Interaktion (Charts, Signatur, Passkeys), seitenweise geladen.
