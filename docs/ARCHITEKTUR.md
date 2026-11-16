# Architektur-Überblick

Kurzfassung für Menschen, die neu in das Projekt kommen. Betrieb und Deployment: `CLAUDE.md`,
`docs/BETRIEBSHANDBUCH.md`. Entscheidungen mit Begründung: `docs/adr/`.

## Datenfluss

```
Browser / App ──HTTPS──► nginx-Proxy (TLS) ──► Traefik ──► webapp (nginx + PHP-FPM)
                                                              │  eeg_app (RLS)
ESP32 ──MQTT──► Mosquitto ──► mqtt-subscriber ──eeg_worker──► │
                                                              ▼
EDA-Datei (Upload/Postfach) ──► webapp legt Job an ──► eda-worker ──eeg_worker──► PostgreSQL + TimescaleDB
                                                              ▲
webapp ──HTTP (API-Key)──► latex-service (PDF) ──────────────┘ (keine DB)
```

| Dienst | Verantwortung | DB-Rolle |
|---|---|---|
| webapp | Web-Portal, Admin, öffentliche Seiten, App-API | `eeg_app`: nur Daten, RLS je Community |
| mqtt-subscriber | Live-Messwerte, Geräte-Konfiguration, Messe-Demo | `eeg_worker`: BYPASSRLS, kein Superuser |
| eda-worker | Netzbetreiber-Daten (Abrechnungsgrundlage), Jobs aus `eda_import_jobs` | `eeg_worker` |
| latex-service | rechtlich formatierte PDFs (Verträge, Rechnungen) | keine |
| timescaledb | Geschäfts- und Zeitreihendaten | `eeg`: Superuser, nur Migrationen/Backup |

## Code-Struktur der Webapp

```
webapp/
  public/index.php        Bootstrap: Fehlerbehandlung, Sicherheits-Header, Domain-Trennung, Router
  routes/                 Routen nach Bereich: public, portal, admin, api (Reihenfolge = Priorität)
  src/
    AppConfig.php         zentrale Konfiguration (Domain, URLs, Betriebsmodus)
    DB.php, Auth.php, …   bestehende Kernklassen (ohne Namensraum)
    functions.php         reine Hilfsfunktionen (testbar)
    Http/Controllers/     Controller: Eingabe lesen, Service aufrufen, Response zurückgeben
    Http/Responses/       HtmlResponse (Template), RedirectResponse, JsonResponse
    Domain/<Bereich>/     Fachregeln + Schnittstellen (Repository, Notifier), ohne SQL/HTTP
    Infrastructure/       Umsetzungen: Database (SQL), Mail, …
    Support/Services.php  baut Controller und Services zusammen
    views/                PHP-Templates: nur Darstellung
  composer.json           PSR-4: App\ -> src/ (Autoloader: src/autoload.php)
```

Fertig nach diesem Muster: **Support-Tickets** (`SupportController`, `Api\SupportApiController`,
`Domain\Support\SupportTicketService`, `Infrastructure\Database\PdoSupportTicketRepository`). Der Rest
liegt noch als Closures in `routes/*.php` und wird nur bei Berührung umgezogen (ADR 0002).

## Regeln für neuen Code

- Keine neue Fachlogik in `public/index.php` oder als Closure-Block in `routes/`. Neuer Bereich:
  Controller + Service + Repository wie bei Support-Tickets, Route ist ein Einzeiler.
- SQL nur in Repositories (`Infrastructure/Database`), nie in Templates.
- Mandantendaten: vor der ersten Abfrage `DB::setCommunity()`; RLS ist die zweite Verteidigungslinie,
  nicht die einzige -- Repositories filtern zusätzlich nach `community_id`/`member_id`.
- Domain, URLs, Kontaktadresse nur über `AppConfig`; Assets über `assetUrl()`.
- Kein Inline-JavaScript ohne Nonce, keine `on…=`-Attribute (CSP, siehe `CLAUDE.md`).
- Tests: Fachregeln als Unit-Test mit Fake-Repository (`tests/support_ticket_service_test.php`),
  SQL als Integrationstest unter der echten RLS-Rolle (`tests/integration/`).

## Tests und Qualität

| Befehl | Prüft |
|---|---|
| `make test` | Unit-Tests ohne Datenbank (auch Templates statisch: CSP, Zustimmungstexte) |
| `make test-e2e` | ganzer Stack in Docker: Login/2FA/CSRF, Rollen, Mandantentrennung, App-API, EDA-Import über den Worker |
| `make test-integration` | Schema + alle Migrationen in Wegwerf-TimescaleDB, RLS-Trennung, Repositories, Python-Puffer |
| `tests/http/headers_test.sh` | gzip/BREACH, Cache-Header, Security-Header gegen laufenden Container |
| PHPStan (`phpstan.neon`) | statische Analyse Level 5; Baseline nur abbauen |

Alles läuft in der CI (`.github/workflows/ci.yml`).
