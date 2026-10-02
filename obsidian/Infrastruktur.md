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
(die App hat eine echte eigene 404-Seite, die soll nicht überdeckt werden). Voraussetzung, dass
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
| traefik | traefik:latest | 80:80 | Reverse Proxy, Docker-Labels + File-Provider |
| timescaledb | timescale/timescaledb-ha:pg16 | — | PostgreSQL + TimescaleDB |
| redis | redis:7-alpine | — | Session-Cache |
| mosquitto | eclipse-mosquitto:2 | 1883, 8883 | MQTT-Broker |
| mqtt-subscriber | (build) | — | MQTT → DB |
| webapp | (build) | — | nginx + PHP 8.2 |
| latex-service | (build) | — | PDF-Generator |

### Wichtige Traefik-Details
- Traefik hört **nur auf Port 80** (kein HTTPS, kein Let's Encrypt) — SSL macht der nginx-Proxy
- `DOCKER_API_VERSION=1.40` gesetzt (Docker Engine 29.x braucht mindestens 1.40)
- `--providers.docker.exposedbydefault=false` → nur Container mit `traefik.enable=true` werden geroutet
- **Traefik v3-Falle:** `Host()` akzeptiert nur noch **einen** Wert pro Aufruf. Mehrere Hosts
  immer mit `Host(\`a\`) || Host(\`b\`)`, NICHT `Host(\`a\`, \`b\`)` (v2-Syntax, bricht den Router).

### Webapp-Router
Seit 25.09.2026 **nicht mehr** als Docker-Labels auf dem `webapp`-Container, sondern als
Traefik-File-Provider-Konfiguration in `docker/traefik/dynamic.yml` (im Repo, in den
`traefik`-Container gemountet):
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
**Warum (Vorfall 25.09.2026):** Solange die Router als Labels AUF dem `webapp`-Container selbst
standen, verschwanden sie zusammen mit dem Container beim Stoppen/Absturz -- Traefik antwortete
dann kurz danach mit einem nackten `404 page not found` statt einem 502/503/504, wodurch die neue
nginx-Wartungsseite (siehe oben) nie griff. Mit dem File-Provider bleibt der Router bestehen,
egal ob nur `webapp` oder Traefik selbst fehlt -- korrektes 502/503/504 in beiden Fällen.
`http://webapp:80` funktioniert über Dockers eingebautes DNS im `eeg-net`-Netzwerk, unabhängig
von Docker-Label-Discovery. DOMAIN dort ist hart eingetragen (keine `${DOMAIN}`-Substitution im
File-Provider) -- bei Domain-Änderung von Hand mitziehen.

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
```

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

## Messe-/Präsentations-Demo (MQTT-Simulator, 02.10.2026)

Für eine Messe-Vorführung ("8 Einspeiser und 12 Verbraucher [...] über mqtt") gibt es
`scripts/messe_demo_setup.php` (legt 20 fiktive, nie abrechnungsrelevante Zählpunkte an) +
`scripts/messe_demo_simulator.py` (publiziert realistisch schwankende Live-Werte im echten
Firmware-Format -- läuft über den kompletten echten Pfad bis zur Live-Anzeige, kein
Frontend-Fake). Nach der Messe `scripts/messe_demo_teardown.php` nicht vergessen, sonst
verzerren die Fantasiewerte dauerhaft die echte Live-Anzeige. Details: `CLAUDE.md`.

## Claude-Sitzungslog (Selbstdokumentation)

Jede Claude-Sitzung (Claude Code / Claude Chat / Cowork) dokumentiert am Ende Datum,
verwendetes Modell, den **ursprünglichen Prompt möglichst wörtlich** (Patrick braucht das für
die Diplomarbeit-Dokumentation) sowie zusätzlich den professionell zusammengefassten Auftrag:
Claude Code schreibt in `obsidian/Claude-Sitzungslog.md` im Repo (wird in den Vault
gespiegelt), Cowork/Chat direkt in den Vault unter `eeg-platform-notes/logs/JJJJ-MM-TT.md`.
Details: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.
