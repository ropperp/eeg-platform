# EEG-Plattform — Architektur & Deployment-Wissen für Claude

Diese Datei dokumentiert die aktuelle Infrastruktur, damit ein neuer Chat-Kontext sofort versteht wie alles zusammenhängt.

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

> `www.stromfueralle.at` muss als SAN im Zertifikat enthalten sein (siehe "www-Subdomain hinzufügen" unten), sonst liefert nginx für www das Default-Zertifikat aus und Browser zeigen einen SSL-Fehler.

---

## EEG-Server (10.0.0.250)

### Verzeichnis
```
/opt/eeg-platform/   ← Git-Repo (branch: main)
/opt/eeg/            ← Persistente Daten (DB, Redis, Mosquitto, Traefik-Certs)
```

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
sudo mkdir -p /opt/eeg/{timescaledb,redis,mosquitto/data,mosquitto/log,traefik/letsencrypt}

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

Bei neuen DB-Migrations:
```bash
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
```
