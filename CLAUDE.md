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
    location / {
        proxy_pass         http://10.0.0.250;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto https;
    }
}
server {
    listen 80;
    server_name stromfueralle.at www.stromfueralle.at;
    return 301 https://$host$request_uri;
}
```

> `client_max_body_size 20M;` muss hier gesetzt sein (Standard-Limit von nginx ist nur 1 MB) — sonst
> liefert **dieser** nginx-Proxy bei Datei-Uploads (z. B. Ausweis-Scan, Beitrittserklärung-PDF) einen
> `413 Request Entity Too Large`, obwohl `webapp/docker/nginx.conf` und `php.ini` im Repo bereits
> korrekt auf 20M stehen. Nach Änderung: `sudo nginx -t && sudo systemctl reload nginx`.

> `www.stromfueralle.at` muss als SAN im Zertifikat enthalten sein (siehe "www-Subdomain hinzufügen" unten), sonst liefert nginx für www das Default-Zertifikat aus und Browser zeigen einen SSL-Fehler.

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
| traefik | traefik:latest | 80:80 | Reverse Proxy, liest Docker-Labels |
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

### Webapp-Router-Labels
```yaml
traefik.enable=true
traefik.http.routers.webapp.rule=Host(`stromfueralle.at`) || Host(`www.stromfueralle.at`)
traefik.http.routers.webapp.entrypoints=web
traefik.http.routers.live.rule=Host(`live.stromfueralle.at`)
traefik.http.routers.portal.rule=Host(`portal.stromfueralle.at`)
traefik.http.routers.admin.rule=Host(`admin.stromfueralle.at`)
traefik.http.routers.webapp-legacy.rule=Host(`webapp.mechtronix.at`)
traefik.http.services.webapp.loadbalancer.server.port=80
```

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

> **Pfad-/Mount-Übersicht:** Welches Host-Verzeichnis in welchen Container gehängt wird, steht
> vollständig in `docs/INFRASTRUKTUR_PFADE.md` (mit Diagramm). Bei DB-/Daten-„weg"-Symptomen
> IMMER zuerst dort nachsehen.

### Datenbank wirkt plötzlich leer / „relation does not exist" nach Container-Neustart
Symptom: Login/Abrechnung brechen ab, `\dt` zeigt kaum Tabellen, obwohl vorher Daten da waren —
oft nach `docker compose up -d`/Reboot/Image-Update. **Ursache (Vorfall 23.07.2026):** Das
`timescale/timescaledb-ha`-Image legt sein Datenverzeichnis unter **`/home/postgres/pgdata/data`**
ab, **nicht** `/var/lib/postgresql/data`. Der Mount stand aber auf `/var/lib/postgresql/data` →
PostgreSQL schrieb in flüchtigen Container-Speicher, der beim nächsten Container-Neubau weg war.
Die echten Daten lagen unangetastet auf der Platte, nur nicht gemountet. **Behoben** durch
korrekten Mount (`/opt/eeg/timescaledb:/home/postgres/pgdata`) + Image-Pin auf feste Digest in
`docker-compose.yml`. Diagnose/Details: `docs/INFRASTRUKTUR_PFADE.md`. Merksatz: **nie den
`:pg16`-Tag unbewusst neu ziehen**, PGDATA prüfen mit
`docker compose exec timescaledb bash -lc 'echo $PGDATA'`.

### Traefik: "client version 1.24 is too old"
Docker Engine 29.x unterstützt nur API ≥ 1.40. Traefik:latest behebt das, zusätzlich ist `DOCKER_API_VERSION=1.40` in der compose-Datei gesetzt.

### MQTT-Broker von außerhalb des lokalen Netzes erreichen (für Mitglieder-ESP32s zuhause)
**Seit 09.08.2026 eingerichtet und funktionsfähig.** `stromfueralle.at`/`portal.stromfueralle.at`
lösen auf die öffentliche IP des **nginx-Proxy-Hosts** (10.0.0.144 / 80.122.212.226) auf -- eine
andere Maschine als der EEG-Server (10.0.0.250, Raspberry Pi 5), auf dem Mosquitto läuft.
Zufällig hat auch dieser Host dieselbe öffentliche IP (80.122.212.226) wie Patricks
Heimnetz-Fritzbox, das ist aber kein Widerspruch -- beide hängen an derselben Fritzbox/
Internetleitung, nur an unterschiedlichen internen Zielen. Für MQTT läuft die Weiterleitung
deshalb bewusst **nicht** über den nginx-Proxy (der kann ohnehin nur HTTP/HTTPS auf Port
80/443, kein rohes TCP/MQTT) und auch nicht über Traefik, sondern als eigene, direkte Kette an
beidem vorbei:

```
Internet → Fritzbox (Portfreigabe 8883 → pfSense) → pfSense (NAT-Weiterleitung 8883 → 10.0.0.250)
         → Raspberry Pi (10.0.0.250), direkt an Mosquitto
```

Beide Freigaben sind eingerichtet: Fritzbox unter Internet → Freigaben → Portfreigaben
(Gerät „pfSense", Port 8883 „MQTT TLS"), pfSense unter Firewall → NAT → Port Forward (WAN,
TCP/UDP, Ziel-Port 8883 → Umleitungsziel 10.0.0.250:8883, Beschreibung „MQTT TLS EEG Raspi").
Nur Port 8883 (TLS) ist extern freigegeben, 1883 (unverschlüsselt) bleibt intern/lokal --
erst seit Mosquitto TLS + Zugangsdaten verlangt (siehe `scripts/mqtt_secure_setup.sh`,
Abschnitt „Update" oben) ist die Weiterleitung überhaupt vertretbar.

> **Stolperstein bei der Einrichtung (09.08.2026):** Die pfSense-NAT-Regel allein reichte nicht --
> `nc`/Online-Port-Checker (z. B. yougetsignal.com „open port finder") zeigten Port 8883 von
> außen weiterhin als **geschlossen**, obwohl sowohl die Fritzbox-Portfreigabe als auch die
> pfSense-NAT-Regel korrekt eingetragen und aktiv waren. Ursache: die NAT-Regel hatte keine
> zugehörige Freigabe unter **Firewall → Rules → WAN** -- pfSense übersetzt die Zieladresse
> zwar (NAT), die Standard-Firewall (default deny) blockte das Paket aber trotzdem, weil dafür
> zusätzlich eine eigene Allow-Regel nötig ist (wird beim Anlegen einer Portweiterleitung über
> den Wizard normalerweise automatisch mit erzeugt, hier aber gefehlt). Behoben durch komplettes
> Neuanlegen der NAT-Regel (dabei automatisch samt zugehöriger WAN-Firewall-Regel erzeugt) --
> danach sofort erreichbar. **Merksatz:** Bei "NAT-Regel korrekt, Port trotzdem von außen zu"
> zuerst Firewall → Rules → WAN auf eine aktive Freigabe für den betroffenen Port prüfen.

Von außen testen (unabhängig vom eigenen Netz, das selbst ausgehende Verbindungen auf
unüblichen Ports blockieren kann): Online-Port-Checker wie
[yougetsignal.com](https://www.yougetsignal.com/tools/open-ports/) oder
[canyouseeme.org](https://canyouseeme.org) mit der aktuellen Fritzbox-WAN-IP + Port 8883.

Alle eingehenden Nachrichten live mitlesen (Debugging, lokal im Netz):
```bash
docker compose exec mosquitto mosquitto_sub -h localhost -t 'eeg/#' -v -u "$MQTT_USER" -P "$MQTT_PASSWORD"
```
Von einem Mac/PC außerhalb des lokalen Netzes (z. B. testweise vom eigenen Laptop, braucht
`brew install mosquitto` für `mosquitto_sub`):
```bash
mosquitto_sub -h stromfueralle.at -p 8883 --insecure -t 'eeg/#' -v -u eeg-device -P "$MQTT_PASSWORD"
```
`--insecure`, weil das Zertifikat selbstsigniert ist (genau wie beim ESP32 via `setInsecure()`).

### 404 von Traefik trotz laufendem webapp
Mögliche Ursachen, in dieser Reihenfolge prüfen:

**a) Ungültige Router-Regel (Traefik v3-Syntax!)** — wir laufen auf `traefik:latest` = v3.x.
In v3 akzeptiert `Host()` nur noch **einen** Wert pro Aufruf; die alte v2-Syntax
`Host(\`a\`, \`b\`)` für mehrere Domains ist ungültig und lässt den Router fehlschlagen
(genau das hat schon einmal alles auf 404 gesetzt). Für mehrere Hosts immer:
```
Host(`a`) || Host(`b`)
```
Prüfen mit:
```bash
docker logs traefik --tail 100 | grep -i error   # Rule-Parse-Fehler auftauchen lassen
docker compose config | grep "routers.*rule"     # gerenderte Labels ansehen
```

**b) `docker-compose.override.yml` vorhanden mit `traefik.enable=false`.**
```bash
ls /opt/eeg-platform/docker-compose.override.yml   # sollte nicht existieren
rm /opt/eeg-platform/docker-compose.override.yml   # falls vorhanden
docker compose up -d --force-recreate webapp
```

### Domain in Labels falsch (z.B. noch 10.0.0.250.nip.io)
```bash
grep DOMAIN /opt/eeg-platform/.env              # prüfen
sed -i 's/^DOMAIN=.*/DOMAIN=stromfueralle.at/' /opt/eeg-platform/.env
docker compose up -d --force-recreate webapp    # Labels neu setzen
```

### webapp startet nicht (Port 80 belegt)
Entweder override-Datei vorhanden (siehe oben) oder Traefik läuft nicht:
```bash
docker ps | grep traefik
docker compose up -d traefik
```

### www-Subdomain hinzufügen (z.B. www.stromfueralle.at)
Traefik-Seite (10.0.0.250, dieses Repo) ist bereits so konfiguriert, dass der
webapp-Router sowohl `stromfueralle.at` als auch `www.stromfueralle.at` matcht
(`docker compose up -d --build` nach `git pull` reicht hier).

Die SSL-Terminierung passiert aber auf dem **separaten nginx-Proxy-Host (10.0.0.144)**,
der nicht Teil dieses Repos ist. **Nicht** `sudo certbot --nginx --expand -d ... -d www...`
direkt verwenden — der nginx-Plugin-Modus schreibt dabei automatisch in die vhost-Datei und
hat in der Praxis den bestehenden `server_name`-Block zerlegt/dupliziert, wodurch parallel
mehrere Zertifikats-Lineages (`stromfueralle.at`, `stromfueralle.at-0001`,
`www.stromfueralle.at`) entstanden sind und die Hauptdomain ihr Zertifikat verlor. Stattdessen:
```bash
# 1. Zertifikat erweitern OHNE dass certbot die nginx-Config anfasst (certonly!)
sudo certbot certonly --nginx \
  --cert-name stromfueralle.at --expand \
  -d stromfueralle.at -d www.stromfueralle.at -d traefik.stromfueralle.at

# 2. vhost-Datei sichern und explizit selbst schreiben (nicht certbot überlassen)
sudo cp /etc/nginx/sites-available/70_stromfueralle.conf \
        /etc/nginx/sites-available/70_stromfueralle.conf.bak-$(date +%s)
sudo nano /etc/nginx/sites-available/70_stromfueralle.conf
#   server_name stromfueralle.at www.stromfueralle.at;   (in beiden server{}-Blöcken)
#   ssl_certificate/-_key bleiben auf .../live/stromfueralle.at/... (unverändert)

# 3. Testen, laden, verifizieren — ERST DANACH ggf. übrige Zertifikate löschen
sudo nginx -t && sudo systemctl reload nginx
sudo certbot certificates | grep -A6 "Certificate Name: stromfueralle.at$"
curl -vI https://stromfueralle.at 2>&1 | grep -i subject
curl -vI https://www.stromfueralle.at 2>&1 | grep -i subject
```
`--cert-name stromfueralle.at --expand` stellt sicher, dass genau die bestehende Lineage unter
`/etc/letsencrypt/live/stromfueralle.at/` erweitert wird (Pfad in der vhost-Config bleibt gültig)
statt eine neue `-0001`-Lineage anzulegen.

### portal-Subdomain für den Login freischalten (ausstehend, Stand 2026-07-15)
Ziel: Der "Anmelden"-Button auf der Hauptseite verlinkt jetzt auf
`https://portal.stromfueralle.at/portal/login` (App-seitig bereits umgesetzt). Traefik
(10.0.0.250, dieses Repo) hat für `portal.stromfueralle.at` schon einen Router auf dieselbe
webapp — Code-seitig ist also nichts weiter zu tun. Es fehlt aber noch, genau wie bei
`www` oben, die SSL-Terminierung auf dem **nginx-Proxy-Host (10.0.0.144)**:
```bash
# 1. Zertifikat um die portal-Subdomain erweitern (certonly, NICHT --nginx-Plugin-Modus
#    die vhost-Datei anfassen lassen -- siehe Warnung bei "www-Subdomain hinzufügen" oben)
sudo certbot certonly --nginx \
  --cert-name stromfueralle.at --expand \
  -d stromfueralle.at -d www.stromfueralle.at -d traefik.stromfueralle.at -d portal.stromfueralle.at

# 2. vhost-Datei sichern und explizit selbst um einen server{}-Block für portal erweitern
sudo cp /etc/nginx/sites-available/70_stromfueralle.conf \
        /etc/nginx/sites-available/70_stromfueralle.conf.bak-$(date +%s)
sudo nano /etc/nginx/sites-available/70_stromfueralle.conf
#   Am Dateiende die beiden folgenden server{}-Blöcke einfügen (gleiches Zertifikat wie
#   der Hauptblock, .../live/stromfueralle.at/... bleibt unverändert):
#
#   server {
#       listen 443 ssl;
#       server_name portal.stromfueralle.at;
#       ssl_certificate     /etc/letsencrypt/live/stromfueralle.at/fullchain.pem;
#       ssl_certificate_key /etc/letsencrypt/live/stromfueralle.at/privkey.pem;
#       include             /etc/letsencrypt/options-ssl-nginx.conf;
#       ssl_dhparam         /etc/letsencrypt/ssl-dhparams.pem;
#       client_max_body_size 20M;
#       location / {
#           proxy_pass         http://10.0.0.250;
#           proxy_set_header   Host              $host;
#           proxy_set_header   X-Real-IP         $remote_addr;
#           proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
#           proxy_set_header   X-Forwarded-Proto https;
#       }
#   }
#   server {
#       listen 80;
#       server_name portal.stromfueralle.at;
#       return 301 https://$host$request_uri;
#   }

# 3. Testen, laden, verifizieren
sudo nginx -t && sudo systemctl reload nginx
curl -vI https://portal.stromfueralle.at/portal/login 2>&1 | grep -i subject
```
Vorher zeigt der Anmelden-Button testweise nur relativ auf `/portal/login`, solange man sich
bereits auf `portal.stromfueralle.at` befindet (schützt vor einer Redirect-Schleife, falls die
Subdomain noch nicht erreichbar ist) — sobald DNS + SSL stehen, greift der absolute Link.

> Wichtig unabhängig von nginx: seit dem Session-Cookie-Fix (siehe "Update"-Abschnitt,
> `.stromfueralle.at`-weite Cookie-Domain) muss auch der Webapp-Container mit dem aktuellen
> Code laufen (`git pull && docker compose up -d --build`), sonst wird eine auf einer Domain
> begonnene Session auf der anderen weiterhin nicht erkannt (wirkt wie "sofort ausgeloggt"
> bzw. Admin-Bereich bleibt scheinbar auf der Hauptdomain hängen).

### Datei-/Profilbild-Upload: 500 im Browser, aber webapp-Access-Log zeigt nur 200/302
Stand 16.07.2026, reproduzierbar bei **jedem** Datei- und Profilbild-Upload, in jedem Browser
(nicht nur groß oder gelegentlich). `docker compose logs webapp` (= nginx-**Access**-Log im
Container) zeigt für den fehlschlagenden Request gar nichts oder nur unbeteiligte GETs — der
Request scheitert also, bevor er im Access-Log landet. Zwei Sackgassen auf dem Weg zur Ursache,
damit sie nicht nochmal verfolgt werden:
- `docker compose logs traefik` ist normalerweise leer, weil Traefik ohne `--accesslog=true`
  (nicht gesetzt in `docker-compose.yml`) grundsätzlich keine einzelnen Requests loggt, nur
  eigene Fehler ab Level ERROR. Kein Hinweis auf einen Traefik-Fehler.
- Fehlendes `proxy_http_version 1.1;` in der nginx-Proxy-Config auf 10.0.0.144 sah zunächst
  nach der Ursache aus (Connection-reset-Meldungen dort), war aber nicht die eigentliche
  Ursache -- dieser Fix ist trotzdem sinnvoll (verhindert HTTP/1.0-Verbindungen zum Backend)
  und bleibt gesetzt, hat das Problem hier aber nicht behoben.

**Tatsächliche Ursache**, sichtbar erst im nginx-**Fehler**-Log INNERHALB des webapp-Containers
(nicht `docker compose logs`, das ist nur der Access-Log-Teil von stdout!):
```bash
docker compose exec webapp cat /var/log/nginx/error.log
```
zeigt:
```
[crit] open() "/var/lib/nginx/tmp/client_body/0000000001" failed (13: Permission denied),
request: "POST /portal/profile/photo HTTP/1.1", host: "portal.stromfueralle.at"
```
`webapp/docker/nginx.conf` setzt `user www-data;` (passend zum PHP-FPM-User), aber das
Alpine-nginx-Paket (`apk add nginx` im Dockerfile) legt `/var/lib/nginx` SAMT `tmp/*` (u.a.
`client_body` -- Zwischenspeicher für POST-Bodies, die den kleinen In-Memory-Puffer von nginx
übersteigen) beim Install mit dem eigenen `nginx`-System-User und Modus 750 an, NICHT
`www-data`. Kleine Requests ohne Datei-Anhang (Login, Formularfelder) bleiben unter dem
Puffer-Limit und brauchen dieses Verzeichnis nie, weshalb der Bug nur bei Uploads auffällt.
nginx scheitert dabei NOCH VOR PHP-FPM und liefert sein eigenes Standard-500 aus, weshalb weder
die App-eigene Fehlerseite noch ein Log-Eintrag im Access-Log auftaucht.

> **Erster Fix-Versuch war unvollständig:** Nur `chown -R www-data:www-data /var/lib/nginx/tmp`
> zu setzen behebt das Problem NICHT zuverlässig. Linux verlangt Ausführungsrecht auf JEDES
> Verzeichnis im Pfad, nicht nur auf das Ziel -- `/var/lib/nginx` selbst (der Elternordner von
> `tmp`) blieb dabei weiterhin `nginx:nginx` mit Modus 750 (keinerlei Rechte für "andere"),
> wodurch `www-data` gar nicht erst hineinkonnte, ganz gleich wie `tmp/` selbst berechtigt war.
> Das erklärt auch das trügerische Verhalten: manche Uploads (deren Body zufällig unter dem
> nginx-Puffer bleibt und `client_body/` nie braucht) funktionierten, andere (die den Puffer
> überschreiten) scheiterten weiterhin mit demselben Permission-denied-Fehler -- unabhängig von
> der tatsächlichen Dateigröße, rein zufällig je nach komprimierter Body-Größe.

**Fix:** in `webapp/Dockerfile` direkt nach dem Storage-Chown ergänzt (bereits im Repo, ab
Commit dieser Doku-Aktualisierung) -- chownt den kompletten Elternordner, nicht nur `tmp`:
```dockerfile
RUN chown -R www-data:www-data /var/lib/nginx
```
Wirkt erst nach einem echten Image-Rebuild (Berechtigungen werden beim `docker build` gesetzt,
nicht zur Laufzeit):
```bash
cd /opt/eeg-platform
git pull origin main
docker compose up -d --build
```
Danach zur Kontrolle direkt im Container prüfen (WICHTIG: diesmal auch den Elternordner selbst,
nicht nur seinen Inhalt):
```bash
docker compose exec webapp ls -la /var/lib/nginx/
# /var/lib/nginx selbst UND tmp/ (inkl. client_body/, proxy/, fastcgi/, uwsgi/, scgi/)
# sollten jetzt alle www-data:www-data gehören
```

### SSL-Zertifikat fehlt/ungültig auf stromfueralle.at
Diagnose auf dem nginx-Proxy-Host (10.0.0.144):
```bash
sudo certbot certificates                              # Alle Lineages + SAN-Listen prüfen —
                                                         # auf Duplikate wie stromfueralle.at-0001 achten!
ls -la /etc/letsencrypt/live/stromfueralle.at/          # Dateien noch vorhanden?
sudo nginx -t                                           # Config-Syntaxfehler?
sudo journalctl -u certbot.timer --since "-2d"          # Auto-Renewal fehlgeschlagen?
sudo tail -50 /var/log/nginx/error.log
```
Häufigste Ursachen:
- **Mehrere Zertifikats-Lineages für dieselbe Domain** (z.B. durch `certbot --nginx --expand`,
  siehe oben) — die vhost-Config zeigt dann evtl. nicht mehr auf die Lineage, die tatsächlich
  alle benötigten Domains enthält, oder `certbot --nginx` hat beim Schreiben den
  `server_name`-Block der Hauptdomain verändert. Fix: siehe "www-Subdomain hinzufügen" oben
  (Konsolidierung auf eine Lineage, vhost-Datei explizit selbst schreiben, danach überzählige
  Lineages mit `sudo certbot delete --cert-name <name>` entfernen — erst nach Verifikation!).
- **Auto-Renewal fehlgeschlagen** (Rate-Limit, DNS/Port-80-Problem während Renewal) →
  `sudo certbot renew --dry-run` zum Testen, danach `sudo certbot renew`.
- **nginx wurde nach Renewal/Änderung nicht neu geladen** → `sudo systemctl reload nginx`.
Nach jeder Änderung: `sudo nginx -t && sudo systemctl reload nginx`.

### Raspberry Pi hängt sich auf (im Netz sichtbar, aber kein SSH/Terminal mehr)
Klassischer I/O-Stall (SD-Karte am Ende, RAM/Swap voll, Unterspannung oder volllaufende
Platte). Ausführliche Diagnose, Ursachen und v. a. **Selbstheilung per Hardware-Watchdog**
(Pi rebootet sich bei Einfrieren selbst, ohne dass jemand daheim sein muss):
→ `docs/RASPBERRY_STABILITAET.md`. Im Repo bereits abgesichert: `restart: always` auf allen
Containern (Autostart nach Reboot) und Docker-Log-Rotation (`x-logging` in
`docker-compose.yml`, max. 3 × 10 MB/Container), damit die Logs die Platte nicht volllaufen
lassen.

### Live-Anzeige (öffentlich, `/api/live/:slug`) zeigt keine Daten
Vorfall 24.08.2026, zwei UNABHÄNGIGE Ursachen nacheinander gefunden -- falls das Symptom wieder
auftritt, beide Diagnosewege der Reihe nach prüfen, nicht nur den ersten:

**1. Veraltete GRANTs der eingeschränkten Laufzeit-Rolle (behoben, aber war nicht die ganze
Ursache).** Die Rolle `eeg_app` (siehe `scripts/db_runtime_role_setup.sh`) hatte keine aktuellen
GRANTs mehr -- vermutlich, weil das Skript nach einer neueren Migration nicht erneut gelaufen
ist. Fix, sicher wiederholbar:
```bash
cd /opt/eeg-platform && ./scripts/db_runtime_role_setup.sh
```
Diagnose davor:
```bash
grep APP_DB_USER /opt/eeg-platform/.env               # läuft die Webapp überhaupt als eeg_app?
docker compose logs webapp --tail 100 | grep -i "permission denied\|PDOException"
docker compose exec timescaledb psql -U eeg -d eeg_platform -c "\dp esp_measurements"
```
(`docker compose ...` immer im Repo-Root `/opt/eeg-platform` ausführen, sonst "no configuration
file provided: not found".)

**2. TimescaleDB-SkipScan-Bug bei `NOT IN (SELECT ...)` auf einem Hypertable (eigentliche
Ursache, seit demselben Update behoben).** Nach Fix 1 blieb das Symptom bestehen. Das eigentliche
Log zeigte:
```
[unhandled] PDOException: SQLSTATE[XX000]: Internal error: 7 ERROR: unsupported subplan type
for SkipScan: Result in /var/www/html/src/DB.php:66
```
Ursache: `/api/live/:slug` schloss gespiegelte Demo-Zählpunkte (`mirror_source_metering_point_id`,
siehe migrate_20260906.sql) über `metering_point_id NOT IN (SELECT id FROM metering_points
WHERE ...)` aus. TimescaleDBs SkipScan-Optimierung für `DISTINCT ON` auf `esp_measurements`
(einem Hypertable) kommt mit diesem NOT-IN-Subplan nicht zurecht und wirft intern einen Fehler --
reproduzierbar bei JEDEM Aufruf dieser Route. `communityLivePower()` (dieselbe Ausschluss-Logik,
aber für die eingeloggte Dashboard-Ansicht) war davon nie betroffen, weil sie von Anfang an einen
`JOIN metering_points mp ON mp.id = ... AND mp.mirror_source_metering_point_id IS NULL` statt
eines NOT-IN-Subplans verwendet hat. **Fix:** `/api/live/:slug` auf dasselbe JOIN-Muster
umgestellt (kein Migrations-/Setup-Skript nötig, reine Code-Änderung) -- bei einem ähnlichen
Fehlerbild (SkipScan/Subplan-Fehler im Log) grundsätzlich zuerst prüfen, ob irgendwo noch eine
`NOT IN (SELECT ...)`-Variante der `mirror_source_metering_point_id`-Ausschlussregel existiert,
und auf JOIN umstellen.
**Wichtige Nebenerkenntnis:** die generische Fehlerseite zeigt den technischen Fehlertext NUR für
eingeloggte Nutzer (`Auth::check()`-Gate in `renderFatalErrorPage()`) -- ein anonymer `curl`-Test
liefert deshalb nur "Es ist ein unerwarteter Fehler aufgetreten" ohne jedes Detail. Der einzige
Weg zum tatsächlichen Fehlertext ist `docker compose logs webapp | grep -i unhandled` (aus dem
Repo-Root, siehe oben).

---

## Update (laufendes System)

```bash
cd /opt/eeg-platform
git pull origin main
docker compose up -d --build
```

> **Einmalig nach dem Update vom 14.07.2026** (Verträge/Dateien-Migration): Das neue
> Storage-Volume muss auf dem Host existieren, BEVOR `docker compose up -d --build` läuft,
> sonst legt Docker es automatisch mit root-Rechten an und PHP (www-data, UID 82 im
> Alpine-Image) kann nicht mehr in `storage/uploads` schreiben:
> ```bash
> sudo mkdir -p /opt/eeg/webapp-storage/{uploads,pdfs}
> sudo chown -R 82:82 /opt/eeg/webapp-storage
> ```

> **Einmalig nach dem Update vom 16.07.2026** (Platform-Admin-Dateiverwaltung für
> LaTeX-Vorlagen, `/admin/templates`): gleiches Muster wie oben, diesmal für
> `/opt/eeg/latex-templates` (wird sowohl von `webapp` als auch von `latex-service` gemountet):
> ```bash
> sudo mkdir -p /opt/eeg/latex-templates
> sudo chown -R 82:82 /opt/eeg/latex-templates
> ```
> `latex-service` läuft als root und darf trotz `82:82`-Eigentümer weiterhin schreiben --
> `82:82` ist nur nötig, damit `webapp` (www-data) darüber Uploads speichern kann. Bleibt das
> Verzeichnis beim ersten Start leer, kopiert `latex-service` (siehe
> `latex-service/docker/entrypoint.sh`) einmalig seine mitgelieferten Standard-Vorlagen hinein.

> **Einmalig nach dem Update vom 30.07.2026** (MQTT-Broker mit TLS + Zugangsdaten statt offen/
> anonym): Mosquitto verlangt jetzt `allow_anonymous false` + ein Zertifikat für Port 8883 --
> ohne beides startet der Container gar nicht (fehlende Dateien in `mosquitto.conf`). Einmalig:
> ```bash
> ./scripts/mqtt_secure_setup.sh
> ```
> Erzeugt ein selbstsigniertes Zertifikat unter `/opt/eeg/mosquitto/certs` (10 Jahre gültig,
> ESP32-Geräte prüfen es nicht -- `setInsecure()` --, verschlüsselt die Verbindung aber trotzdem),
> generiert `MQTT_USER`/`MQTT_PASSWORD` in `.env`, schreibt die Passwort-Datei
> (`/opt/eeg/mosquitto/passwd`) und startet `mosquitto` + `mqtt-subscriber` neu. **Wichtig:**
> Danach verliert JEDES bereits im Feld laufende ESP32-Gerät die Verbindung, bis im eigenen
> `/config`-Formular (Zahnrad-Symbol) Benutzername/Passwort nachgetragen werden (Port 8883
> empfohlen, sobald der Broker auch von außerhalb des lokalen Netzes erreichbar sein soll --
> aktuell nur im 10.0.0.0/24-Netz, siehe Abschnitt weiter unten zu externem MQTT-Zugriff).
> Bei einer echten Neuinstallation ruft `scripts/setup.sh` dieses Skript automatisch mit auf,
> nichts weiter zu tun.

> **Einmalig nach dem Update vom 25.08.2026** (automatischer EDA-Postfach-Import): der
> monatliche EDA-Energiedatenreport kann jetzt automatisch importiert werden, statt ihn von
> Hand über `/portal/eda/upload` hochzuladen -- `EdaAutoImporter.php` liest ein zentrales
> Postfach über Microsoft Graph aus, lädt die Exportdatei herunter und übergibt sie an
> `eda-parser/parser.py` (Community-Zuordnung über die Marktpartner-ID im Dateinamen,
> z. B. `RC108175_...`). Einmalig einzurichten, alles über die Platform-Admin-Oberfläche außer
> dem Cron-Eintrag:
> 1. **Shared Mailbox `eda@stromfueralle.at`** in Microsoft 365 anlegen (wie
>    `noreply@stromfueralle.at`, siehe `docs/vorlagen/Anleitung_Mailversand_Azure_GraphAPI.md`).
> 2. **Zusätzliche Anwendungsberechtigung `Mail.Read`** (Application Permission, Admin-Zustimmung
>    erteilen) für dieselbe Azure-App-Registrierung `stromfueralle-mailer` -- sie hat bereits
>    `Mail.Send` für den Mailversand, `Mail.Read` erlaubt ihr zusätzlich, JEDES Postfach im
>    Tenant zu lesen (genau wie bei `Mail.Send` deshalb bewusst ein eigenes, dediziertes
>    Postfach statt eines persönlichen).
> 3. Im EDA-Anwenderportal einen eigenen Export-User anlegen, dessen Login-E-Mail (bzw. dessen
>    Benachrichtigungsadresse) auf `eda@stromfueralle.at` zeigt -- **das eigentliche Anfordern/
>    Auslösen des Exports im Portal bleibt vorerst ein manueller Schritt** (Login + Klick auf
>    "Export"), nur das Abholen des danach gemailten Downloads passiert automatisch.
> 4. Platform-Admin → Einstellungen → Abschnitt "EDA-Automatik": Postfachadresse
>    eintragen (Feld leer = Automatik aus). Bei jeder EEG (Platform-Admin → EEG bearbeiten)
>    optional die EDA-Login-Zugangsdaten hinterlegen (nur zur zentralen Aufbewahrung,
>    verschlüsselt wie WLAN-Passwörter -- nicht für einen automatisierten Login).
> 5. Cron-Eintrag auf dem Host (einmal täglich reicht, EDA-Exporte fallen ohnehin nur monatlich an):
>    ```bash
>    ( crontab -l 2>/dev/null; echo "0 7 * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/eda_auto_import.php >> /var/log/eeg-eda-import.log 2>&1" ) | crontab -
>    ```
> Zum Testen ohne auf den Cron zu warten: Platform-Admin → Einstellungen → "Jetzt
> prüfen". Kann eine Mail nicht automatisch verarbeitet werden (z. B. Community nicht
> zuordenbar, Download schlägt fehl), bleibt sie ungelesen im Postfach und es geht eine
> Alarm-Mail an die Backup-Alarm-Adressen -- Fallback bleibt in jedem Fall der manuelle Upload
> über `/portal/eda/upload`.
>
> **EDA-Exportmail-Format verifiziert (Patrick, 13.08.2026, anhand einer echten Mail):**
> Absender `no-reply@eda.at`, Betreff `EDA Portal – Energiedatenreport RC108175` (Marktpartner-ID
> steht auch im Betreff, nicht nur im Dateinamen), kein Anhang -- stattdessen ein signierter,
> 7 Tage gültiger Download-Link im HTML-Mailtext auf
> `https://prod-api.eda-portal.at/exports/download/<uuid>?expires=...&signature=...`.
> `EdaAutoImporter.php` entsprechend angepasst: prüft den Absender (alles andere im Postfach wird
> ignoriert statt fälschlich als fehlgeschlagener Import behandelt zu werden), sucht gezielt nach
> einem Link auf diese Export-Domain statt dem ersten beliebigen `href` in der Mail, gleicht die
> Marktpartner-ID aus Dateiname UND Betreff gegeneinander ab, und erzwingt eine `.xlsx`-Endung
> beim Speichern (der Link selbst enthält nur eine UUID, keine erkennbare Dateiendung).
> **Live-Download bestätigt (13.08.2026):** beim ersten echten Auto-Import-Lauf hat die
> komplette Kette funktioniert -- Absendererkennung, Download-Link-Suche, Herunterladen OHNE
> Portal-Session, Dateibenennung, Community-Zuordnung, Parser-Start. Der Lauf endete zwar mit
> einem Fehler, aber einem inhaltlichen (siehe "Erneuter Import" unten), nicht am Download
> selbst -- die zuvor offene Frage ist damit positiv beantwortet, kein Login-Schritt nötig.
>
> **Erneuter Import für einen Zeitraum mit bereits vorhandenen Daten** ("Duplikat"): wird seit
> demselben Tag automatisch überschrieben, SOLANGE noch keine Rechnungen für den Zeitraum
> verschickt wurden (kein Abrechnungslauf mit status 'released'/'done') -- z. B. wenn zunächst
> nur L3-Datenqualität vorlag und ein späterer Export bessere Werte liefert. Ist der Zeitraum
> schon abgerechnet, bleibt es beim harten Fehler. Siehe `_billing_period_finalized()` in
> `eda-parser/parser.py`.

> **Einmalig nach dem Update vom 10.08.2026** (MQTT-Zugangsdaten in der Plattform sichtbar/
> änderbar, seit dem gleichen Tag auch per Knopfdruck automatisch angewendet): bisher lagen
> `MQTT_USER`/`MQTT_PASSWORD` ausschließlich in `.env` auf dem Server (zufälliger 24-stelliger
> Hex-String, nirgends auf der Plattform selbst einsehbar). Jetzt gibt es unter Platform-Admin →
> Einstellungen → "MQTT-Zugangsdaten" ein Formular (inkl. "einfaches Passwort
> vorschlagen"-Button) -- "Speichern & anwenden" trägt den Wunschwert in die DB
> (`platform_mqtt_config`, `pending_apply=true`) ein. Die Webapp kann Docker/Dateien auf dem Host
> nicht direkt anfassen, deshalb übernimmt ein Host-Cron-Job das eigentliche Anwenden:
> ```bash
> # einmalig einrichten, z.B. jede Minute:
> ( crontab -l 2>/dev/null; echo "* * * * * cd /opt/eeg-platform && bash scripts/mqtt_apply_pending.sh >> /var/log/eeg-mqtt-apply.log 2>&1" ) | crontab -
> ```
> `scripts/mqtt_apply_pending.sh` prüft `pending_apply`, ruft bei Bedarf
> `scripts/mqtt_secure_setup.sh --apply` auf (liest Benutzername/Passwort aus der DB, schreibt
> sie nach `.env`, erzeugt die Mosquitto-Passwort-Datei neu, startet `mosquitto` +
> `mqtt-subscriber` neu) und markiert die Änderung danach in der DB als erledigt
> (`applied_at`) -- die Plattform-Oberfläche zeigt diesen Status an. Ohne diesen Cron-Job bleibt
> eine gespeicherte Änderung als "wird in Kürze angewendet" hängen; manueller Fallback (auch
> ohne eingerichteten Cron) bleibt `./scripts/mqtt_secure_setup.sh --apply` direkt auf dem
> Server. Wie bei jeder Änderung der MQTT-Zugangsdaten: danach verliert jedes bereits im Feld
> laufende ESP32-Gerät die Verbindung, bis im eigenen `/config`-Formular das neue Passwort
> nachgetragen wird.

> **Einmalig nach dem Update vom 17.08.2026** (OWASP-Audit-Fixes -- RLS greift jetzt tatsächlich,
> TOTP-Secrets verschlüsselt, Brute-Force-Schutz, CSRF-Schutz, Security-Header,
> Passwort-Leak-Check): mehrere Punkte brauchen je ein einmaliges Setup-Skript, das nicht
> automatisch beim `git pull && docker compose up -d --build` mitläuft. **Genaue Reihenfolge,
> Begründung und Garantie "kein Datenverlust, keine Neu-Registrierung" ausführlich in
> `docs/DEPLOY_OWASP_AUDIT.md`** -- hier nur die Kurzfassung:
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260822.sql
> ./scripts/redis_secure_setup.sh
> ./scripts/db_runtime_role_setup.sh
> docker compose up -d --build
> docker compose exec -T webapp php < scripts/migrate_encrypt_totp_secrets.php
> ```
> **Reihenfolge wichtig, nicht vertauschen** (Vorfall 17.08.2026, Patrick komplett ausgesperrt):
> `redis_secure_setup.sh`/`db_runtime_role_setup.sh` MÜSSEN vor `docker compose up -d --build`
> laufen, nicht danach. Grund: das neue `docker-compose.yml` bindet
> `/opt/eeg/redis-config/redis.conf` als Datei in den redis-Container -- existiert diese Datei
> auf dem Host noch nicht, wenn `docker compose up` den redis-Container zum ersten Mal mit der
> neuen Compose-Datei startet, legt Docker für den Bind-Mount automatisch ein leeres
> **Verzeichnis** an diesem Pfad an (Standard-Docker-Verhalten, gleiches Muster wie beim
> Storage-Verzeichnis oben). Redis kann seine Konfiguration dann nicht mehr lesen ("Redis
> connection not available" im webapp-Log), jede Sitzung schlägt fehl, JEDER Login -- auch nach
> erneutem Anmeldeversuch/Browser-Daten-löschen, weil das Problem rein serverseitig ist -- landet
> auf der "Sitzung abgelaufen"-Seite. `redis_secure_setup.sh` legt die Datei selbst an, BEVOR es
> intern `docker compose up -d --force-recreate redis webapp` aufruft -- läuft es dagegen NACH
> einem bereits erfolgten `docker compose up -d --build`, ist der Pfad auf dem Host schon als
> Verzeichnis "verseucht" und das Skript kann dort keine Datei mehr schreiben.
>
> **Fix, falls das schon passiert ist:**
> ```bash
> docker compose stop redis
> sudo rm -rf /opt/eeg/redis-config/redis.conf   # der fälschlich angelegte Ordner
> ./scripts/redis_secure_setup.sh                # schreibt die Datei jetzt korrekt + startet neu
> ```
> Kein Datenverlust dabei -- nur alle gerade aktiven Sitzungen müssen sich einmal neu anmelden.
>
> Jeder einzelne Schritt läuft bis zu seiner Ausführung im bisherigen (unsicheren) Fallback
> weiter -- keine Downtime, keine Reihenfolge-Falle, siehe Tabelle in der verlinkten Doku. Bei
> einer **Neuinstallation** ruft `scripts/setup.sh` `redis_secure_setup.sh` und
> `db_runtime_role_setup.sh` automatisch mit auf (gleiches Muster wie `mqtt_secure_setup.sh`).

> **Einmalig nach dem Update vom 03.09.2026** (Push-Benachrichtigungen für die iOS-App --
> Obmann/Admin bei neuem Postfach-Element, Mitglied bei neuer Rechnung, Mitglied bei
> Einspeisung über selbst gesetzter Schwelle mit Hysterese, Patrick 19.08.2026: "ja leg mit den
> Push-Benachrichtigungen los"): Datenbank-Trigger füllen `push_notifications_queue`
> (`database/migrate_20260903.sql`), `Push.php` leert sie über Apples APNs (HTTP/2 + ES256-JWT,
> siehe Klassendoc). Braucht zusätzlich das PHP-`curl`-Modul (jetzt im `webapp`-Image, da
> PHPs eingebauter `http://`-Wrapper kein HTTP/2 kann) -- kommt automatisch mit dem nächsten
> `docker compose up -d --build`, kein Extra-Schritt.
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260903.sql
> docker compose up -d --build
> ( crontab -l 2>/dev/null; echo "* * * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/send_pending_push.php >> /var/log/eeg-push.log 2>&1" ) | crontab -
> ```
> **Ohne Apples echte Zugangsdaten bleibt die Warteschlange liegen, sonst passiert nichts
> Schlimmes** -- `Push::sendPending()` prüft `platform_apns_config` zuerst und rührt die Queue
> gar nicht an, wenn dort noch nichts hinterlegt ist (kein Fehlerspam, einfach nichts zu tun).
> Patrick muss dafür einmalig in seinem Apple-Developer-Account einen APNs-Auth-Key (.p8)
> erzeugen (Team-ID, Key-ID, Bundle-ID der iOS-App, Inhalt der .p8-Datei) und über
> Platform-Admin → Einstellungen → "Push-Benachrichtigungen" (bzw. direkt
> `POST /api/v1/admin/settings/apns`) hinterlegen -- sobald das steht, greift der nächste
> Cron-Lauf automatisch, kein Neustart nötig. Test ohne auf eine echte Auslösung zu warten:
> `POST /api/v1/admin/settings/apns/test` (erfordert vorher ein über
> `POST /api/v1/push/register` registriertes eigenes Gerät).

> **Einmalig nach dem Update vom 04.09.2026** (Viertelstunden-Verbrauchsdiagramm für Mitglieder
> -- Patrick, 03.09.2026: "wie viel sie viertelstündlich verbrauchen und wie viel davon
> energiegemeinschaftlich genutzt wird"): nur die Migration nötig, sonst nichts (kein neues
> Python-Paket, `openpyxl`/`pandas` sind schon da):
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260904.sql
> docker compose up -d --build
> ```
> Datenquelle ist ein **zweiter, eigener EDA-Export-Typ** ("Energiedaten"-Sheet, echte
> Viertelstundenwerte) neben dem bisherigen monatlichen Energiedatenreport -- beide werden im
> EDA-Anwenderportal separat exportiert, hat mit der Abrechnung nichts zu tun (eigene Tabelle
> `eda_interval_data`, siehe Kommentar in der Migration). Unter Platform-Admin bzw.
> Obmann-Bereich → "EDA-Daten importieren" gibt es dafür jetzt eine zweite Upload-Karte inkl.
> Anzeige "Daten vorhanden bis ..., es fehlen X Tage" -- da EDA maximal einen Monat pro Export
> erlaubt, aber auch kürzere/überlappende Zeiträume liefert, einfach alle paar Tage den
> aktuellen Ausschnitt hochladen (ein überschneidender Zeitraum wird automatisch überschrieben,
> nicht wie beim Monatsimport als Duplikat abgelehnt). Mitglieder sehen das Diagramm unter
> "Mein Verbrauch" im Portal bzw. in der App (`GET /api/v1/consumption/interval`).

> **Einmalig nach dem Update vom 05.09.2026** (Demo-Login für Präsentation/Diplomarbeit-Review --
> Patrick, 05.09.2026: "ich möchte schon bitte gerne einen einzigen Login haben ... es sollen
> bitte schon für einen Login alle 4 Rollen sein"): EIN Login, umschaltbar zwischen
> Plattform-Admin, Obmann und ZWEI unabhängig wählbaren, komplett fiktiven Mitglied-Identitäten
> ("Verbraucher 1"/"Einspeiser 1") in derselben EEG -- dafür musste `user_roles` erstmals mehr
> als eine 'member'-Zeile je (community_id, user_id) erlauben (neue Spalte `member_id`, siehe
> Kommentar in der Migration). Der Login ist über `users.is_demo` PLATTFORMWEIT UND
> ROLLENÜBERGREIFEND schreibgeschützt (jeder POST wird zentral in `Router.php` bzw.
> `AppApiAuth::requireAppAuth()` abgelehnt, außer dem Rollenwechsel selbst) -- unabhängig davon,
> welche Rollen ihm zugewiesen sind, kann er nirgends etwas verändern. `members.is_demo`
> schließt die beiden fiktiven Mitglied-Identitäten zusätzlich explizit von echten
> Abrechnungsläufen (`Billing.php`) und der Mitgliederstatistik im Obmann-Dashboard aus.
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260905.sql
> docker compose up -d --build
> ./scripts/create_demo_login.sh        # fragt E-Mail + Passwort interaktiv ab, KEINE Rollen
> docker compose exec -T webapp php < scripts/create_demo_members.php   # legt "Verbraucher 1"/
>                                                                        # "Einspeiser 1" an
> ```
> `create_demo_members.php` sucht die echten Mitglieder Stefanie Schwaiger und Daniel Ropper,
> kopiert deren aktive Zählpunkte samt kompletter EDA-Messreihen (`eda_measurements`,
> `eda_interval_data`) auf zwei fiktive Mitglied-Datensätze in derselben EEG -- gleiche
> Verbrauchszahlen fürs Diagramm, aber neuer Name, neue (mit "DEMO-" statt "AT" beginnende,
> garantiert nie mit einem echten EDA-Import kollidierende) Zählpunktnummer, keine echte
> Adresse/Telefonnummer/Geburtsdatum (alles frei erfunden, nicht von den echten
> Vorlage-Mitgliedern abgeleitet -- Patrick, 05.09.2026: "damit was personenbezogen sein kann,
> unkennbar oder unlesbar ist"). Danach im Platform-Admin-Backoffice ("Benutzer verwalten") den
> neu angelegten Demo-Login öffnen und unter "Rolle hinzufügen" alle vier Rollen zuweisen (bei
> `member` jeweils die passende Mitglied-Identität im neuen Feld "Mitglied-Identität" wählen).
> `create_demo_login.sh` legt den Login nur einmalig an (E-Mail bereits vergeben -> Passwort wird
> aktualisiert, Rollen bleiben unangetastet).
>
> **`create_demo_members.php` ist ein SYNC, kein Einmal-Skript** (Patrick, 05.09.2026: "Die
> Daten sollen immer gleich sein mit den aktuell gültigen Daten"): der Mitglied-Datensatz selbst
> (Name/Adresse/Kundennummer/member_id) wird nur beim allerersten Lauf angelegt und danach
> unverändert wiederverwendet (sonst würden Rollenzuweisungen im Admin-Backoffice, die auf die
> member_id zeigen, bei jedem Lauf ungültig). Die Zählpunkte + ALLE Messdaten
> (`eda_measurements`, `eda_interval_data`) werden dagegen bei JEDEM Lauf komplett gelöscht und
> frisch aus dem aktuellen Stand des jeweiligen Vorlage-Mitglieds neu kopiert -- damit "Verbraucher
> 1"/"Einspeiser 1" nach jedem neuen EDA-Import automatisch aktuell bleiben. Damit das ohne
> manuelles Nachtriggern gilt (unabhängig davon, ob die Vorlage-Daten per Auto-Import oder
> manuellem Upload aktualisiert wurden), als täglichen Cron-Job einrichten:
> ```bash
> ( crontab -l 2>/dev/null; echo "30 7 * * * cd /opt/eeg-platform && docker compose exec -T webapp php < scripts/create_demo_members.php >> /var/log/eeg-demo-sync.log 2>&1" ) | crontab -
> ```
> (bewusst 7:30 Uhr, kurz NACH dem täglichen EDA-Auto-Import-Cron um 7:00 Uhr, siehe oben --
> damit ein frisch importierter Tag noch am selben Morgen in die Demo-Daten übernommen wird).
>
> **Wichtig -- "richtiger DEMO-Acc" (Patrick, 05.09.2026):** in ALLEN vier Rollen sind
> ausnahmslos alle Funktionen, Felder und Buttons sichtbar, nichts ist ausgeblendet -- die
> Read-only-Sperre (`Auth::isDemo()`) greift ausschließlich beim tatsächlichen Absenden eines
> Formulars (POST) und zeigt dann eine freundliche Hinweisseite statt eines rohen Fehlers, sonst
> verhält sich die Oberfläche wie bei jedem echten Account. `create_demo_members.php` befüllt
> seither auch Kundennummer, IBAN/BIC/Kontoinhaber (klar erkennbare Platzhatzer-IBAN --
> unbedenklich, da `is_demo`-Mitglieder nie eine `invoices`-Zeile bekommen), Stromlieferant und
> alle Beitritts-Zustimmungen, damit Mitglied-Detailseiten vollständig statt leer wirken.
> Bewusst NICHT vorbelegt: der Vertragsstatus (`contract_bezug_status`/
> `contract_einspeisung_status` bleiben `'none'`) -- ein "signierter" Vertrag ohne echt erzeugte
> PDF-Datei würde beim Ansehen nur einen kaputten Download-Link zeigen. Wer für die Präsentation
> auch einen fertig signierten Beispielvertrag zeigen will: einmalig über den EIGENEN echten
> Obmann-Account (nicht den Demo-Login, der ist read-only) für "Verbraucher 1"/"Einspeiser 1"
> einen Vertrag erzeugen/signieren -- der Demo-Login kann ihn danach ganz normal ansehen.

> **Einmalig nach dem Update vom 06.09.2026** (Live-ESP-Spiegelung für die Demo-Mitglieder --
> Patrick, 05./06.09.2026: "du sollst bitte die Echtzeit-Werte zum Einspeisen von Daniel Ropper
> synchronisieren und die Echtzeit-Daten von Stefanie Schwaiger für den Verbraucher verwenden.
> Aber bitte in Echtzeit."): "Verbraucher 1"/"Einspeiser 1" haben keine eigene ESP32-Hardware --
> statt einer synthetischen Simulation (bewusst abgelehnt, siehe Konversation) spiegelt ein
> DB-Trigger auf `esp_measurements` jetzt JEDE neue Live-Messung des jeweiligen echten
> Vorlage-Zählpunkts sofort (kein Polling, keine Verzögerung) auch auf den zugehörigen
> Demo-Zählpunkt -- echte Live-Daten, nur unter fiktiver Identität. `mqtt-subscriber` schreibt
> ca. alle 5s eine neue Zeile (siehe `migrate_20260903.sql`), die Demo-Kachel "Aktuelle Leistung"
> bewegt sich dadurch im selben Takt wie beim echten Vorlage-Mitglied. Der Trigger zieht dabei
> auch `esp_online`/`esp_last_seen_at`/`meter_reachable` am Demo-Zählpunkt mit, wodurch er auch
> in der "ESP online: X von Y"-Zählung normal mitzählt (bisher blieb er dort unsichtbar, weil
> `esp_last_seen_at` nie gesetzt wurde -- kein Fehler, aber jetzt eben ein "online" wirkender
> Zählpunkt statt einem, der wie "noch nie installiert" aussieht).
> ```bash
> cd /opt/eeg-platform
> git pull origin main
> docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260906.sql
> docker compose exec -T webapp php < scripts/create_demo_members.php
> ```
> Kein `docker compose up -d --build` nötig (reine DB-Änderung, kein Code in `webapp`/
> `mqtt-subscriber` geändert). Der zweite Befehl trägt bei den beiden Demo-Zählpunkten
> `mirror_source_metering_point_id` auf den jeweiligen echten Vorlage-Zählpunkt ein -- ohne das
> hätte der neue Trigger nichts zu spiegeln. Ab dann läuft die Spiegelung von selbst weiter,
> unabhängig vom täglichen Sync-Cron oben (der ist nur für die EDA-/Abrechnungsdaten nötig).

> **Stolperstein bei der Rollenzuweisung des Demo-Logins (Patrick, 06.09.2026, per Screenshot):**
> im Platform-Admin-Backoffice (`/admin/users/:id` -> "Rolle hinzufügen") erscheint das Feld
> "Mitglied-Identität" erst, NACHDEM in der Rolle-Auswahl "member" ausgewählt wurde -- leicht zu
> übersehen. Wird eine `member`-Rolle OHNE dieses Feld gespeichert, landet sie in `user_roles`
> mit `member_id = NULL` und führt für den Demo-Login ins Leere (kein `members`-Datensatz mit
> `user_id` = Demo-Login, siehe migrate_20260905.sql) -- "Aktuelle Rollen" zeigt dann `member`
> mit Mitglied "--". Das Formular hat seit diesem Update einen Hinweistext dazu bekommen.
> Bereits falsch angelegte Rollen reparieren (räumt eine `member`-Rolle ohne Mitglied-Identität
> auf und trägt stattdessen "Verbraucher 1"/"Einspeiser 1" korrekt ein, sicher erneut ausführbar):
> ```bash
> docker compose exec -T webapp php < scripts/assign_demo_member_roles.php
> ```
> Danach beim Demo-Login einmal neu anmelden (bzw. neu laden, falls gerade eingeloggt), damit die
> Session die neuen Rollen sieht.

> **PII-Maskierung für Obmann/Admin im Demo-Login (Patrick, 06.09.2026: "wie sieht es mit dem
> read only mit ***-verpixelten/unkennbar gemachten Daten bei Obmann und Admin-Acc aus? [...]
> ich möchte das die verwaltung als obmann und admin auch herzeigen können"):** reine
> Code-Änderung, keine Migration/kein Skript nötig -- mit dem nächsten `git pull && docker
> compose up -d --build` aktiv. `demoMask*()` in `functions.php` maskiert personenbezogene
> Felder ECHTER Mitglieder/Logins (NIE die beiden fiktiven Demo-Mitglieder selbst), sobald
> `Auth::isDemo()` aktiv ist -- Vorname: erste 4 Buchstaben + Punkte, Nachname/E-Mail/Adresse/
> IBAN/Zählpunktnummer: komplett unkenntlich, Telefonnummer: nur die letzten 4 Stellen sichtbar,
> Geburtsdatum: komplett maskiert, Profilbild: Default-Avatar statt echtem Foto. Eingebaut in die
> Kernseiten der "Verwaltung": Obmann-Mitgliederliste (`/portal/members`) + Mitglied-Detailseite
> (`/portal/members/:id`), Platform-Admin-Nutzerliste (`/admin`) + Nutzerdetailseite
> (`/admin/users/:id`, inkl. Mitglied-Identität-Auswahlfeld) + EEG-Mitgliederliste
> (`/admin/communities/:id`). **Noch NICHT abgedeckt** (bewusst zurückgestellt, siehe
> Konversation): Aktivitätslog (Freitext kann Namen enthalten), Beitrittsanträge, Postfach,
> Support-Tickets, Rechnungsliste, sowie die Mitglied-BEARBEITEN-Formulare (zeigen echte Werte
> in Eingabefeldern, auch wenn Speichern ohnehin gesperrt ist) -- diese Seiten beim Vorführen des
> Demo-Accounts vorerst meiden, bis sie ebenfalls maskiert sind.

> **Stolperstein Pre-Launch-Popup (Patrick, 06.09.2026, per Screenshot):** ein Demo-Login saß
> beim allerersten Aufruf der Mitglied-Ansicht ("Verbraucher 1"/"Einspeiser 1") hinter dem
> Pre-Launch-Hinweis-Popup ("Willkommen! Ein kurzer Hinweis...") fest -- der "Gelesen"-Button
> dahinter ist ein POST (`/portal/ack-prelaunch`) und wurde von der Read-only-Sperre blockiert,
> landete auf der "Nur Lesezugriff"-Seite statt das Popup zu schließen; da der dahinterliegende
> Seiteninhalt bewusst per `pointer-events:none` gesperrt ist, kam man so gar nicht mehr weiter.
> Behoben: das Popup wird für Demo-Logins jetzt grundsätzlich gar nicht mehr angezeigt (der
> Hinweistext richtet sich an echte, neue Mitglieder und ist für eine Präsentation irrelevant),
> zusätzlich steht `/portal/ack-prelaunch` als zweite, folgenlose Ausnahme neben
> `/portal/switch-role` auf der Demo-Erlaubnisliste in `Router.php` (falls es je doch auftaucht).
> Reine Code-Änderung, kein Migrations-/Setup-Skript nötig -- mit dem nächsten `git pull &&
> docker compose up -d --build` aktiv.

> **Drei Nachbesserungen vom 06.09.2026:**
>
> **1. Energiefluss doppelt gezählt (Patrick: "es dürfen die Daten nicht doppelt in dem
> Energiefluss angezeigt werden"):** die Live-ESP-Spiegelung vom selben Tag (siehe oben) hat
> einen Community-weiten Zähl-Bug ausgelöst -- `communityLivePower()` (Obmann-/Mitglied-Dashboard,
> `/portal/api/live-power`, `/api/v1/live`) UND die öffentliche `/api/live/:slug` (Grundlage von
> `live.stromfueralle.at`, für JEDEN Besucher sichtbar!) summierten Leistung/Energie über ALLE
> Zählpunkte der Community, ohne gespiegelte Demo-Zählpunkte auszuschließen -- die echte Messung
> UND ihre Spiegelung zählten doppelt. Behoben durch `mirror_source_metering_point_id IS NULL`
> in allen betroffenen Summen/Zählungen. Live an einer Scratch-DB verifiziert (500 W echt blieb
> 500 W in der Summe, nicht 1000 W). Reine Code-Änderung.
>
> **2. platform_admin/manager im Demo-Login fehlten trotz manueller Zuweisung:** `scripts/
> assign_demo_member_roles.php` (siehe oben) legt jetzt zusätzlich platform_admin + manager
> selbst an, falls sie fehlen sollten (statt sich nur auf die manuelle Zuweisung über die
> Admin-Oberfläche zu verlassen), UND gibt am Ende den tatsächlichen Rollenstand aus der DB aus:
> ```bash
> docker compose exec -T webapp php < scripts/assign_demo_member_roles.php
> ```
> Sicher erneut ausführbar (prüft vor jedem Insert per SELECT, legt nie eine zweite/doppelte
> Rolle an). Bei Unklarheit über den tatsächlichen Rollenstand: die Ausgabe dieses Skripts ist
> die verlässliche Quelle, nicht die Vermutung über die Admin-Oberfläche.
>
> **3. Einspeiser hatten kein Verbrauchs-Äquivalent-Diagramm (Patrick: "warum haben die
> Einspeiser nicht die Möglichkeit, ihre eingespeiste Leistung in einem Diagramm einzusehen?"):**
> neue, spiegelbildliche Seite `/portal/my/einspeisung` (bzw. `GET
> /api/v1/production/interval` für die App) für Mitglieder mit Einspeise-/Prosumer-Zählpunkten --
> nutzt dieselbe `eda_interval_data`-Tabelle, aber `energy_direction='GENERATION'`. Card dafür
> auf dem Mitglied-Dashboard, analog zur bestehenden Verbrauchs-Karte. Reine Code-Änderung.
>
> Alle drei Punkte reine Code-Änderungen, kein Migrations-/Setup-Skript nötig außer Punkt 2 (das
> bereits bekannte Rollen-Skript) -- mit dem nächsten `git pull && docker compose up -d --build`
> aktiv.

> **Weitere Nachbesserungen vom 06.09.2026, nach dem ersten echten Login-Versuch als Demo-Admin:**
>
> **1. Absturz beim Öffnen von /portal/dashboard als Demo-Admin** ("DB::setCommunity():
> Argument #1 ($communityId) must be of type string, null given"): `scripts/
> assign_demo_member_roles.php` hatte platform_admin mit `community_id=NULL` angelegt (rein
> funktional korrekt, `Auth::isPlatformAdmin()` braucht keine Community) -- `/portal/dashboard`
> leitet aber JEDEN mit `Auth::isManager()` (das gilt auch für platform_admin) auf
> `manager_dashboard.php` weiter, das zwingend eine aktive Community braucht und sonst abstürzt.
> Doppelt behoben: `/portal/dashboard` weicht jetzt auf `/admin` aus, wenn keine Community aktiv
> ist (schützt auch echte platform_admin-Accounts vor demselben Absturz), UND das Rollen-Skript
> setzt für neu angelegte/reparierte platform_admin-Rollen dieselbe Community wie die
> Mitglied-Identitäten (genau wie beim manuellen Anlegen über die Admin-Oberfläche). Ein
> bestehender kaputter Zustand wird beim nächsten Lauf automatisch repariert:
> ```bash
> docker compose exec -T webapp php < scripts/assign_demo_member_roles.php
> ```
>
> **2. "ESP online: 3 von 4" statt korrekt "X von 2"** (Patrick, per Screenshot, obwohl nur 2
> echte ESPs existieren): eine ZWEITE, von `communityLivePower()` unabhängige Zähl-Stelle in
> `manager_dashboard.php` (Status-Kachel "ESP online" + "Registrierte Zählpunkte") hatte
> denselben, beim ersten Fix übersehenen Doppelzählungs-Bug -- gespiegelte Demo-Zählpunkte
> (`mirror_source_metering_point_id`) wurden auch hier mitgezählt. Ergänzt um dieselbe
> `mirror_source_metering_point_id IS NULL`-Bedingung. Reine Code-Änderung.
>
> **3. Echte Zugangsdaten im Klartext für Demo-Admin sichtbar** (Patrick: "die ganzen
> E-Mail-Einstellungen, Sachen wie die Graph API von Microsoft [...] verpixelt oder mit
> Sternchen"): die Read-only-Sperre verhindert zwar jede Änderung, aber NICHT das bloße Ansehen
> -- drei Stellen zeigten echte, entschlüsselte Zugangsdaten im Klartext-Formularfeld:
> MQTT-Passwort + Geräte-Fernkonfigurationspasswort (`/admin/mail-settings`), EDA-Portal-Passwort
> je EEG (`/admin/communities/:id`), und das Heim-WLAN-Passwort eines Mitglieds (Endpunkt
> `/portal/members/:id/metering-points/:mpid/wifi-info`, per GET abrufbar -- von der POST-only
> Sperre nicht erfasst). Das Microsoft-Graph-Client-Secret selbst war bereits vorher sicher (nie
> im Klartext, nur ein Passwort-Feld mit Platzhalter) -- Tenant-/Client-ID zusätzlich maskiert,
> obwohl technisch keine Geheimnisse (Azure-Identifikatoren, kein Client-Secret), auf Patricks
> ausdrücklichen Wunsch. Alle vier jetzt für Demo-Logins maskiert (`demoMaskFull()`), beim
> WLAN-Endpunkt zusätzlich geprüft, ob das betroffene Mitglied ECHT ist (fiktive Demo-Mitglieder
> selbst bleiben unmaskiert, wie überall sonst auch). Reine Code-Änderung.

> **Einmalig nach dem Update vom 06.09.2026** (Datei-Downloads für den Demo-Account komplett
> gesperrt + weitere PII-Lücken geschlossen -- Patrick, 06.09.2026: "Die Dateien dürfen nie, in
> gar keinem Fall, irgendwie installiert oder heruntergeladen werden können. Ich würde da voll
> gegen das Datenschutzrecht verstoßen."): reine Code-Änderung, kein Migrations-/Setup-Skript
> nötig -- mit dem nächsten `git pull && docker compose up -d --build` aktiv.
>
> **1. Datei-Downloads:** die bisherige Read-only-Sperre (`Router.php`/`AppApiAuth.php`) blockt
> nur POST -- alle Datei-/PDF-Download-Routen sind aber GET und liefen deshalb weiterhin durch
> (gleiches Lückenmuster wie schon beim WLAN-/MQTT-/EDA-Passwort). Neue zentrale Helper
> `denyDemoFileDownload()` (Web, zeigt die "Nur Lesezugriff"-Seite) bzw.
> `denyDemoApiFileDownload($ctx)` (App-API, JSON-403) in `index.php`, jeweils ganz am Anfang der
> Route aufgerufen -- bei den Vertrags-PDF-Routen (`/portal/members/:id/contract/bezug` u.ä.)
> verhindert der frühe `return` dabei auch gleich einen Status-Update-Nebeneffekt, den das bloße
> Ansehen sonst auslöst. Betrifft ausnahmslos JEDE Datei, egal ob sie technisch zu einem echten
> oder einem fiktiven Demo-Mitglied gehört: Mitglieder-Uploads (`/portal/files/...`,
> `/portal/my/documents/...`), Beitrittserklärungen (`/portal/applications/:id/formular`),
> Bezugs-/Einspeisevereinbarungen, Rechnungen (inkl. `/portal/billing/preview`-Vorlage), die
> SEPA-Sammellastschrift-XML eines Abrechnungslaufs, LaTeX-Vorlagen/Logos
> (`/admin/templates/:name/download`, `/portal/settings/logo/preview`), Profilbilder/Avatare und
> der DSGVO-Selbstauskunft-Export -- jeweils jedes GET-Pendant im Web-Portal UND in der App-API.
> Bloßes Browsen/Hineinklicken in Datei-LISTEN bleibt erlaubt ("Hineinklicken wäre nämlich schon
> cool zu können") -- nur der eigentliche Dateitransfer wird geblockt.
>
> **2. `/portal/files` + `/portal/files/:id`:** die Mitgliederliste dieser Seite hatte eine
> eigene, bisher ungemaskte Abfrage (Screenshot-bestätigt: Namen/E-Mails vollständig im Klartext
> sichtbar) -- jetzt wie überall sonst über `demoMaskMembers()`/`demoMaskMember()` maskiert.
>
> **3. Postfach:** Name in "Neue Beitrittserklärung: ..."-Meldungen sowie die Zählernummer in
> "Unbekannte Zählernummer gemeldet"-Meldungen (noch keinem Mitglied zugeordnetes ESP) sind hier
> freier Fließtext statt eigener Spalten (siehe `notify_unknown_meter()` in
> `mqtt-subscriber/main.py`) -- neue Funktion `demoMaskNotification()` ersetzt gezielt das
> bekannte Textmuster je Benachrichtigungstyp.
>
> **4. Support-Tickets:** Namen echter Mitglieder in Ticketliste (`/portal/support`) und
> -detail (`/portal/support/:id`) maskiert (`demoMaskMembers()`/`demoMaskMember()`, dafür `m.is_demo`
> in beide Abfragen mit aufgenommen) -- eigene Tickets der beiden fiktiven Demo-Mitglieder
> (Verbraucher 1/Einspeiser 1) bleiben unmaskiert und lassen sich weiterhin ganz normal anlegen.
>
> **5. Obmann-Einstellungen (`/portal/settings`, gleiche Felder auch auf
> `/admin/communities/:id`):** ZVR-Nummer und EEG-Name bleiben bewusst sichtbar (Vereins-
> Stammdaten, keine PII). Neue Funktionen `demoMaskCommunitySettings()` (Kontakt-E-Mail/
> Kontoinhaber komplett unkenntlich, Gläubiger-ID/Marktpartner-ID nur die ersten paar Zeichen --
> Patrick nannte Letzteres "PIC"; mangels eines Felds mit diesem Namen auf `marktpartner_id`
> gemappt, ggf. korrigieren falls etwas anderes gemeint war), `demoMaskSettingsUser()` (Name des
> eingeloggten Obmann-Kontos im Unterschrift-Bereich, nur 3 Anfangsbuchstaben statt der sonst
> üblichen 4) und `demoMaskTaxConfig()` (UID-Nummer, nur die ersten 3 Zeichen).
>
> Alle neuen `demoMask*`-Funktionen unit-getestet (`tests/functions_test.php`) und zusätzlich
> gegen eine Scratch-DB mit echten und fiktiven Mitgliedern/Tickets/Postfach-Meldungen/
> EEG-Stammdaten live verifiziert (u.a. `Stefanie Schwaiger` -> `Stef•••• •••••••••`, `Verbraucher
> 1` bleibt unmaskiert, ZVR-Nummer bleibt sichtbar).

> **Einmalig nach dem Update vom 06.09.2026** (Aktivitätslog + Beitrittsanträge maskiert, WLAN-Info
> ohne Klick sichtbar -- reine Code-Änderung, kein Migrations-/Setup-Skript nötig):
>
> **1. Aktivitätslog (`/admin/log`, `/admin/log/export`, `/api/v1/admin/log`):** die/der
> Handelnde (aus `users`) wird wie überall über `demoMaskUser()` maskiert. `beschreibung` ist
> dagegen freier Fließtext aus über 50 verschiedenen `logAudit()`-Aufrufstellen im ganzen Code
> (Mitgliedernamen, E-Mails, IBANs, ...) -- ein gezielter Textbaustein-Ersatz je Aufrufer wie bei
> `demoMaskNotification()` wäre hier nicht robust pflegbar, deshalb neue Funktion
> `demoMaskAuditLog()`: `beschreibung` wird für den Demo-Zugang komplett durch "Details
> ausgeblendet (Demo-Zugang)." ersetzt, Aktion/Objekttyp/EEG/Zeitpunkt bleiben sichtbar. Der
> Markdown-Export (`/admin/log/export`) fällt zusätzlich unter die generelle
> Datei-Download-Sperre (`denyDemoFileDownload()`, siehe oben).
>
> **2. Beitrittsanträge (`/portal/applications`, `/portal/applications/:id`):** eigene Tabelle
> `membership_applications` mit eigenen Spaltennamen (`iban`/`bic` statt `member_iban`/
> `member_bic`, `bezug_zaehlpunkt` statt `znr_bezug`, ...), deshalb neue Funktion
> `demoMaskApplication()` statt `demoMaskMember()`. Unterschriftsbilder (Beitritt + SEPA-Mandat)
> werden komplett ausgeblendet statt maskiert. Das PDF-Formular selbst
> (`/portal/applications/:id/formular`) war bereits über die Datei-Download-Sperre vom letzten
> Update abgedeckt.
>
> **3. WLAN-Info ohne Klick sichtbar** (Patrick, 23.08.2026, per Screenshot: "nicht darunter den
> kleinen Schriftzug 'WLAN-Info anzeigen'"): auf der Mitglied-Detailseite (`/portal/members/:id`)
> zeigte ein Klick auf "WLAN-Info anzeigen" bisher SSID/IP/WLAN-Passwort in einem `alert()`-Popup.
> Jetzt lädt `member_detail.php` diese Info für jeden Zählpunkt mit Zähler automatisch beim
> Öffnen der Seite per AJAX nach und zeigt sie direkt in der Tabelle an -- kein Klick, kein
> Popup mehr nötig. Die bestehende Sicherheitsvorkehrung bleibt dabei erhalten: das
> WLAN-Passwort landet weiterhin NICHT im initial vom Server gerenderten HTML, sondern kommt
> weiterhin über den separaten, authentifizierten Endpunkt
> `/portal/members/:id/metering-points/:mpid/wifi-info` -- nur eben automatisch statt erst nach
> einem Klick. Die dortige Demo-Maskierung (echte Mitglieder maskiert, fiktive Demo-Mitglieder
> unmaskiert, siehe Update vom 06.09.2026 weiter oben) ist davon unberührt und greift unverändert.

> **Einmalig nach dem Update vom 06.09.2026** (WLAN-Info-Popup zurückgebaut + für Demo-Zugang
> komplett ausgeblendet, Rechnungsliste maskiert -- reine Code-Änderung, kein Migrations-/
> Setup-Skript nötig):
>
> **1. WLAN-Info wieder Popup, für Demo-Zugang aber komplett unsichtbar** (Patrick, 23.08.2026:
> "das dann schon rechtlich jetzt nicht okay ist, dass ein Demo-Account das sieht [...] soll gar
> nicht sehen, dass es die Möglichkeit gibt" + "ich nämlich Platz sparen muss" -- die automatisch
> geladene Inline-Variante vom Update davor war ein Missverständnis): `member_detail.php` zeigt
> den Button "WLAN-Info anzeigen" (mit `alert()`-Popup wie ursprünglich) jetzt nur noch für
> Obmann/Platform-Admin -- `Auth::isDemo()` blendet den Button komplett aus, nicht nur den Inhalt,
> damit im Demo-Zugang nicht einmal erkennbar ist, dass WLAN-Zugangsdaten grundsätzlich
> nachsehbar wären. Der zugrundeliegende Endpunkt
> `/portal/members/:id/metering-points/:mpid/wifi-info` bleibt zusätzlich wie gehabt maskiert
> (Verteidigung in der Tiefe, falls er doch direkt aufgerufen wird).
>
> **2. Rechnungsliste (`/portal/billing/invoices`, `/portal/billing/invoices/:id/edit`):**
> Mitgliedernamen/E-Mail/IBAN/Mandatsreferenz jetzt über `demoMaskMembers()`/`demoMaskMember()`
> maskiert (Patrick, 23.08.2026: "bitte für zukünftige Rechnungen [...] auch wieder maskieren").
> Rechnungs-PDFs selbst waren bereits über die Datei-Download-Sperre vom vorletzten Update
> abgedeckt, SEPA-Sammellastschrift-Vorschau ebenso -- hier ging es nur um die bislang
> ungemaskte Listen-/Bearbeiten-Ansicht.

> **Einmalig nach dem Update vom 07.09.2026** (Mitglied-Bearbeiten-Formular für Demo-Zugang
> gesperrt, Namen in Support-Ticket-Nachrichten maskiert -- reine Code-Änderung, kein
> Migrations-/Setup-Skript nötig):
>
> **1. `/portal/members/:id/edit`:** dieses Formular zeigt echte Werte vorbefüllt in
> Eingabefeldern (IBAN, Adresse, Geburtsdatum, ...) -- eine spaltenweise Maskierung wie bei den
> reinen Anzeige-Seiten wäre hier nicht sinnvoll (verfälscht ein Formular, in dem ohnehin nicht
> gespeichert werden kann). Neuer genereller Helper `denyDemoPage(string $message)` (Refactor von
> `denyDemoFileDownload()`, das jetzt nur noch einen festen Text an ihn weiterreicht) zeigt
> stattdessen direkt die "Nur Lesezugriff"-Seite (Patrick, 24.08.2026: "/members/<id>/edit darf
> nicht verfügbar sein"). Der "Bearbeiten"-Button auf der Mitglied-Detailseite bleibt bewusst
> sichtbar (führt nur zur Sperr-Seite) -- Patricks Grundprinzip "alle Funktionen und Buttons
> sichtbar" gilt weiterhin, anders als beim WLAN-Info-Button (dort sollte nicht einmal die
> Möglichkeit erkennbar sein).
>
> **2. Support-Ticket-Nachrichten:** die bereits bestehende Maskierung des Ticket-Headers
> (`/portal/support/:id`) griff nicht auf die einzelnen Nachrichten im Thread durch -- `author_label`
> in `support_ticket_messages` ist freier Text (voller Name zum Zeitpunkt des Absendens, keine
> members-Fremdschlüssel-Spalte), Patrick per Screenshot: "steht drinnen trotzdem immer der volle
> Name". Neue Funktion `demoMaskSupportMessages()` maskiert sowohl Mitglied- als auch
> Verwaltungs-Nachrichten (`author_label = Auth::userName()`, ebenfalls ein echter Name) --
> eigene Nachrichten der beiden fiktiven Demo-Mitglieder bleiben unmaskiert.

> **Einmalig nach dem Update vom 24.08.2026** (Energiefluss-Grafik neu gezeichnet, geometrisch
> statt mit starren CSS-Connectors -- reine Code-Änderung, kein Migrations-/Setup-Skript nötig):
> `webapp/src/views/partials/energy_flow.php` (gemeinsam genutzt von `manager_dashboard.php` und
> `member_dashboard.php`) zeichnet die Verbindungslinien + animierten Energie-Impulse zwischen
> PV-/Netz-/Verbrauch-Kreisen und dem EEG-Knoten als SVG, per JS aus den tatsächlichen
> Kreis-Positionen/-Radien berechnet (`getBoundingClientRect()`), statt fixer CSS-Connector-Divs
> mit Lücke zum Kreisrand (Patrick, 24.08.2026, nach Vorbild der Fronius-Energiefluss-Darstellung:
> "Die Animation darf nicht erst mehrere Pixel/Abstände außerhalb des Kreises beginnen").
> **Ausschließlich gerade Linien** -- eine erste Fassung hatte die PV-Verbindung noch als
> Bezier-Kurve um den Text "676 W"/"PV-Erzeugung" herumgeführt, das wurde von Patrick im selben
> Update wieder verworfen ("ABSOLUT KEINE KURVEN [...] Die Verbindung soll immer die direkte
> kürzeste gerade Strecke [...] sein"): die Linie läuft jetzt bewusst gerade durch den Text
> hindurch, der Text bleibt unverändert an seiner Position, nur unter der Linie (z-index).
> **Genau EIN Energie-Impuls je aktiver Verbindung** (nicht mehrere gleichzeitig) -- bewegt sich
> von Kreisrand zu Kreisrand (~1s), verschwindet vollständig, macht exakt 0,5s Pause, startet neu
> ("Impuls → Ziel → verschwinden → 0,5 s Pause → Impuls → ..."). Technisch über SVG
> `<animateMotion>`/`<mpath>` mit `begin="0s;<eigene-id>.end+0.5s"` gelöst -- ein
> Standard-SMIL-Idiom für eine sich selbst wiederholende Animation mit Pause zwischen den
> Durchläufen (`repeatCount="indefinite"` kennt keine Pause zwischen Wiederholungen). Richtung
> aus den tatsächlichen Leistungswerten abgeleitet (PV->EEG, EEG->Verbrauch, Netz<->EEG je nach
> Vorzeichen), keine Animation bei 0 W. Farben/Typografie/Kreisgrößen/Layout unangetastet.
> Beide Fassungen vor dem jeweiligen Commit mit Playwright gegen das echte `app.css` gerendert
> und verifiziert -- bei der zweiten Fassung zusätzlich das SMIL-Timing selbst per
> `page.evaluate()`-Polling (nicht nur Screenshots) auf exakt 1s Bewegung + 0,5s Pause geprüft.
> **Netz/Verbrauch strikt waagrecht** (dritte Nachbesserung, selbes Update, Patrick: "das schiefe
> gefällt mir nicht"): beide Linien nehmen jetzt bewusst die Y-Koordinate des EEG-Knotens als
> gemeinsame Höhe (`trimHorizontal()`), statt der individuell gemessenen Kreis-Mitte -- letztere
> konnte durch unterschiedlich hohe Beschriftungen um ein, zwei Pixel abweichen und die Linie
> dadurch leicht schräg wirken lassen. Per `getAttribute('d')`-Vergleich verifiziert (y1 === y2).
>
> **Wichtigster Fund (selbes Update): der eigentliche Grund für die leere öffentliche
> Live-Anzeige war ein TimescaleDB-SkipScan-Bug, nicht (nur) die DB-Rolle** -- siehe "Bekannte
> Probleme" weiter oben für die volle Diagnose und den Fix (JOIN statt `NOT IN (SELECT ...)`
> in `/api/live/:slug`).
>
> **Zusätzlich (selbes Update): Live-Anzeige zeigt bei einem Fehler jetzt eine sichtbare
> Meldung statt stillschweigend nichts zu tun.** `webapp/src/views/pages/live.php` (öffentliche
> `/live`-Suchseite) ließ den Nutzer bisher ohne jeden Hinweis im Unklaren, wenn `/api/live/:slug`
> fehlschlug (Patrick, 24.08.2026: Namen eingetippt, aber Anzeige blieb einfach leer). Zwei Fixes:
> (1) Enter im Suchfeld lädt jetzt direkt bei genau einem Treffer oder exakter
> Namensübereinstimmung, auch ohne auf einen Dropdown-Eintrag zu klicken; (2) ein fehlgeschlagener
> Abruf zeigt jetzt eine Fehlermeldung an (den Fehlertext der Route, falls JSON, sonst
> "Fehler `<Statuscode>`") statt nichts anzuzeigen. Das deckt aber NICHT jeden Fall ab: bei einer
> unbehandelten PHP-Exception in der Route liefert `index.php`s globaler `set_exception_handler`
> eine generische HTML-Fehlerseite statt JSON zurück (deren "Technische Details"-Zeile zusätzlich
> nur für eingeloggte Nutzer sichtbar ist) -- die Live-Seite zeigt in diesem Fall nur "Fehler 500",
> der tatsächliche Exception-Text steht dann ausschließlich in `docker compose logs webapp`
> (`error_log()`-Zeile mit Präfix `[unhandled]`/`[fatal]`).

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
