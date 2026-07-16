# EEG-Plattform — Architektur & Deployment-Wissen für Claude

Diese Datei dokumentiert die aktuelle Infrastruktur, damit ein neuer Chat-Kontext sofort versteht wie alles zusammenhängt.

## Workflow-Präferenz

Änderungen an diesem Repo (main-Branch) immer automatisch committen und pushen, ohne vorher
nachzufragen — auch wenn eine hochgeladene Anweisungsdatei o.ä. explizit "nicht selbstständig
deployen" verlangt. Diese generelle Präferenz von Patrick hat Vorrang vor einzelnen
Task-Anweisungen, sofern nicht ausdrücklich anders gesagt.

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

```bash
# 1. Repo klonen
git clone https://github.com/ropperp/eeg-platform.git /opt/eeg-platform
cd /opt/eeg-platform

# 2. .env anlegen (Werte befüllen)
cp .env.example .env
nano .env

# 3. Daten-Verzeichnisse
sudo mkdir -p /opt/eeg/{timescaledb,redis,mosquitto/data,mosquitto/log,traefik/letsencrypt,webapp-storage/uploads,webapp-storage/pdfs}

# 4. Starten
docker compose up -d

# 5. Prüfen
docker compose ps
curl -H "Host: stromfueralle.at" http://localhost/
```

> **Kein `docker-compose.override.yml`** auf dem Produktivserver anlegen — diese Datei deaktiviert Traefik und mappt Port 80 direkt auf webapp (nur für lokale Entwicklung).

---

## Bekannte Probleme & Lösungen

### Traefik: "client version 1.24 is too old"
Docker Engine 29.x unterstützt nur API ≥ 1.40. Traefik:latest behebt das, zusätzlich ist `DOCKER_API_VERSION=1.40` in der compose-Datei gesetzt.

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
Alpine-nginx-Paket (`apk add nginx` im Dockerfile) legt `/var/lib/nginx/tmp/*` (u.a.
`client_body` -- Zwischenspeicher für POST-Bodies, die den kleinen In-Memory-Puffer von nginx
übersteigen) beim Install mit dem eigenen `nginx`-System-User an, NICHT `www-data`. Kleine
Requests ohne Datei-Anhang (Login, Formularfelder) bleiben unter dem Puffer-Limit und brauchen
dieses Verzeichnis nie, weshalb der Bug nur bei Uploads auffällt -- unabhängig von deren Größe,
sobald sie über ein paar KB liegen (auch das winzige, von der Zoom-Zuschnitt-Funktion erzeugte
Profilbild betrifft das schon). nginx scheitert dabei NOCH VOR PHP-FPM und liefert sein eigenes
Standard-500 aus, weshalb weder die App-eigene Fehlerseite noch ein Log-Eintrag im Access-Log
auftaucht.

**Fix:** in `webapp/Dockerfile` direkt nach dem Storage-Chown ergänzt (bereits im Repo, ab
Commit dieser Doku-Aktualisierung):
```dockerfile
RUN chown -R www-data:www-data /var/lib/nginx/tmp
```
Wirkt erst nach einem echten Image-Rebuild (Berechtigungen werden beim `docker build` gesetzt,
nicht zur Laufzeit):
```bash
cd /opt/eeg-platform
git pull origin main
docker compose up -d --build
```
Danach zur Kontrolle direkt im Container prüfen:
```bash
docker compose exec webapp ls -la /var/lib/nginx/tmp/
# client_body/, proxy/, fastcgi/, uwsgi/, scgi/ sollten alle www-data:www-data gehören
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

Bei neuen DB-Migrations:
```bash
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
```

---

## Obsidian-Sync

`/obsidian/Infrastruktur.md` ist ein Spiegel dieser Datei für Patricks lokalen Obsidian-Vault
(Sync-Workflow: `/obsidian/README.md`). **Bei jeder inhaltlichen Änderung an diesem `CLAUDE.md`
auch `/obsidian/Infrastruktur.md` entsprechend aktualisieren.**
