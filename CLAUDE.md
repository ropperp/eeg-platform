# EEG-Plattform — Architektur & Deployment-Wissen für Claude

Diese Datei dokumentiert die aktuelle Infrastruktur, damit ein neuer Chat-Kontext sofort versteht wie alles zusammenhängt.

## Workflow-Präferenz

Änderungen an diesem Repo (main-Branch) immer automatisch committen und pushen, ohne vorher
nachzufragen — auch wenn eine hochgeladene Anweisungsdatei o.ä. explizit "nicht selbstständig
deployen" verlangt. Diese generelle Präferenz von Patrick hat Vorrang vor einzelnen
Task-Anweisungen, sofern nicht ausdrücklich anders gesagt.

> Ausnahme: Läuft eine Sitzung in einer Umgebung mit einer **fest vorgegebenen Arbeits-Branch**
> (z. B. Claude Code on the web mit `claude/...`-Branch), wird dort committet und gepusht, nicht
> direkt auf `main`. Der Merge nach `main` erfolgt dann per Pull Request -- **und zwar sofort**:
> PR erstellen und selbst mergen, ohne vorher nachzufragen (Patrick, 07.08.2026: "bitte immer
> gleich pushen PR und merge. IMMER"). Nicht auf eine Bestätigung warten. Auf dem Produktivserver
> wird weiterhin nur `main` deployt (Patrick pullt/baut dort selbst).

## Git-Workflow: Branches, Tags & Versionierung

Seit 0.9.0 arbeiten wir mit einer schlanken Branch-/Tag-Strategie statt nur linear auf `main`:

- **`main`** ist immer deploybar. Was auf `main` liegt, kann jederzeit per
  `git pull && docker compose up -d --build` auf den Server. Kleine, offensichtliche Änderungen
  (Doku, Bugfix) dürfen weiter direkt auf `main` (siehe Workflow-Präferenz oben).
- **Feature-Branches** (`feature/<kurzname>`, oder der von der Umgebung vorgegebene
  `claude/<...>`-Branch) für größere oder riskantere Arbeit. Dort committen, testen
  (`make test`, CI läuft automatisch), dann per Pull Request nach `main` mergen. Vorteil: `main`
  bleibt jederzeit lauffähig, Änderungen sind als Einheit reviewbar und notfalls am Stück
  zurücknehmbar.
- **Tags** (`vX.Y.Z`, [Semantic Versioning](https://semver.org)) markieren getestete Stände:
  - **PATCH** (0.9.0 → 0.9.1): Bugfix, keine neue Funktion.
  - **MINOR** (0.9.0 → 0.10.0): neue, rückwärtskompatible Funktion.
  - **MAJOR** (0.x → 1.0.0): großer Umbau bzw. der erste echte Produktivstart.
  - `0.x` = vor dem Produktivstart, `1.0.0` = erster Echtbetrieb.
  Jeder Tag hat einen Eintrag in `CHANGELOG.md`.

**Warum das nützlich ist:** Ein Tag ist ein benannter, unveränderlicher Fixpunkt. Damit lässt
sich (a) jederzeit ein bestimmter, getesteter Stand deployen oder dorthin **zurückrollen**, wenn
ein Update Probleme macht; (b) im `CHANGELOG.md` genau nachlesen, was zwischen zwei Ständen
passiert ist; (c) gegenüber der Diplomarbeit/HTL sauber dokumentieren, welcher Funktionsumfang
zu welchem Zeitpunkt fertig war. Branches wiederum halten `main` sauber und deploybar, während an
etwas Größerem gearbeitet wird.

```bash
# Neuen Feature-Branch beginnen
git switch -c feature/mein-thema
# ... committen ...
git push -u origin feature/mein-thema        # dann PR nach main

# Release taggen (nach Merge auf main, main ausgecheckt)
git tag -a v0.9.1 -m "0.9.1 – <kurzbeschreibung>"
git push origin v0.9.1

# Bestimmten getesteten Stand deployen / zurückrollen
git checkout v0.9.0 && docker compose up -d --build
```

---

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
webapp (nginx + PHP 8.2, internes Docker-Netz)
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
Derselbe `include snippets/eeg-maintenance.conf;` + `proxy_intercept_errors on;` steht auch im
`portal.stromfueralle.at`-Block weiter unten in derselben Datei.

> `client_max_body_size 20M;` muss hier gesetzt sein (Standard-Limit von nginx ist nur 1 MB) — sonst
> liefert **dieser** nginx-Proxy bei Datei-Uploads (z. B. Ausweis-Scan, Beitrittserklärung-PDF) einen
> `413 Request Entity Too Large`, obwohl `webapp/docker/nginx.conf` und `php.ini` im Repo bereits
> korrekt auf 20M stehen. Nach Änderung: `sudo nginx -t && sudo systemctl reload nginx`.

> `www.stromfueralle.at` muss als SAN im Zertifikat enthalten sein (siehe "www-Subdomain hinzufügen" unten), sonst liefert nginx für www das Default-Zertifikat aus und Browser zeigen einen SSL-Fehler.

### Eigene Wartungsseite bei Serverausfall (seit 25.09.2026)
Statt nginx' eigener hässlicher Standard-Fehlerseite bzw. eines nackten Verbindungsfehlers im
Browser zeigt der nginx-Proxy jetzt eine eigene, zum Corporate Design passende Seite
("Kurzschluss" — animierte durchhängende/reißende Stromleitung zwischen zwei Masten), sobald
`10.0.0.250` (Traefik/Pi) ein 502/503/504 liefert oder gar nicht erreichbar ist:
- `/etc/nginx/error_pages/wartung.html` — eigenständige, selbst-enthaltene HTML-Datei (kein
  externes CSS/JS/Font, läuft auch wenn sonst alles down ist), `<meta http-equiv="refresh"
  content="30">` prüft automatisch alle 30s neu. Nur auf dem nginx-Proxy-Host abgelegt, nicht im
  Git-Repo (reiner Deploy-Artefakt für einen anderen Host, wie die restliche nginx-Proxy-Config).
- `/etc/nginx/snippets/eeg-maintenance.conf` — der wiederverwendbare `error_page`/`location`-Block,
  in beide `server{}`-Blöcke (Hauptdomain + `portal`) eingebunden.
- `proxy_intercept_errors on;` ist nötig, sonst reicht nginx Traefiks eigene 502/503/504-Antwort
  roh durch statt der eigenen Seite. `proxy_connect_timeout 5s;` sorgt dafür, dass ein komplett
  unerreichbarer Pi (nicht nur ein toter Webserver dahinter) schneller erkannt wird als mit
  nginx' Standard-Timeout.
- **Bewusst NICHT auf HTTP 404 erweitert:** die Wartungsseite fängt nur 502/503/504 ab. 404 lässt
  die App selbst über ihre eigene, echte `404.php`-Seite beantworten -- ein pauschales Abfangen
  aller 404 würde die auch überdecken.
- Voraussetzung dafür, dass ein nur gestopptes/abgestürztes `webapp` (nicht der ganze Pi) überhaupt
  ein 502/503/504 statt eines nackten Traefik-404 liefert: siehe "Webapp-Router" weiter oben
  (Traefik-File-Provider statt Docker-Labels, Vorfall 25.09.2026).

---

## EEG-Server (10.0.0.250)

### Verzeichnis
```
/opt/eeg-platform/   ← Git-Repo (branch: main)
/opt/eeg/            ← Persistente Daten (DB, Redis, Mosquitto, Traefik-Certs, Webapp-Storage)
```

> `/opt/eeg/webapp-storage` (→ `/var/www/html/storage` im Container) enthält Mitglieder-Uploads,
> Beitrittserklärungen und generierte Vertrags-/Rechnungs-PDFs. Vorher lag das nur im
> Container-Dateisystem und ging bei jedem `--build` verloren — seit der Verträge/Dateien-Migration
> (14.07.2026) ist es ein echtes Volume. **Unbedingt ins Server-Backup aufnehmen.**

### Docker-Stack (`docker-compose.yml`)

| Service | Image | Ports (Host) | Zweck |
|---------|-------|-------------|-------|
| traefik | traefik:latest | 80:80 | Reverse Proxy, Docker-Labels + File-Provider |
| timescaledb | timescale/timescaledb-ha:pg16 | — | PostgreSQL + TimescaleDB |
| redis | redis:7-alpine | — | Session-Cache |
| mosquitto | eclipse-mosquitto:2 | 1883, 8883 | MQTT-Broker |
| mqtt-subscriber | (build) | — | MQTT → DB |
| webapp | (build) | — | nginx + PHP 8.2 |
| latex-service | (build) | — | PDF-Generator |

### Wichtige Traefik-Details
- Traefik hört **nur auf Port 80** (kein HTTPS, kein Let's Encrypt) — SSL macht der nginx-Proxy
- `DOCKER_API_VERSION=1.40` ist als Env-Var gesetzt (Docker Engine 29.x braucht mindestens 1.40, Traefik v3.x würde sonst 1.24 verwenden → Fehler)
- `--providers.docker.exposedbydefault=false` → nur Container mit `traefik.enable=true` werden geroutet
- Zusätzlich zum Docker-Provider läuft seit 25.09.2026 ein **File-Provider**
  (`--providers.file.filename=/etc/traefik/dynamic.yml`, Datei im Repo: `docker/traefik/dynamic.yml`)
  für die webapp-Router — Grund siehe "Webapp-Router" unten.

### Webapp-Router
Seit 25.09.2026 **nicht mehr** als Docker-Labels auf dem `webapp`-Container, sondern als
Traefik-File-Provider-Konfiguration in `docker/traefik/dynamic.yml` (ins Repo eingecheckt,
in den `traefik`-Container gemountet):
```yaml
http:
  routers:
    webapp:        { rule: "Host(`stromfueralle.at`) || Host(`www.stromfueralle.at`)", entryPoints: [web], service: webapp }
    live:          { rule: "Host(`live.stromfueralle.at`)",   entryPoints: [web], service: webapp }
    portal:        { rule: "Host(`portal.stromfueralle.at`)", entryPoints: [web], service: webapp }
    admin:         { rule: "Host(`admin.stromfueralle.at`)",  entryPoints: [web], service: webapp }
    webapp-legacy: { rule: "Host(`webapp.mechtronix.at`)",    entryPoints: [web], service: webapp }
  services:
    webapp:
      loadBalancer:
        servers: [{ url: "http://webapp:80" }]
```
**Warum die Umstellung (Vorfall 25.09.2026):** Solange die Router nur als Labels AUF dem
`webapp`-Container selbst standen, verschwanden sie zusammen mit dem Container, sobald er
gestoppt wurde (z. B. `docker compose stop webapp`, oder ein echter Absturz) — Traefik
antwortete dann kurz nach dem Stop nicht mehr mit einem aussagekräftigen 502/503/504
("Backend nicht erreichbar"), sondern mit seinem eigenen, nackten `404 page not found`
(`Content-Type: text/plain`, kein Router matcht mehr). Entdeckt beim Testen der neuen
nginx-Wartungsseite (siehe unten) — die fängt bei 502/503/504 eine eigene Seite ab, griff
bei nur gestopptem `webapp` (Traefik selbst lief noch) deshalb nie, weil kein 5xx
zurückkam (404 wird bewusst NICHT abgefangen, sonst würde die Wartungsseite auch echte
Anwendungs-404-Seiten überdecken). Mit dem File-Provider bleibt der Router jetzt bestehen,
egal ob nur `webapp` oder Traefik selbst fehlt — nur der Backend-Server wird dann
unerreichbar → korrektes 502/503/504, Wartungsseite greift zuverlässig in beiden Fällen.
`http://webapp:80` als Backend-URL funktioniert dabei ganz normal über Dockers eingebautes
DNS im gemeinsamen `eeg-net`-Netzwerk, unabhängig von Docker-Label-Discovery.
> **DOMAIN dort ist hart eingetragen** (`stromfueralle.at`) — der File-Provider kennt keine
> `${DOMAIN}`-Variablensubstitution aus der `.env` wie `docker-compose.yml` selbst. Bei
> einer Domain-Änderung `docker/traefik/dynamic.yml` von Hand mitziehen.

---

## .env auf dem Server

Datei: `/opt/eeg-platform/.env` (nicht in Git, nie committen)

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
```

> Kein `ACME_EMAIL` nötig — Traefik macht kein Let's Encrypt mehr.

---

## Neuinstallation (Fresh Deploy)

Seit dem Setup-Skript (Stand 18.07.2026) reicht ein Befehl -- `.env` (mit zufälligen Secrets),
`/opt/eeg`-Verzeichnisse (inkl. korrekter `82:82`-Rechte), Container, alle Migrationen UND der
erste Platform-Admin-Zugang (interaktiv nach E-Mail/Passwort gefragt, kein fest im Repo
eingetragener Account mehr) werden automatisch erledigt:

```bash
git clone https://github.com/ropperp/eeg-platform.git /opt/eeg-platform
cd /opt/eeg-platform
./scripts/setup.sh
```

Danach:
```bash
docker compose ps
curl -H "Host: stromfueralle.at" http://localhost/
```

Manuelle Schritt-für-Schritt-Variante (falls das Skript nicht genutzt werden soll/kann) →
`SETUP.md`. Docker-Installation (macOS/Windows/Linux) → `docs/DOCKER_INSTALL.md`.

> **Kein `docker-compose.override.yml`** auf dem Produktivserver anlegen — diese Datei deaktiviert Traefik und mappt Port 80 direkt auf webapp (nur für lokale Entwicklung).

---

## Bekannte Probleme & Lösungen

**Ausgelagert nach `docs/VORFAELLE.md`** (am 02.10.2026, die Datei war über 2000 Zeilen
angewachsen). Enthält das vollständige Vorfallstagebuch -- Symptom, gefundene Ursache, Fix --
u. a. zu: DB-Mount/PGDATA, Traefik-Routing/404, MQTT-Fernzugriff, Live-Anzeige/SkipScan/CSP,
ESP32-OTA-Update, Datei-Upload-Berechtigungen (nginx/Safari), Logo-Schattendateien,
SSL-Zertifikats-Lineages, Raspberry-Pi-Stabilität (Watchdog, Stromversorgung), EDA-Datenqualität/
Abrechnung, externer Sicherheits-Scan (OWASP-Nachtests).

**Bei einem neuen, noch unklaren Symptom IMMER zuerst dort nachsehen** -- die Pfad-/Mount-
Übersicht (`docs/INFRASTRUKTUR_PFADE.md`) bleibt der erste Blick bei DB-/Daten-„weg"-Symptomen,
sonst `docs/VORFAELLE.md` durchsuchen, bevor eine neue Diagnose von vorne beginnt.

---

## Update (laufendes System)

```bash
cd /opt/eeg-platform
git pull origin main
docker compose up -d --build
```

**Historie der einmaligen "Einmalig nach dem Update vom ..."-Anleitungen (Migrationen,
Setup-Skripte, Cron-Einträge) ausgelagert nach `docs/BETRIEBSHANDBUCH.md`** (am 02.10.2026,
die Datei war über 2000 Zeilen angewachsen). Für ein bereits laufendes, aktuelles System ist nur
der jeweils NEUESTE Eintrag dort (am Dateiende) noch relevant -- ältere sind auf Patricks
Produktivserver längst angewendet. Bei einer Neuinstallation von einem alten Tag/Backup aus,
oder um nachzuvollziehen, warum ein bestimmter Schritt nötig war: dort nachsehen.

Bei neuen DB-Migrations:
```bash
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
```

---

## Container-Healthchecks & Selbstheilung

Jeder Container hat einen `healthcheck` (in `docker-compose.yml`), damit `docker compose ps` für
alle `healthy`/`unhealthy` statt nur „Up" zeigt — inkl. `traefik` (via `--ping`) und
`mqtt-subscriber` (schreibt eine Heartbeat-Datei `/tmp/mqtt_subscriber_healthy`, solange die
MQTT-Verbindung steht).

`scripts/health_monitor.sh` ist der Wächter (läuft als Host-Cron, nicht im Container): findet er
einen Dienst `unhealthy`/gestoppt, startet er ihn **1–2× automatisch neu**; bleibt es dabei, geht
eine Alarm-Mail ans Admin-Postfach (`scripts/health_alert.php`, gleiche Microsoft-Graph-Anbindung
wie der Backup-Alarm — Empfänger = `backup_alert_email_1/2` bzw. erster Platform-Admin). Eine
Cooldown-Datei je Dienst (`/opt/eeg/health-monitor/<svc>.alerted`, Standard 6 h) verhindert
Neustart-/Mail-Fluten.

Einrichten (einmalig, auf dem Host):
```bash
# alle 5 Minuten prüfen
( crontab -l 2>/dev/null; echo "*/5 * * * * cd /opt/eeg-platform && bash scripts/health_monitor.sh >> /var/log/eeg-health.log 2>&1" ) | crontab -
```
Manuell testen: `cd /opt/eeg-platform && bash scripts/health_monitor.sh`.

---

## Obsidian-Sync

`/obsidian/Infrastruktur.md` ist ein Spiegel dieser Datei für Patricks lokalen Obsidian-Vault
(Sync-Workflow: `/obsidian/README.md`). **Bei jeder inhaltlichen Änderung an diesem `CLAUDE.md`
auch `/obsidian/Infrastruktur.md` entsprechend aktualisieren.**

> `docs/VORFAELLE.md` und `docs/BETRIEBSHANDBUCH.md` (siehe oben) sind bewusst NICHT Teil dieses
> Spiegels -- sie liegen unter `docs/`, nicht `obsidian/`, und werden vom täglichen Sync nicht
> erfasst. `/obsidian/Infrastruktur.md` übernimmt bei inhaltlichen Vorfällen/Update-Schritten
> weiterhin nur eine knappe Zusammenfassung (wie bisher schon üblich), nicht die volle Historie.

---

## Selbstdokumentation (Claude-Sitzungslog)

Patrick möchte nachvollziehen können, welches Claude-Modell wann mit welchem Auftrag
gearbeitet hat — er braucht das für die Dokumentation seiner Diplomarbeit. Deshalb schreibt
**jede** Claude-Arbeitssitzung (Claude Code, Claude Chat, Cowork) am Ende einen kurzen
Log-Eintrag, der **immer** Datum, Modell und den ursprünglichen Prompt festhält.

**Format je Eintrag** (neueste zuerst):

```markdown
## JJJJ-MM-TT HH:MM — <Werkzeug> — <Modell>
**Prompt:** <ursprünglicher Prompt/Auftrag des Nutzers, möglichst wörtlich zitiert — das ist
der Teil, den Patrick für die Diplomarbeit-Dokumentation braucht, deshalb nicht umformulieren>
**Auftrag:** <Anliegen des Nutzers, sprachlich geglättet und professionell
zusammengefasst — zusätzlich zum wörtlichen Prompt, nicht statt ihm; 1–3 Sätze>
**Ergebnis:** <was gemacht wurde: Commits, Dateien, offene Punkte; 1–3 Sätze>
```

- Werkzeug: `Claude Code` / `Claude Chat` / `Cowork`
- Modell: so genau wie bekannt, z. B. `Claude Fable 5`, `Claude Opus 4.8`

**Wohin schreiben:**
- **Claude Code** (arbeitet in diesem Repo): Eintrag oben in `obsidian/Claude-Sitzungslog.md`
  einfügen und zusammen mit den übrigen Änderungen committen/pushen. Die Datei liegt bewusst
  unter `obsidian/` und wird dadurch per täglichem Sync automatisch in Patricks
  Obsidian-Vault gespiegelt.
- **Cowork / Claude Chat** (haben Obsidian-Zugriff, committen/pushen NICHT in dieses Repo):
  Eintrag direkt in den Vault schreiben: `eeg-platform-notes/logs/JJJJ-MM-TT.md`
  (eine Datei pro Tag; existiert sie schon, Eintrag anhängen). Der Ordner `logs/` existiert
  nur im Vault und wird vom Doku-Sync nie überschrieben.
