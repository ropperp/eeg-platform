# EEG-Plattform — Architektur & Deployment-Wissen für Claude

Kurzreferenz für einen neuen Chat-Kontext (stromfueralle.at, Diplomarbeit HTL 2026/27). Die Datei
bleibt bewusst kompakt (Richtwert: unter 300 Zeilen). **Nichts Historisches hier anhängen** — siehe
„Wohin mit was“.

## Wohin mit was

| Inhalt | Ort |
|---|---|
| Neuer Vorfall (Symptom, Ursache, Fix) | `docs/VORFAELLE.md` — bei unklarem Symptom **zuerst dort suchen** |
| Einmalige Schritte nach einem Update (Migration, Cron, Volume) | `docs/BETRIEBSHANDBUCH.md` (nur der neueste Eintrag am Ende ist für ein laufendes System relevant) |
| DB-/Daten-„weg“, Pfade und Mounts | `docs/INFRASTRUKTUR_PFADE.md` |
| nginx-Proxy, Wartungsseite, Netzwerk-Diagramm | `docs/NGINX_PROXY.md` |
| Messe-/Präsentations-Demo (simulierte Werte) | `docs/MESSE_DEMO.md` |
| App-API, Datenbank, Backup, DSGVO, Docker-Install | `docs/APP_API.md`, `DATENBANK.md`, `BACKUP.md`, `DSGVO.md`, `DOCKER_INSTALL.md` |
| Manuelle Installation | `SETUP.md` |

Hier gehören nur dauerhaft gültige Architektur, Regeln und Stolperfallen hin.

## Arbeitsregeln

- **Commit und Push auf `main` ohne Rückfrage** (Patricks Dauer-Präferenz, hat Vorrang vor
  Task-Anweisungen wie „nicht selbstständig deployen“, sofern nicht ausdrücklich anders gesagt).
- **Ausnahme:** Bei fest vorgegebenem Arbeits-Branch (z. B. `claude/...` in Claude Code on the web)
  dort committen und pushen, danach **sofort** PR erstellen und selbst mergen, ohne Rückfrage.
  Auf dem Produktivserver wird nur `main` deployt (Patrick pullt/baut selbst).
- **Öffentliches Repo:** keine Passwörter, Tokens, Zugangsdaten, E-Mail-Adressen oder Personendaten
  (Familie, Mitglieder, Kunden) in Code, Doku oder Log. Keine wörtlichen Prompts oder Zitate.
- Tests: `make test` (Unit), `make test-integration` (echte TimescaleDB, RLS), `make test-e2e` (ganzer Stack:
  Login, Rechte, App-API, EDA-Worker), `tests/http/headers_test.sh`; alles auch im CI, dazu PHPStan (`phpstan.neon`, Baseline nur abbauen, nicht
  erweitern). Neue Doku-Erkenntnisse sofort in die passende Datei oben einarbeiten.

## Git-Workflow

- `main` ist immer deploybar. Kleine Änderungen (Doku, Bugfix) direkt auf `main`; größere oder
  riskantere Arbeit auf `feature/<kurzname>` (oder dem vorgegebenen `claude/...`-Branch) per PR.
- Tags `vX.Y.Z` ([SemVer](https://semver.org)) markieren getestete Stände, jeder mit Eintrag in
  `CHANGELOG.md`: PATCH = Bugfix, MINOR = neue Funktion, MAJOR = Umbau bzw. Produktivstart
  (`0.x` = vor Produktivstart). Zweck: Deploy und Rollback auf einen bekannten Stand.

```bash
git switch -c feature/mein-thema && git push -u origin feature/mein-thema   # dann PR nach main
git tag -a v0.9.1 -m "0.9.1 – <kurz>" && git push origin v0.9.1             # nach Merge, auf main
git checkout v0.9.0 && docker compose up -d --build                         # Rollback auf Tag
```

## Netzwerk

```
Internet → :443 nginx-Proxy (10.0.0.144, SSL via Certbot) → :80 Traefik (10.0.0.250, Docker)
         → webapp (nginx + PHP 8.4, internes Docker-Netz)
```

Der nginx-Proxy ist ein eigener Host; seine Config liegt nicht im Repo (Details in
`docs/NGINX_PROXY.md`). Drei Punkte, die man oft vergisst:
- `client_max_body_size 20M;` muss dort gesetzt sein, sonst `413` bei Datei-Uploads.
- `www.stromfueralle.at` muss als SAN im Zertifikat stehen.
- Die Wartungsseite greift nur bei 502/503/504 (nicht bei 404) und braucht `proxy_intercept_errors on;`.

## EEG-Server (10.0.0.250)

```
/opt/eeg-platform/   ← Git-Repo (branch: main)
/opt/eeg/            ← Persistente Daten (DB, Redis, Mosquitto, Traefik-Certs, Webapp-Storage, LaTeX-Vorlagen)
```

`/opt/eeg/webapp-storage` enthält Mitglieder-Uploads und generierte Vertrags-/Rechnungs-PDFs
(Volume seit 14.07.2026). **Muss im Server-Backup sein.**

| Service | Image | Ports (Host) | Zweck |
|---------|-------|-------------|-------|
| traefik | traefik:v3.7.19 | 80:80 | Reverse Proxy, nur File-Provider (kein Docker-Socket) |
| timescaledb | timescale/timescaledb-ha:pg16 | — | PostgreSQL + TimescaleDB |
| redis | redis:7.4.12-alpine | — | Session-Cache |
| mosquitto | eclipse-mosquitto:2 (2.1.2) | 1883, 8883 | MQTT-Broker |
| mqtt-subscriber | (build) | — | MQTT → DB (+ Messe-Demo-Thread) |
| eda-worker | (build, `eda-parser/`) | — | EDA-Import-Jobs (Upload + Postfach) → DB |
| webapp | (build) | — | nginx + PHP 8.4 |
| latex-service | (build) | — | PDF-Generator |
| konsole | (build, `konsole/`) | 8090 | Steuerzentrale `admin.<DOMAIN>`: Wartungsmodus, Status/Logs/Neustart |
| keycloak | (build, `keycloak/`, Profil `keycloak`) | 127.0.0.1:8081 | Anmeldedienst `auth.<DOMAIN>` (ADR 0007, in Umsetzung) |

Alle Images sind mit Version **und Digest** gepinnt (Compose + Dockerfiles); Updates kommen als
Dependabot-PR (`.github/dependabot.yml`), nie automatisch.

### Traefik
- Hört **nur auf Port 80**; SSL macht der nginx-Proxy.
- **Kein Docker-Provider, kein Docker-Socket** (seit 15.11.2026; ein Socket-Mount wäre
  Vollzugriff auf den Docker-Dienst). Alle Router stehen in `docker/traefik/dynamic.yml`. Neue
  Dienste mit eigener Adresse dort eintragen, nicht per Label.
- Die Domain ist dort **hart eingetragen** (`stromfueralle.at`); der File-Provider kennt keine
  `${DOMAIN}`-Substitution. Bei Domain-Änderung von Hand mitziehen.
- Router: `stromfueralle.at`/`www`, `live.`, `portal.` und `webapp.mechtronix.at` (legacy) → Service
  `webapp` (`http://webapp:80`). Traefik liest den ganzen Ordner `docker/traefik/`; im Wartungsmodus legt
  die Konsole dort `maintenance.yml` ab (in `.gitignore`).
- Warum File-Provider statt Labels (Router verschwanden mit dem gestoppten Container → nacktes
  Traefik-404 statt 502): `docs/VORFAELLE.md`, Vorfall 25.09.2026.
- `--entrypoints.web.forwardedHeaders.trustedIPs=${TRUSTED_PROXY_IP:-10.0.0.144}`: `X-Forwarded-For`
  wird nur vom nginx-Proxy übernommen. Die webapp liest die Client-IP über `clientIp()`
  (`webapp/src/functions.php`, von rechts). Ändert sich die Proxy-IP: `TRUSTED_PROXY_IP` in der `.env`.

### Typische Stolperfallen
- **Kein `docker-compose.override.yml` auf dem Produktivserver** (deaktiviert Traefik, mappt Port 80
  direkt auf die webapp, `APP_ENV=development`). Seit 16.11.2026 nicht mehr versioniert: Vorlage
  `docker-compose.dev.yml`, lokal `cp docker-compose.dev.yml docker-compose.override.yml`.
- **LaTeX-Vorlagen:** `/opt/eeg/latex-templates` ist ein persistentes Volume (für `/admin/templates`)
  und **gewinnt immer** gegen die im Image mitgelieferte Version. „Meine Vorlagen-Änderung kommt
  auf dem Server nicht an“ → zuerst `ls -la /opt/eeg/latex-templates/`. Liegt die Datei dort und war
  nie bewusst angepasst: löschen (`rm …/<datei>.tex`), dann greift die Image-Version wieder. Bewusst
  angepasste Dateien stattdessen über `/admin/templates` neu hochladen. Hintergrund: `docs/VORFAELLE.md`.
- **Verträge werden live gerendert, nicht als PDF gespeichert.** Damit ein unterschriebener Vertrag nach einer
  Textänderung gleich bleibt, gibt es eingefrorene Kopien (`*_elwog2010.tex`) und `members.contract_*_template`;
  `contractTemplateName()` (portal.php) wählt die Vorlage. **Neue Fassung = neue Vorlage + Altfassung einfrieren**,
  nie die Datei der Altfassung ändern. Rechnungs-PDFs sind ab Freigabe eingefroren (`pdf_frozen_path`).
- **Angaben, die nicht in den Code gehören** (Betreiber-Anschrift/Rechtsform, Hosting-Anbieter, Netzgebühr-%):
  `platform_settings`, Admin → Einstellungen → Allgemein (`platformSettingText()`). Lieferantenpflichten § 69 ElWG:
  Schalter `communities.supplier_duties_enabled` (`communitySupplierDuties()`), Standard aus.
- **Assets immer mit `assetUrl('/assets/…')` einbinden** (hängt `?v=<mtime>` an). nginx cached nur
  versionierte Assets lange (`immutable`); Security-Header stehen in `webapp/docker/security-headers.conf`
  und müssen in jeder location mit eigenem `add_header` erneut per `include` eingebunden werden.
- **Neues Storage-Volume vor `up --build` anlegen**, sonst gehört es root und PHP (www-data, UID 82)
  kann nicht schreiben: `sudo mkdir -p <pfad> && sudo chown -R 82:82 <pfad>`.

## .env auf dem Server

Datei `/opt/eeg-platform/.env` (nicht in Git, nie committen). Pflichtfelder: `DB_USER`, `DB_PASSWORD`,
`DB_NAME`, `DOMAIN`, `APP_SECRET` (64 Zeichen zufällig), `LATEX_API_KEY`, `SMTP_HOST`, `SMTP_USER`,
`SMTP_PASSWORD`. Optional: `TRUSTED_PROXY_IP`, `MESSE_DEMO_LAT`/`MESSE_DEMO_LON`, Keycloak (`KC_*`, `COMPOSE_PROFILES`, `GOOGLE_*`;
trägt `scripts/keycloak_setup.sh` ein). Kein `ACME_EMAIL`
nötig (Traefik macht kein Let's Encrypt mehr).

**Datenbank-Rollen** (`scripts/db_runtime_role_setup.sh` erzeugt `APP_DB_*` und `WORKER_DB_*`, SQL in
`scripts/sql/runtime_roles.sql`):

| Rolle | Wer | Rechte |
|---|---|---|
| `DB_USER` (`eeg`) | Migrationen, Backup | Superuser (Postgres-Image), Schema-Besitzer |
| `APP_DB_USER` (`eeg_app`) | Webapp | nur Daten, **RLS greift**; in Produktion Pflicht |
| `WORKER_DB_USER` (`eeg_worker`) | mqtt-subscriber, eda-worker | nur Daten, `BYPASSRLS`, kein Superuser |

Domain, Portal-Host, öffentliche URLs und Kontakt-Rückfall kommen aus `webapp/src/AppConfig.php`
(`DOMAIN`, optional `PORTAL_HOST`/`CONTACT_EMAIL`) -- keine neuen fest codierten `stromfueralle.at` im Code.
Fehlerseite zeigt nur eine Fehlernummer (Log: `grep <nummer>`), Details nur mit `APP_DEBUG=1`.

`APP_ENV` (Standard `production`): ohne `APP_DB_USER` startet die Webapp nicht, der Healthcheck
(`webapp/bin/db_role_check.php`) meldet eine falsch privilegierte Rolle als `unhealthy`. Lokal setzt
`docker-compose.override.yml` (Kopie von `docker-compose.dev.yml`) `APP_ENV=development`.

## Neuinstallation und Update

```bash
# Neuinstallation: .env mit Zufalls-Secrets, /opt/eeg (inkl. 82:82-Rechte), Container, Migrationen,
# erster Platform-Admin (interaktiv)
git clone https://github.com/ropperp/eeg-platform.git /opt/eeg-platform && cd /opt/eeg-platform
./scripts/setup.sh
docker compose ps && curl -H "Host: stromfueralle.at" http://localhost/

# Update (laufendes System)
cd /opt/eeg-platform && git pull origin main && docker compose up -d --build

# Neue DB-Migration
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
```

Manuelle Variante: `SETUP.md`. Docker-Installation: `docs/DOCKER_INSTALL.md`. Einmalige Schritte
nach einzelnen Updates: `docs/BETRIEBSHANDBUCH.md`.

## Container-Healthchecks & Selbstheilung

Jeder Container hat einen `healthcheck` (`docker-compose.yml`), `mqtt-subscriber` schreibt dazu
`/tmp/mqtt_subscriber_healthy`. `scripts/health_monitor.sh` läuft als Host-Cron: findet es einen
Dienst `unhealthy`/gestoppt, startet es ihn 1–2× neu und schickt sonst eine Alarm-Mail
(`scripts/health_alert.php`, Cooldown 6 h je Dienst in `/opt/eeg/health-monitor/`).

```bash
( crontab -l 2>/dev/null; echo "*/5 * * * * cd /opt/eeg-platform && bash scripts/health_monitor.sh >> /var/log/eeg-health.log 2>&1" ) | crontab -
```

## EDA-Import (eda-worker)

Upload (`/portal/eda/upload`) und Postfach-Abruf (Cron `scripts/eda_auto_import.php`) legen nur einen
Job in `eda_import_jobs` an; der Container `eda-worker` (`eda-parser/worker.py`) ruft die unveränderten
Parser auf und schreibt Ergebnis + Audit. Die Webapp enthält kein Python mehr. Hängt ein Import:
`docker compose ps eda-worker`, `docker compose logs --tail=50 eda-worker`, Jobs:
`SELECT status, count(*) FROM eda_import_jobs GROUP BY 1;`. Parser-Werkzeuge (z. B. `--resplit`) jetzt
mit `docker compose exec eda-worker python parser.py …`. Entscheidung: `docs/adr/0005-eda-import-als-worker.md`.

## Steuerzentrale (konsole)

`admin.<DOMAIN>` → nginx-Proxy **direkt** auf Port 8090 (nicht über Traefik), eigener Container ohne
`depends_on`. Anmeldung über Keycloak (gemeinsame Sitzung mit dem Portal, nur `platform_admin`), Notzugang per
Passkey eines Platform-Admins (`/?passkey=1`, Kopie in `/opt/eeg/konsole/passkeys.json`, damit er auch bei
DB-Ausfall geht). Kann Wartungsmodus setzen, Status/Logs zeigen und Dienste aus einer
festen Liste neu starten (Docker-Socket, nichts sonst). Aktionen in `/opt/eeg/konsole/audit.log`.
Entscheidung und Restrisiko: `docs/adr/0006-steuerzentrale.md`, nginx-Block: `docs/NGINX_PROXY.md`.

## Anmeldedienst (keycloak)

Keycloak unter `auth.<DOMAIN>` (Traefik-Router `auth`, öffentlich nur `/realms/stromfueralle/` und `/resources/`;
Admin-Konsole nur per SSH-Tunnel auf `127.0.0.1:8081`). Startet nur mit `COMPOSE_PROFILES=keycloak`
(setzt `scripts/keycloak_setup.sh`). Eigene DB `keycloak`. Keycloak sagt nur, **wer** jemand ist; Rollen, RLS und
Fachlogik bleiben in der Plattform, Verknüpfung über `users.keycloak_id`, nie über die E-Mail. Passwörter
werden still übernommen (Argon2id direkt, bcrypt über die Erweiterung `keycloak/providers`, Umstellung auf
Argon2 beim ersten Login). Realm-Datei `keycloak/realm/` gilt nur beim **ersten** Start; Änderungen am
bestehenden Realm kommen in `scripts/keycloak_realm_update.sh` (idempotent).
Portal-Anmeldung: Schalter `AUTH_PROVIDER` (`keycloak-test` = nur `/portal/login?keycloak=1`, `keycloak` = alle),
Rücksprung `/portal/auth/callback`. **Das Portal bleibt Quelle** für Profil, Passwort, 2FA, Sperre: Trigger
schreiben Änderungen in `keycloak_sync_queue`, die Webapp gleicht nach jeder Anfrage ab, Cron
`bin/keycloak_sync.php` holt nach. Keycloak aus → automatisch bisherige Anmeldung. Details: `docs/adr/0007-keycloak.md`.

## Statuten-Generator (nicht öffentlich)

Platform-Admin → `/admin/statuten-einladungen` erzeugt einen persönlichen Link `/statuten/{token}` (256 Bit, in der
DB nur SHA-256, **nur einmal angezeigt**). Interessent füllt ohne Login den Fragebogen aus; nach „Statuten
erzeugen“ ist der Link 90 Tage **nur Download**. Tabellen `statute_invites`/`statute_drafts` (plattformweit, ohne
RLS), PDFs in `/opt/eeg/webapp-storage/statuten/`, Vorlagen `statuten.tex` (Querverweise **nur** `\label`/`\ref`),
`statuten_anzeige.tex`, `protokoll.tex`. Löschung nach 12 Monaten: Cron `scripts/statute_cleanup.php`.
Prüfregeln/Rechtsstand: `docs/STATUTEN_GENERATOR.md`, Entscheidung: `docs/adr/0008-statuten-generator.md`.

## Messe-/Präsentations-Demo

Checkbox „Simulierte Werte anzeigen“ in den Obmann-Einstellungen. Ein Thread im `mqtt-subscriber`
legt 20 fiktive Zählpunkte (`is_demo=true`, nie abrechnungsrelevant) an und publiziert echte
MQTT-Werte (echtzeit-synchrones Tagesprofil, Einspeisung nach echtem Wetter von Open-Meteo). Solange
aktiv, zeigt die Oberfläche einen Hinweis auf fiktive Werte. Vollständige Beschreibung:
`docs/MESSE_DEMO.md`.

## Code-Struktur (Webapp)

`public/index.php` = Bootstrap; Routen in `webapp/routes/{public,portal,admin,api}.php` (Reihenfolge =
Priorität). Neuer Code nach PSR-4 im Namensraum `App\` (`src/Http`, `src/Domain`, `src/Infrastructure`),
Vorlage: Support-Tickets. Keine neue Fachlogik als Closure-Block, SQL nur in Repositories.
Details und Regeln: `docs/ARCHITEKTUR.md`, Entscheidungen: `docs/adr/`.
Öffentliche Info-Seiten (`/funktionen…`, `/faq`, `/energiegemeinschaft-feldkirchen`): Texte nur in
`webapp/src/content/marketing_pages.php`, eine Vorlage `views/pages/marketing_page.php`. FAQPage-Daten
nur auf `/faq`; `public/sitemap.xml` und `public/llms.txt` bei neuen Seiten mitziehen.

## Frontend-Regeln (Content-Security-Policy)

Die Plattform sendet `script-src 'self' 'nonce-…'` und `script-src-attr 'none'` (PHP: `cspPolicy()` in
`webapp/src/functions.php`; **nicht** in nginx setzen). Für neuen Code:
- **Kein** `onclick=`/`onchange=`/`onsubmit=`/… und kein `javascript:`-Link. Stattdessen die
  `data-`-Attribute aus `webapp/public/assets/js/ui-actions.js` (`data-confirm`, `data-danger-confirm`,
  `data-onclick="funktion" data-arg-0=…`, `data-dialog-open`, `data-print`, …).
- Jedes Inline-`<script>` braucht `nonce="<?= cspNonce() ?>"` (außer `application/ld+json`); größere
  Skripte als Datei unter `assets/js/`.
- `tests/csp_static_test.php` (CI) und `tests/browser/csp.js` schlagen bei Verstößen an.

## Doku-Pflege (Obsidian-Spiegel)

`obsidian/Infrastruktur.md` ist ein Spiegel dieser Datei für Patricks Vault (Workflow:
`obsidian/README.md`). **Bei jeder inhaltlichen Änderung an dieser Datei die Spiegelnotiz mit
anpassen.** `docs/VORFAELLE.md`, `docs/BETRIEBSHANDBUCH.md`, `docs/NGINX_PROXY.md` und
`docs/MESSE_DEMO.md` gehören bewusst nicht zum Spiegel; dort steht nur eine knappe Zusammenfassung.

## Selbstdokumentation (Claude-Sitzungslog)

Patrick braucht für die Diplomarbeit nachvollziehbar, welches Modell wann welchen Auftrag bearbeitet
hat. **Jede** Claude-Sitzung (Claude Code, Chat, Cowork) schreibt am Ende einen Eintrag.

- **Vorher suchen:** vor jeder Recherche zuerst `obsidian/Claude-Sitzungslog.md` und
  `obsidian/Problemstellungen.md` durchsuchen. Treffer: Datum und Kurzinhalt in einem Satz nennen,
  darauf aufbauen. Kein Treffer: normal recherchieren.
- **Datenschutz (Repo ist öffentlich, 10.10.2026):** kein wörtlicher Prompt, keine Personendaten,
  Zugangsdaten oder E-Mail-Adressen. Ältere Einträge bleiben unverändert.
- **Format** (neueste zuerst):

```markdown
## JJJJ-MM-TT HH:MM — <Werkzeug> — <Modell>
**Auftrag:** <Anliegen, geglättet zusammengefasst, 1–3 Sätze, kein wörtliches Zitat>
**Verbesserter Prompt:** <so hätte man direkt zum Ergebnis kommen können; knapp, ohne Privates>
**Ergebnis:** <Commits, Dateien, offene Punkte; 1–3 Sätze>
**Fehler/Lehre:** <nur falls etwas schiefging; sonst weglassen>
```

- Werkzeug: `Claude Code` / `Claude Chat` / `Cowork`. Modell so genau wie bekannt.
- **Wann:** Wirkt eine Aufgabe abgeschlossen, Patrick fragen, ob jetzt gespeichert werden soll.
  Wird danach weitergearbeitet, den **bestehenden** Eintrag aktualisieren.
- **Wohin:** Claude Code → oben in `obsidian/Claude-Sitzungslog.md` (mit committen; wird in den Vault
  gespiegelt, eine geplante Aufgabe legt daraus täglich Notizen in `Claude Chats/Einträge/` an).
  Cowork/Chat → direkt in den Vault: `eeg-platform-notes/logs/JJJJ-MM-TT.md` (eine Datei pro Tag,
  anhängen); `logs/` wird vom Doku-Sync nie überschrieben.
