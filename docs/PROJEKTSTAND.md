# Projektstand — EEG-Plattform

**Stand:** 20. Juni 2026 (aktualisiert nach Notifications/Audit-Migration)  
**Diplomarbeit HTL Kärnten 2026/27**  
**Autoren:** Patrick Ropper, Fabian (nachbauend), Alexander (nachbauend)

---

## Zweck der Plattform

Die Plattform verwaltet **Erneuerbare-Energie-Gemeinschaften (EEGs)** nach österreichischem EAG (Erneuerbaren-Ausbau-Gesetz) und ElWOG. Eine EEG ist ein Zusammenschluss von Privat- und Firmenmitgliedern, die gemeinsam Photovoltaik-Strom erzeugen und intern verrechnen.

**Pilot-EEG:** Strompool Feldkirchen Süd-West (ZVR 1778816746, Marktpartner-ID RC108175, Netzbetreiber KNG)

Die Plattform ist als **mandantenfähiges SaaS** konzipiert: Patrick, Fabian und Alexander betreiben je ihre eigene EEG-Instanz, alle laufen auf derselben Software. Datentrennung ist auf Datenbankebene via Row-Level Security erzwungen.

---

## Tech-Stack

| Schicht | Technologie | Begründung |
|---------|-------------|------------|
| Datenbank | PostgreSQL 16 + TimescaleDB | Zeitreihendaten nativ, RLS für Mandantentrennung |
| Backend | PHP 8.2 (kein Framework) | Einfach, kein Build-Step, im HTL-Lehrplan |
| Webserver | nginx + PHP-FPM | Im selben Docker-Container, simpel |
| Reverse Proxy | Traefik v3 | Automatische Let's Encrypt-Zertifikate |
| Session-Cache | Redis 7 | Persistente Sessions über Container-Restarts |
| MQTT-Broker | Eclipse Mosquitto 2 | ESP32-Standardprotokoll |
| MQTT→DB | Python 3.12 + paho-mqtt | Einfache Skriptsprache für den Subscriber |
| EDA-Parser | Python 3.12 + pandas | XLSX-Verarbeitung für Netzbetreiber-Daten |
| PDF-Service | Node.js 20 + pdflatex | TeX Live für professionelle Dokumente |
| Infrastruktur | Docker Compose, Raspberry Pi 5 | Günstig, stromsparend, NVMe-SSD |

---

## Verzeichnisstruktur

```
eeg-platform/
├── docker-compose.yml              # Alle Services, Netzwerk, Healthchecks
├── docker-compose.override.yml     # Lokal: webapp direkt auf Port 80, Traefik deaktiviert
├── .env.example                    # Vorlage für Umgebungsvariablen (ohne Secrets)
├── .gitignore
├── README.md                       # Schnellstart
├── SETUP.md                        # Detaillierte Installationsanleitung
├── Makefile                        # Kurzkommandos
│
├── scripts/
│   ├── backup.sh                   # pg_dump custom-Format mit Zeitstempel
│   └── restore.sh                  # pg_restore --clean --if-exists
│
├── docs/
│   ├── PROJEKTSTAND.md             # Diese Datei
│   ├── BACKUP.md                   # Backup-Strategie, Cron, externes Storage
│   ├── DATENBANK.md                # DB-Wahl, Architektur, RLS, TimescaleDB
│   ├── schema.sql                  # Nur Tabellenstruktur (kein Daten) — via make schema
│   ├── er-diagramm.md              # ER-Diagramm als Mermaid (versioniert)
│   └── STATISTIK.md                # Anonyme Kennzahlen (Abfragen + Vorlage)
│
├── database/
│   ├── init.sql                    # Vollständiges Schema inkl. Pilot-Daten (erster DB-Start)
│   ├── seed_demo.sql               # Fiktive Demo-Daten für Screenshots (NICHT auf Prod)
│   └── migrate_YYYYMMDD.sql        # Nachträgliche Migrationen für laufende Systeme
│
├── webapp/                         # PHP-Webapplikation
│   ├── Dockerfile                  # nginx + PHP 8.2 + Python (EDA-Parser)
│   ├── docker/
│   │   ├── nginx.conf
│   │   ├── php.ini
│   │   └── entrypoint.sh
│   ├── public/                     # nginx document root
│   │   ├── index.php               # Zentraler Router (alle Routen hier)
│   │   └── assets/css/app.css      # Globales Stylesheet
│   ├── src/
│   │   ├── Auth.php                # Session, bcrypt, Rollenverwaltung
│   │   ├── DB.php                  # PDO-Wrapper, setzt app.community_id für RLS
│   │   ├── Router.php              # Minimaler HTTP-Router
│   │   ├── Billing.php             # Abrechnungslogik
│   │   └── views/
│   │       ├── layouts/
│   │       │   ├── base.php        # HTML-Grundgerüst (Landingpage)
│   │       │   └── portal.php      # Portal-Layout: Sidebar, Navbar, Profil-Dropdown
│   │       └── pages/              # Eine Datei pro Seite (siehe unten)
│   └── storage/
│       ├── uploads/                # EDA-XLSX-Uploads (nicht in Git)
│       └── pdfs/                   # Generierte PDFs (nicht in Git)
│
├── latex-service/                  # PDF-Generator
│   ├── Dockerfile                  # Node.js 20 + TeX Live
│   ├── service.js                  # Express-API: POST /generate → PDF
│   └── templates/
│       ├── bezugsvereinbarung.tex
│       ├── einspeisevereinbarung.tex
│       └── rechnung.tex
│
├── mqtt-subscriber/                # ESP32-Daten → Datenbank
│   ├── Dockerfile
│   ├── main.py
│   └── requirements.txt
│
├── eda-parser/                     # Netzbetreiber-XLSX → Datenbank
│   ├── parser.py
│   └── requirements.txt
│
└── docker/
    └── mosquitto/config/
        └── mosquitto.conf          # MQTT-Broker-Konfiguration
```

---

## Services und ihre Aufgabe

| Service | Image/Build | Port (intern) | Aufgabe |
|---------|-------------|---------------|---------|
| `traefik` | `traefik:v3.1` | 80, 443 | Reverse Proxy, SSL-Terminierung, HTTP→HTTPS-Redirect |
| `timescaledb` | `timescale/timescaledb-ha:pg16` | 5432 | Haupt-Datenbank: Stammdaten + Zeitreihendaten |
| `redis` | `redis:7-alpine` | 6379 | PHP-Session-Cache |
| `mosquitto` | `eclipse-mosquitto:2` | 1883, 8883 | MQTT-Broker für ESP32-Geräte |
| `mqtt-subscriber` | `./mqtt-subscriber` | — | Empfängt MQTT-Nachrichten, schreibt in `esp_measurements` |
| `webapp` | `./webapp` | 80 | nginx + PHP 8.2: Portal, Admin, Landingpage, Live-Dashboard |
| `latex-service` | `./latex-service` | 3210 | Node.js + pdflatex: generiert PDFs aus LaTeX-Templates |

Alle Services kommunizieren über das interne Docker-Netzwerk `eeg-net`. Nur Traefik ist nach außen sichtbar (Ports 80/443). Mosquitto ist optional auch auf 1883/8883 direkt erreichbar (ESP32-Geräte).

---

## Datenbankschema

### Kern-Tabellen

| Tabelle | Beschreibung |
|---------|--------------|
| `communities` | EEGs (Mandanten): Name, Slug, Marktpartner-ID, ZVR, Adresse, IBAN |
| `users` | Login-Accounts: E-Mail, bcrypt-Passwort-Hash, Vor-/Nachname, Reset-Token |
| `user_roles` | Verbindet Users mit Communities und Rollen: `platform_admin` / `manager` / `member` |
| `members` | Mitglieder einer EEG: Stammdaten, Bankverbindung, Vertragsstatus |
| `metering_points` | Zählpunkte: Zählpunkt-Nr. (AT...), Typ (consumer/producer/prosumer) |

### Zeitreihendaten (TimescaleDB Hypertables)

| Tabelle | Chunk-Intervall | Beschreibung |
|---------|----------------|--------------|
| `esp_measurements` | 1 Tag | ESP32-Echtzeit: Momentanleistung + Zählerstand. **Nur Visualisierung, keine Abrechnungsgrundlage.** |
| `eda_measurements` | 7 Tage | 15-Min-Werte vom Netzbetreiber (EDA-Portal). **Einzige rechtlich gültige Abrechnungsgrundlage.** |
| `eda_imports` | — | Protokoll aller EDA-XLSX-Imports: Zeitraum, Datensätze, Warnungen |

### Konfiguration (historisiert)

| Tabelle | Beschreibung |
|---------|--------------|
| `tariff_config` | Tarife mit `valid_from`: Bezug (ct/kWh), Einspeisung (ct/kWh), Mitgliedsbeitrag (EUR/Jahr) |
| `tax_config` | Steuermodell mit `valid_from`: `kleinunternehmer` (§ 6 Abs 1 Z 27 UStG) oder `standard` (20% USt) |

### Benachrichtigungen & Audit (migrate_20260620.sql)

| Tabelle | Beschreibung |
|---------|--------------|
| `notifications` | Mandanten-Postfach: audience (`platform_admin`/`manager`/`member`), is_read, payload JSONB. RLS analog zu den anderen Tabellen. |
| `audit_log` | Append-only Ereignisprotokoll: action, entity_type, entity_id, details JSONB, ip. App-Rolle hat kein UPDATE/DELETE. |

Tarife und Steuern sind **nie hardcodiert** — immer aus DB mit dem zum Abrechnungszeitraum gültigen Eintrag.

### Abrechnung

| Tabelle | Beschreibung |
|---------|--------------|
| `billing_runs` | Quartalsabrechnung: Status (`pending` → `ready` → `released` → `done`), 60-Tage-Sperrdatum |
| `invoices` | Einzelrechnung je Mitglied: Rechnungsnummer, Saldo, PDF-Pfad |
| `invoice_items` | Positionen je Rechnung: Typ (bezug/einspeisung/mitgliedsbeitrag), kWh, Tarif, Betrag |

### Row-Level Security

Alle mandantenspezifischen Tabellen haben RLS aktiviert. Vor jeder DB-Abfrage setzt `DB::setCommunity($id)` den PostgreSQL-Parameter `app.community_id`. Die Policy erlaubt nur Zugriff auf Zeilen mit dieser `community_id`. Platform-Admins umgehen RLS durch direkte Abfragen mit `DB::fetchOne()` (ohne vorheriges `setCommunity()`).

---

## Seiten im Portal

| Datei | Route | Rolle | Beschreibung |
|-------|-------|-------|--------------|
| `home.php` | `/` | alle | Landingpage |
| `live.php` | `/live` | öffentlich | Live-Dashboard (aggregiert, kein Login) |
| `login.php` | `/portal/login` | — | Login-Formular |
| `forgot_password.php` | `/portal/forgot-password` | — | Passwort-Reset per E-Mail |
| `password_change.php` | `/portal/password` | alle | Passwort ändern |
| `profile.php` | `/portal/profile` | member/manager | Profildaten ändern |
| `member_dashboard.php` | `/portal/dashboard` | member | Eigener Verbrauch + Zählpunkt |
| `manager_dashboard.php` | `/portal/dashboard` | manager | Übersicht: Mitglieder, Abrechnungsstatus |
| `member_list.php` | `/portal/members` | manager | Mitgliederliste mit Suche |
| `member_form.php` | `/portal/members/new` | manager | Neues Mitglied anlegen |
| `member_detail.php` | `/portal/members/:id` | manager | Mitglied-Detail: Daten, Zählpunkte, Verträge |
| `member_contract.php` | `/portal/members/:id/contract` | manager | Vertragsübersicht |
| `eda_upload.php` | `/portal/eda/upload` | manager | EDA-XLSX hochladen und importieren |
| `billing.php` | `/portal/billing` | manager | Abrechnungsübersicht, Freigabe |
| `invoices.php` | `/portal/invoices` | member | Eigene Rechnungen als PDF |
| `settings.php` | `/portal/settings` | manager | EEG-Einstellungen: Tarife, Steuer, Stammdaten |
| `admin.php` | `/admin` | platform_admin | Alle EEGs verwalten |
| `admin_community.php` | `/admin/communities/:id` | platform_admin | EEG-Detail |
| `admin_user.php` | `/admin/users/:id` | platform_admin | Benutzer-Detail |
| `postfach.php` | `/portal/postfach` | alle | Benachrichtigungs-Postfach mit Badge in der Navbar |
| `audit.php` | `/portal/audit` | manager | Ereignisprotokoll der eigenen EEG |
| `audit.php` | `/admin/audit` | platform_admin | Plattformweites Ereignisprotokoll |

---

## Was ist fertig

### Vollständig implementiert und getestet

- [x] Docker Compose Stack (alle 7 Services)
- [x] Datenbankschema (vollständig, inkl. RLS und TimescaleDB-Hypertables)
- [x] Auth-System: Login, Logout, bcrypt, Redis-Sessions, Rollenwechsel
- [x] Passwort-Reset per E-Mail (Token, 1 Stunde gültig)
- [x] Plattform-Admin: alle EEGs und Benutzer verwalten
- [x] Manager-Portal: Mitgliederverwaltung (anlegen, bearbeiten, löschen)
- [x] Zählpunktverwaltung (consumer/producer, aktiv/inaktiv)
- [x] Vertragsgenerierung: Bezugs-/Einspeisevereinbarung als PDF (via latex-service)
- [x] Vertragsstatus: none → created → signed (per Klick im Portal)
- [x] EDA-XLSX-Import (Python-Parser, 15-Min-Zeitreihen)
- [x] MQTT-Subscriber (ESP32-Daten → TimescaleDB)
- [x] Live-Dashboard (aggregierte Echtzeit-Daten)
- [x] Mitglieder-Dashboard (eigener Verbrauch, Zählpunkt)
- [x] Tarif- und Steuerkonfiguration (historisiert)
- [x] Abrechnungslogik vollständig (`Billing::getOrCreateRun()` + `compute()` + `release()` + `generateInvoicePdf()`)
- [x] Rechnungstemplate (rechnung.tex)
- [x] Collapsible Sidebar (Icon-only-Modus, localStorage)
- [x] Profil-Dropdown (Avatar-Kreis, Daten/Passwort/Abmelden)
- [x] Multi-Tenant IDOR-Schutz auf allen Routen
- [x] LaTeX-Injection-Schutz (texEscape + RAW_-Prefix)
- [x] LaTeX-Log nicht in HTTP-Response (nur server-seitig)
- [x] Backup-Skripte (scripts/backup.sh + scripts/restore.sh)
- [x] Verify-Skript (scripts/verify.sh): Stack, Backup, Restore-Probe, Schema, Statistiken, pdflatex-Test
- [x] Dokumentation: BACKUP.md, DATENBANK.md, schema.sql, ER-Diagramm, STATISTIK.md
- [x] Demo-Datensatz (database/seed_demo.sql) mit Fantasienamen für Screenshots
- [x] pdflatex Root-Cause analysiert und alle Template-Bugs behoben (siehe unten)
- [x] Notifications-Tabelle + Postfach-UI (`/portal/postfach`) mit Navbar-Badge
- [x] Audit-Log-Tabelle (append-only) + `src/Audit.php` + Hooks an allen kritischen Aktionen
- [x] `src/Notify.php` mit `create()` und `existsRecent()` (Deduplizierung)
- [x] Audit-Log-Seiten: `/portal/audit` (Manager) und `/admin/audit` (Platform-Admin)
- [x] Steuer-Konfiguration POST-Route (`/portal/settings/tax`) ergänzt
- [x] MQTT-Subscriber auf Dual-Topic erweitert:
  - `eeg/+/meter/+/live` (ESP32-Legacy: slug + meter_code, bestehend)
  - `eeg/+/meter/+/power` (Node-RED/neu: RC-Nummer + zaehlpunkt_nr)
  - Unbekannter Zählpunkt → Postfach-Meldung (manager + platform_admin) + Audit, Dedupe 6 h
- [x] Node-RED Testflow dokumentiert (`docs/NODERED_TEST.md`)

### In Arbeit / noch offen

- [ ] **Docker-Images auf Raspi neu bauen**: `git pull && docker compose build --no-cache webapp latex-service && docker compose up -d` — danach `bash scripts/verify.sh`
- [ ] **Migration einspielen**: `docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260620.sql`
- [ ] **MQTT-Subscriber neu bauen**: `docker compose build --no-cache mqtt-subscriber && docker compose up -d mqtt-subscriber`
- [ ] **Node-RED Testflow** einrichten und verifizieren (siehe `docs/NODERED_TEST.md`)
- [x] **Abrechnung komplett fertigstellt**: `Billing::compute()` (idempotent, EDA-only, anteiliger Beitrag) + `Billing::release()` (60-Tage-Check) + PDF-Erzeugung via latex-service (Bugs behoben: `vars`-Key, Binär-Response, `RAW_STEUER_ZEILE` nie leer, `SELECT *` für Community) + neue UI-Routen: Lauf anlegen (`POST /portal/billing`), Berechnen (`POST /portal/billing/compute`)
- [x] **Mitglieder-Rechnungen**: `/portal/invoices` zeigt echte Rechnungen mit PDF-Download (nach `Billing::compute()`)
- [x] **Layout-Regression behoben**: Profil-Dropdown (style.display statt CSS-Klasse, `display:none` inline) + kritisches CSS im `<style>`-Block in portal.php (resistent gegen gecachte app.css) + Verträge für `prosumer`-Typ
- [x] **DB-Migration idempotent**: `migrate_20260620.sql` mit `IF NOT EXISTS` + DO-Blöcken für Policies; `notifications`/`audit_log` in `init.sql` ergänzt; `invoice_items` RLS-Policy in `init.sql` ergänzt
- [ ] **E-Mail-Versand**: SMTP-Integration für Passwort-Reset und Rechnungsversand (Brevo/Postmark)
- [ ] **EDA-Import UI**: Upload-Formular vorhanden, Parser-Output-Darstellung ausbaubar
- [ ] **60-Tage-Freigabe End-to-End-Test**: UI + Backend implementiert, Raspi-Test fehlt
- [ ] **TLS für MQTT** (Port 8883): Mosquitto-Config vorbereitet, Zertifikate fehlen noch
- [ ] **SEPA-Lastschrift**: Template-Platzhalter vorhanden, kein Code
- [ ] **Automatische Backups**: Cron-Job auf Raspi einrichten

---

## Bekannte Besonderheiten und Architekturentscheidungen

### Zwei strikt getrennte Datenpfade

`esp_measurements` (ESP32, Echtzeit) ≠ `eda_measurements` (Netzbetreiber, Abrechnung). Diese Trennung ist gesetzlich notwendig: Abrechnungsgrundlage sind ausschließlich die offiziellen EDA-Daten. Die ESP32-Daten dienen nur zur Visualisierung und sind entsprechend beschriftet.

### 60-Tage-Korrekturfenster ist hardcodiert

Das Datum `freigabe_nach` in `billing_runs` wird aus `period_to + 60 Tage` berechnet und im Code geprüft. Kein Obmann-Override möglich — bewusste Entscheidung für Rechtskonformität.

### Kein PHP-Framework

Bewusste Entscheidung: eigener Router (Router.php), kein Composer, kein ORM. Hält den Stack klein, ist im HTL-Lehrplan verankert, und die Komplexität des Projekts rechtfertigt kein Framework.

### Platform-Admin hat keine community_id in der Session

`Auth::isPlatformAdmin()` gibt `true`, wenn die aktive Rolle `platform_admin` ist. In diesem Fall ist `Auth::activeCommunityId()` `null`. Alle Routen, die auf community-spezifische Daten zugreifen, holen die `community_id` aus dem DB-Record des Zielobjekts (nicht aus der Session) und prüfen IDOR via `if (!Auth::isPlatformAdmin() && Auth::activeCommunityId() !== $record['community_id'])`.

### latex-service: RAW_-Präfix für LaTeX-Syntax

Der latex-service escaped alle Template-Variablen via `escapeTex()`. Variablen mit dem Präfix `RAW_` werden ohne Escaping eingesetzt. Wird verwendet für Tabellen-Zeilen, die LaTeX-Syntax (`&`, `\\`) enthalten. PHP escaped die Zell-Inhalte vorher mit `texEscape()`, der Service übernimmt die assemblierten Zeilen unverändert.

### Lokale Entwicklung vs. Produktion

`docker-compose.override.yml` deaktiviert Traefik und öffnet webapp direkt auf Port 80. Für Produktion diese Datei löschen/umbenennen, damit Traefik aktiv wird und Let's Encrypt-Zertifikate ausstellt.

---

## Migrations-Workflow

Beim **ersten Start** führt TimescaleDB `database/init.sql` automatisch aus.

Für **spätere Änderungen** an der laufenden DB:
1. Migration in `database/migrate_YYYYMMDD.sql` anlegen
2. Auf Raspi: `docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql`
3. Datei committen (damit Fabian/Alexander nachziehen können)

`init.sql` wird bei bestehender DB nicht nochmals ausgeführt — sie ist die Referenz für einen Neustart von Null.
