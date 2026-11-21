---
tags: [eeg-platform, infrastruktur, stromfueralle]
quelle: CLAUDE.md (eeg-platform Repo-Root)
---

# EEG-Plattform — Infrastruktur

> Spiegel von `CLAUDE.md` im [eeg-platform](https://github.com/ropperp/eeg-platform)-Repo.
> Bei jeder Änderung an `CLAUDE.md` wird diese Notiz mit aktualisiert.

## Git-Workflow: Branches, Tags & Versionierung

Seit 0.9.0 mit schlanker Branch-/Tag-Strategie:

- **`main`** ist immer deploybar (`git pull && docker compose up -d --build`). Kleine, klare
  Änderungen dürfen direkt auf `main`.
- **Feature-Branches** (`feature/<kurzname>` bzw. der von der Umgebung vorgegebene
  `claude/<...>`-Branch) für größere/riskante Arbeit → testen (`make test` + CI) → per Pull
  Request nach `main` mergen. Hält `main` jederzeit lauffähig. Auf einem fest vorgegebenen
  Arbeits-Branch (Claude Code on the web): PR sofort selbst erstellen UND mergen, ohne
  nachzufragen (Patrick, 07.08.2026).
- **Tags** (`vX.Y.Z`, Semantic Versioning) markieren getestete Stände: PATCH = Bugfix,
  MINOR = neue Funktion, MAJOR/`1.0.0` = großer Umbau bzw. Produktivstart. `0.x` = vor dem
  Produktivstart. Jeder Tag hat einen `CHANGELOG.md`-Eintrag.

**Nutzen:** Ein Tag ist ein benannter Fixpunkt → jederzeit einen getesteten Stand deployen oder
dorthin zurückrollen, im Changelog nachlesen was sich geändert hat, und gegenüber der HTL/
Diplomarbeit den Funktionsstand pro Zeitpunkt dokumentieren.

```bash
git switch -c feature/mein-thema        # neuen Branch beginnen
git push -u origin feature/mein-thema   # dann PR nach main
git tag -a v0.9.1 -m "0.9.1 – ..." && git push origin v0.9.1   # Release taggen
git checkout v0.9.0 && docker compose up -d --build            # Stand deployen/zurückrollen
```

## Netzwerk-Architektur

```
Internet
   │
   ▼ Port 443 (HTTPS)
nginx-Proxy (10.0.0.144 / öffentliche IP: 80.122.212.226)
   │  SSL-Terminierung via Certbot/Let's Encrypt
   │  Zertifikat: /etc/letsencrypt/live/stromfueralle.at/
   │
   ▼ HTTP Port 80 (intern: 10.0.0.250)
Traefik (Docker, Port 80)
   │  Routing per Host-Header
   │
   ▼
webapp (nginx + PHP 8.4, internes Docker-Netz)
```

### nginx-Proxy-Config (auf 10.0.0.144)
Datei: `/etc/nginx/sites-available/70_stromfueralle.conf`

```nginx
server {
    listen 443 ssl;
    server_name stromfueralle.at www.stromfueralle.at;
    ssl_certificate     /etc/letsencrypt/live/stromfueralle.at/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/stromfueralle.at/privkey.pem;
    include             /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;
    client_max_body_size 20M;
    include             snippets/eeg-maintenance.conf;
    location / {
        proxy_http_version 1.1;
        proxy_set_header   Connection        "";
        proxy_pass         http://10.0.0.250;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto https;
        proxy_intercept_errors on;
        proxy_connect_timeout 5s;
    }
}
server {
    listen 80;
    server_name stromfueralle.at www.stromfueralle.at;
    return 301 https://$host$request_uri;
}
```
Derselbe `include snippets/eeg-maintenance.conf;` + `proxy_intercept_errors on;` auch im
`portal.stromfueralle.at`-Block in derselben Datei.

> `client_max_body_size 20M;` muss hier gesetzt sein (Standard-Limit von nginx ist nur 1 MB) — sonst
> liefert **dieser** nginx-Proxy bei Datei-Uploads (z. B. Ausweis-Scan, Beitrittserklärung-PDF) einen
> `413 Request Entity Too Large`, obwohl `webapp/docker/nginx.conf` und `php.ini` im Repo bereits
> korrekt auf 20M stehen. Nach Änderung: `sudo nginx -t && sudo systemctl reload nginx`.

> `www.stromfueralle.at` muss als SAN im Zertifikat enthalten sein, sonst liefert nginx
> für www das Default-Zertifikat aus und Browser zeigen einen SSL-Fehler.

### Eigene Wartungsseite bei Serverausfall (seit 25.09.2026)
Statt nginx' Standard-Fehlerseite/nacktem Verbindungsfehler zeigt der nginx-Proxy jetzt eine
eigene, markenkonforme Seite ("Kurzschluss" — animierte durchhängende/reißende Stromleitung),
sobald 10.0.0.250 (Traefik/Pi) 502/503/504 liefert oder unerreichbar ist. `/etc/nginx/error_pages/
wartung.html` (eigenständig, kein externes CSS/JS, `<meta refresh>` alle 30s) + `/etc/nginx/
snippets/eeg-maintenance.conf` (der `error_page`/`location`-Block, in beide server{}-Blöcke
eingebunden) -- nur auf dem nginx-Proxy-Host, nicht im Git-Repo. `proxy_intercept_errors on;`
nötig, sonst reicht nginx Traefiks eigene 5xx-Antwort roh durch. Bewusst NICHT auf 404 erweitert
(die App hat eine echte eigene 404-Seite, die soll nicht überdeckt werden). Vollständige nginx-Config und Details: `docs/NGINX_PROXY.md`. Voraussetzung, dass
ein nur gestopptes `webapp` (nicht der ganze Pi) überhaupt 502/503/504 statt eines nackten
Traefik-404 liefert: siehe "Webapp-Router" weiter unten (File-Provider statt Docker-Labels).

## EEG-Server (10.0.0.250)

### Verzeichnis
```
/opt/eeg-platform/   ← Git-Repo (branch: main)
/opt/eeg/            ← Persistente Daten (DB, Redis, Mosquitto, Traefik-Certs, Webapp-Storage)
```

> `/opt/eeg/webapp-storage` (→ `/var/www/html/storage`) enthält Mitglieder-Uploads,
> Beitrittserklärungen und generierte PDFs. Vorher nur im Container — ging bei jedem `--build`
> verloren. Seit 14.07.2026 ein echtes Volume, unbedingt ins Backup aufnehmen.

### Docker-Stack

| Service | Image | Ports (Host) | Zweck |
|---------|-------|-------------|-------|
| traefik | traefik:v3.7.19 | 80:80 | Reverse Proxy, nur File-Provider (kein Docker-Socket) |
| timescaledb | timescale/timescaledb-ha:pg16 | — | PostgreSQL + TimescaleDB |
| redis | redis:7.4.12-alpine | — | Session-Cache |
| mosquitto | eclipse-mosquitto:2 (2.1.2) | 1883, 8883 | MQTT-Broker |

Alle Images mit Version und Digest gepinnt; Updates nur als Dependabot-PR (seit 16.11.2026).
| mqtt-subscriber | (build) | — | MQTT → DB |
| eda-worker | (build) | — | EDA-Import-Jobs (Upload + Postfach) → DB |
| webapp | (build) | — | nginx + PHP 8.4 |
| latex-service | (build) | — | PDF-Generator |
| konsole | (build, `konsole/`) | 8090 | Steuerzentrale `admin.<DOMAIN>`: Wartungsmodus, Status/Logs/Neustart |
| keycloak | (build, `keycloak/`, Profil `keycloak`) | 127.0.0.1:8081 | Anmeldedienst `auth.<DOMAIN>` (ADR 0007, in Umsetzung) |

### Wichtige Traefik-Details
- Traefik hört **nur auf Port 80** (kein HTTPS, kein Let's Encrypt) — SSL macht der nginx-Proxy
- **Kein Docker-Provider, kein Docker-Socket** (seit 15.11.2026): kein Dienst trägt Traefik-Labels, alle
  Router stehen in `docker/traefik/dynamic.yml`. Ein Socket-Mount (auch `:ro`) ist Vollzugriff auf den
  Docker-Dienst des Servers -- bei einer Traefik-Übernahme wäre der ganze Server offen. Neue Dienste mit
  eigener Adresse daher in `dynamic.yml` eintragen, nicht per Label. (Die frühere `DOCKER_API_VERSION`-
  Variable entfällt damit ebenfalls.)
- `--entrypoints.web.forwardedHeaders.trustedIPs=${TRUSTED_PROXY_IP:-10.0.0.144}` (seit 07.11.2026):
  Traefik übernimmt `X-Forwarded-For`/`X-Real-IP` NUR vom nginx-Proxy, sonst verwirft er sie --
  ohne das speicherte die webapp bei Beitritt/Vertragsunterschrift immer `172.19.0.x` statt der
  echten Client-IP. Die webapp liest die IP über `clientIp()` (`webapp/src/functions.php`):
  `X-Forwarded-For` nur hinter einem vertrauenswürdigen Proxy und von RECHTS gelesen (der erste
  Eintrag ist vom Client fälschbar). Ändert sich die Proxy-IP: `TRUSTED_PROXY_IP` in der `.env`.
- **Traefik v3-Falle:** `Host()` akzeptiert nur noch **einen** Wert pro Aufruf. Mehrere Hosts
  immer mit `Host(\`a\`) || Host(\`b\`)`, NICHT `Host(\`a\`, \`b\`)` (v2-Syntax, bricht den Router).

### Webapp-Router
Seit 25.09.2026 **nicht mehr** als Docker-Labels auf dem `webapp`-Container, sondern als
Traefik-File-Provider-Konfiguration in `docker/traefik/dynamic.yml` (im Repo; seit 17.11.2026 wird der
ganze Ordner `docker/traefik/` gemountet, im Wartungsmodus legt die Steuerzentrale dort `maintenance.yml`
ab). `admin.stromfueralle.at` läuft nicht mehr über Traefik, sondern direkt zur Steuerzentrale:
```yaml
http:
  routers:
    webapp:        { rule: "Host(`stromfueralle.at`) || Host(`www.stromfueralle.at`)", entryPoints: [web], service: webapp }
    live:          { rule: "Host(`live.stromfueralle.at`)",   entryPoints: [web], service: webapp }
    portal:        { rule: "Host(`portal.stromfueralle.at`)", entryPoints: [web], service: webapp }
    webapp-legacy: { rule: "Host(`webapp.mechtronix.at`)",    entryPoints: [web], service: webapp }
  services:
    webapp:
      loadBalancer:
        servers: [{ url: "http://webapp:80" }]
```
**Warum (Vorfall 25.09.2026):** Solange die Router als Labels AUF dem `webapp`-Container selbst
standen, verschwanden sie zusammen mit dem Container beim Stoppen/Absturz -- Traefik antwortete
dann kurz danach mit einem nackten `404 page not found` statt einem 502/503/504, wodurch die neue
nginx-Wartungsseite (siehe oben) nie griff. Mit dem File-Provider bleibt der Router bestehen,
egal ob nur `webapp` oder Traefik selbst fehlt -- korrektes 502/503/504 in beiden Fällen.
`http://webapp:80` funktioniert über Dockers eingebautes DNS im `eeg-net`-Netzwerk, unabhängig
von Docker-Label-Discovery. DOMAIN dort ist hart eingetragen (keine `${DOMAIN}`-Substitution im
File-Provider) -- bei Domain-Änderung von Hand mitziehen.

### Verträge: Vorlagenfassung je Vertrag (21.11.2026)
Verträge werden bei jedem Aufruf live aus der LaTeX-Vorlage gerendert. Damit ein unterschriebener Vertrag nach
einer Textänderung gleich bleibt, gibt es eingefrorene Kopien (`*_elwog2010.tex`) und
`members.contract_*_template`; `contractTemplateName()` wählt die Vorlage. Neue Fassung = neue Vorlage plus
Altfassung einfrieren. Rechnungs-PDFs sind ab Freigabe eingefroren. Betreiber-Anschrift, Hosting-Anbieter und
Netzgebühr-% stehen in `platform_settings` (Admin → Einstellungen), Lieferantenpflichten § 69 ElWG pro Gemeinschaft
im Schalter `supplier_duties_enabled` (Standard aus).

### LaTeX-Vorlagen: persistentes Volume überdeckt jeden Git-Deploy (Vorfall 18.10.2026)
`/opt/eeg/latex-templates` (`/admin/templates`-Uploads) gewinnt IMMER gegen die im Image
mitgelieferte, aktuelle Vorlage -- liegt dort z.B. eine alte `rechnung.tex` (bewusst
hochgeladen oder Überbleibsel des alten, vor 04.10.2026 blind kopierenden Entrypoints), ändert
`git pull && docker compose up -d --build` an dieser Datei GAR NICHTS, ohne Fehlermeldung. Bei
"meine Vorlagen-Änderung kommt nicht an": `ls -la /opt/eeg/latex-templates/` prüfen, betroffene
Datei bei unbeabsichtigtem Überbleibsel einfach löschen (danach greift automatisch wieder die
aktuelle Image-Version, dauerhaft).

## .env auf dem Server

Datei: `/opt/eeg-platform/.env` (nicht in Git)

```env
DB_USER=eeg
DB_PASSWORD=<sicheres Passwort>
DB_NAME=eeg_platform
DOMAIN=stromfueralle.at
APP_SECRET=<64-Zeichen zufällig>
LATEX_API_KEY=<random>
SMTP_HOST=smtp-relay.brevo.com
SMTP_USER=<email>
SMTP_PASSWORD=<passwort>
APP_ENV=production          # Standard; ohne APP_DB_USER startet die Webapp dann nicht
APP_DB_USER=eeg_app         # + APP_DB_PASSWORD, generiert von scripts/db_runtime_role_setup.sh
WORKER_DB_USER=eeg_worker   # + WORKER_DB_PASSWORD, ebenso generiert
```

**Datenbank-Rollen (seit 16.11.2026):** `DB_USER` (`eeg`) ist Superuser und Schema-Besitzer (nur
Migrationen/Backup). Die Webapp verbindet als `eeg_app` (nur Daten, Row-Level Security greift), der
mqtt-subscriber und der EDA-Parser als `eeg_worker` (nur Daten, `BYPASSRLS`, kein Superuser). Der
Webapp-Healthcheck (`webapp/bin/db_role_check.php`) meldet `unhealthy`, wenn die Rolle zu viele Rechte hat.

**Zentrale Konfiguration (seit 16.11.2026):** Domain, Portal-Host, öffentliche Links und
Kontakt-Rückfalladresse kommen aus `webapp/src/AppConfig.php` (aus `DOMAIN`, optional `PORTAL_HOST`/
`CONTACT_EMAIL`). Die Fehlerseite zeigt nur eine Fehlernummer; Details stehen im Log
(`docker compose logs webapp | grep <nummer>`) oder mit `APP_DEBUG=1` auf der Seite.

**Assets und Caching:** Skripte/Styles immer über `assetUrl('/assets/…')` einbinden (`?v=<mtime>`); nur
versionierte Assets cached nginx ein Jahr als `immutable`. Security-Header stehen in
`webapp/docker/security-headers.conf`. Dynamisches HTML/JSON wird nie gzip-komprimiert (BREACH).

**EDA-Import (seit 16.11.2026):** eigener Container `eda-worker`. Upload und Postfach-Abruf legen nur einen
Job an (Tabelle `eda_import_jobs`), der Worker importiert; die Upload-Seite zeigt den Fortschritt.
Prüfen: `docker compose ps eda-worker`, `docker compose logs --tail=50 eda-worker`.

**Tests:** `make test` (Unit), `make test-integration` (echte TimescaleDB, RLS-Trennung), `make test-e2e` (ganzer Stack),
`tests/http/headers_test.sh` (Header gegen laufenden Container), PHPStan (`phpstan.neon`). Alles läuft in der CI.

## Update (laufendes System)

```bash
cd /opt/eeg-platform
git pull origin main
docker compose up -d --build
```

**Historie der "Einmalig nach dem Update vom ..."-Schritte ausgelagert nach
`docs/BETRIEBSHANDBUCH.md` im Repo** (am 02.10.2026, dieser Vault-Spiegel war auf über 1000
Zeilen angewachsen). Für das laufende System ist nur der jeweils NEUESTE Eintrag dort noch
relevant -- ältere sind bereits angewendet.

Bei neuen DB-Migrations:
```bash
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
```

## Bekannte Probleme & Lösungen

**Vollständiges Vorfallstagebuch ausgelagert nach `docs/VORFAELLE.md` im Repo** (am 02.10.2026,
dieser Vault-Spiegel war auf über 1000 Zeilen angewachsen). Bei einem neuen, noch unklaren
Symptom dort nachsehen -- die Pfad-/Mount-Übersicht (`docs/INFRASTRUKTUR_PFADE.md`) bleibt der
erste Blick bei DB-/Daten-„weg"-Symptomen.

## Öffentliche Info-Seiten (17.11.2026)

`/funktionen…`, `/faq`, `/energiegemeinschaft-feldkirchen`: Texte nur in `webapp/src/content/marketing_pages.php`,
eine Vorlage `views/pages/marketing_page.php`. FAQPage-Daten nur auf `/faq`; `public/sitemap.xml` und
`public/llms.txt` bei neuen Seiten mitziehen.

## Steuerzentrale (konsole, 17.11.2026)

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

## Messe-/Präsentations-Demo (simulierte Werte, 02.10.2026)

Zweck: glaubwürdiger Energiefluss für Messe/Präsentationen, bis echte Ausleseeinheiten verbaut sind, als dauerhafter Hintergrund-Schalter statt Kommandozeile. Checkbox "Simulierte Werte anzeigen" in
den Obmann-Einstellungen (ganz oben) -- läuft als Daemon-Thread dauerhaft im
`mqtt-subscriber`-Container, legt bei Bedarf automatisch 20 fiktive Zählpunkte (8 Einspeiser,
12 Verbraucher, nie abrechnungsrelevant) an und publiziert echte MQTT-Werte mit echtem,
echtzeit-synchronem Tagesprofil (Verbraucher mit Nacht-Grundlast + Morgen-/Mittags-/
Abendspitze). Die Einspeiser folgen seit 08.11.2026 dem echten Wetter (Einstrahlung von
Open-Meteo, alle 10 min; Nebel gedrosselt) und dem echten Sonnenstand, nachts exakt 0 W --
Standort über `MESSE_DEMO_LAT`/`MESSE_DEMO_LON` in der `.env`. Bei 0 W zeigt die Oberfläche
„aktuell keine Einspeisung“. Läuft über den kompletten echten Pfad bis zur
Live-Anzeige, kein Frontend-Fake -- dort erscheint dafür ein deutlicher Hinweis, dass die Werte
teilweise simuliert sind, solange der Schalter an ist. Details: `docs/MESSE_DEMO.md` (Kurzfassung in `CLAUDE.md`).

## Claude-Sitzungslog (Selbstdokumentation)

Jede Claude-Sitzung (Claude Code / Claude Chat / Cowork) dokumentiert am Ende Datum,
verwendetes Modell, den zusammengefassten Auftrag, einen verbesserten Prompt, das Ergebnis und
ggf. Fehler/Lehren. **Kein wörtlicher Prompt mehr** (Repo ist öffentlich, Stand 10.10.2026).
Vor jeder Recherche wird zuerst das Log durchsucht (Treffer: Datum + Kurzinhalt nennen).
Claude Code schreibt in `obsidian/Claude-Sitzungslog.md` im Repo (wird in den Vault
gespiegelt), Cowork/Chat direkt in den Vault unter `eeg-platform-notes/logs/JJJJ-MM-TT.md`.
Eine geplante Aufgabe legt täglich je Tag eine Notiz in `Claude Chats/Einträge/` an.
Details: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.

---

## Code-Struktur der Webapp (seit 16.11.2026)

`webapp/public/index.php` ist nur noch der Bootstrap (~1.400 statt ~11.900 Zeilen). Die Routen liegen in
`webapp/routes/` (public, portal, admin, api – die Reihenfolge ist die Priorität). Neuer Code kommt nach
PSR-4 in den Namensraum `App\` (Controller, Domain-Service, Repository); Vorlage ist der Bereich
Support-Tickets. Überblick: `docs/ARCHITEKTUR.md`, Entscheidungen: `docs/adr/` im Repo.

---

## Frontend-Regeln (Content-Security-Policy)

Die Plattform sendet `script-src 'self' 'nonce-…'` und `script-src-attr 'none'` (PHP: `cspPolicy()` in
`webapp/src/functions.php`; **nicht** in nginx setzen). Für neuen Code heißt das:
- **Kein** `onclick=`/`onchange=`/`onsubmit=`/… in Templates und kein `javascript:`-Link. Stattdessen die
  `data-`-Attribute aus `webapp/public/assets/js/ui-actions.js` (`data-confirm`, `data-danger-confirm`,
  `data-onclick="funktion" data-arg-0=…`, `data-dialog-open`, `data-print`, …).
- Jedes Inline-`<script>` braucht `nonce="<?= cspNonce() ?>"` (außer `application/ld+json`). Größere Skripte
  besser als Datei unter `assets/js/` ablegen.
- `tests/csp_static_test.php` (läuft im CI) schlägt bei jedem Verstoß an; `tests/browser/csp.js` prüft im Browser.
