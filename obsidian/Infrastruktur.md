---
tags: [eeg-platform, infrastruktur, stromfueralle]
quelle: CLAUDE.md (eeg-platform Repo-Root)
---

# EEG-Plattform — Infrastruktur

> Spiegel von `CLAUDE.md` im [eeg-platform](https://github.com/ropperp/eeg-platform)-Repo.
> Bei jeder Änderung an `CLAUDE.md` wird diese Notiz mit aktualisiert.

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

> `www.stromfueralle.at` muss als SAN im Zertifikat enthalten sein, sonst liefert nginx
> für www das Default-Zertifikat aus und Browser zeigen einen SSL-Fehler.

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
| traefik | traefik:latest | 80:80 | Reverse Proxy, liest Docker-Labels |
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

> **Einmalig nach dem Update vom 14.07.2026:** Storage-Volume vorher anlegen, sonst
> Rechteproblem (www-data/UID 82 im Alpine-Image kann sonst nicht schreiben):
> ```bash
> sudo mkdir -p /opt/eeg/webapp-storage/{uploads,pdfs}
> sudo chown -R 82:82 /opt/eeg/webapp-storage
> ```

Bei neuen DB-Migrations:
```bash
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
```

## Bekannte Probleme & Lösungen

### Traefik: "client version 1.24 is too old"
Docker Engine 29.x unterstützt nur API ≥ 1.40 → `DOCKER_API_VERSION=1.40` in der compose-Datei.

### 404 von Traefik trotz laufendem webapp
1. **Ungültige Router-Regel (Traefik v3-Syntax)** — `Host(\`a\`, \`b\`)` ist v2-Syntax und
   lässt den Router fehlschlagen. Immer `Host(\`a\`) || Host(\`b\`)` verwenden.
   Prüfen: `docker logs traefik --tail 100 | grep -i error` und
   `docker compose config | grep "routers.*rule"`.
2. **`docker-compose.override.yml` vorhanden** mit `traefik.enable=false` (nur für lokale
   Entwicklung gedacht — auf Produktion löschen).
3. **Domain in `.env` falsch** → `DOMAIN` prüfen, dann `docker compose up -d --force-recreate webapp`.

### www-Subdomain hinzufügen
Traefik-Seite ist im Repo bereits fertig konfiguriert. Auf dem **separaten
nginx-Proxy-Host (10.0.0.144)** NICHT `certbot --nginx --expand` direkt verwenden — das
schreibt automatisch in die vhost-Datei und hat schon einmal den `server_name`-Block
zerlegt/dupliziert (mehrere Zertifikats-Lineages, Hauptdomain verlor ihr Zertifikat).
Stattdessen `certbot certonly --nginx --cert-name stromfueralle.at --expand -d ... -d www...`
(rührt die nginx-Config nicht an) und die vhost-Datei danach selbst schreiben.
Details/vollständiges Skript: siehe `CLAUDE.md` im Repo.

### portal-Subdomain für Login (ausstehend, Stand 2026-07-15)
Anmelden-Button verlinkt jetzt auf `portal.stromfueralle.at/portal/login`. Traefik-Routing
steht bereits, es fehlt noch die SSL-Terminierung auf 10.0.0.144 (gleiches Vorgehen wie bei
www oben, zusätzlich `-d portal.stromfueralle.at` im certbot-Aufruf + eigener server{}-Block
in der vhost-Datei). Details/vollständiges Skript: siehe `CLAUDE.md` im Repo.

### SSL-Zertifikat fehlt/ungültig
Meist: mehrere Zertifikats-Lineages für dieselbe Domain (`stromfueralle.at`,
`stromfueralle.at-0001`, `www.stromfueralle.at`) — Diagnose mit `sudo certbot certificates`,
Konsolidierung auf eine Lineage, dann `sudo certbot delete --cert-name <name>` für die
überzähligen (erst nach Verifikation!).

### Datei-/Profilbild-Upload: 500 im Browser (Stand 16.07.2026, gelöst)
Jeder Upload brach ab, `docker compose logs webapp` (nur Access-Log) zeigte nichts. Echte
Ursache erst sichtbar im nginx-**Fehler**-Log IM Container:
```bash
docker compose exec webapp cat /var/log/nginx/error.log
```
→ `open() "/var/lib/nginx/tmp/client_body/..." failed (13: Permission denied)`. Grund:
`nginx.conf` setzt `user www-data;`, aber Alpines nginx-Paket legt `/var/lib/nginx` SAMT
`tmp/*` mit dem eigenen `nginx`-User und Modus 750 an. Kleine Requests (Login) brauchen dieses
Zwischenspeicher-Verzeichnis nie, jeder Datei-/Profilbild-Upload (sobald der Body den
nginx-Puffer übersteigt) schon -- nginx scheitert dabei schon vor PHP-FPM und liefert sein
eigenes 500 aus.
**Erster Fix-Versuch unvollständig:** Nur `tmp/` selbst zu chownen reicht nicht -- der
Elternordner `/var/lib/nginx` blieb `nginx:nginx` mit Modus 750 (keine Rechte für "andere"),
wodurch `www-data` gar nicht hineinkonnte (Linux braucht Ausführungsrecht auf JEDEN
Pfad-Bestandteil). Erklärt das trügerische Verhalten: kleine Uploads (Body bleibt unter dem
nginx-Puffer, `client_body/` nie gebraucht) funktionierten, größere scheiterten weiter.
Fix in `webapp/Dockerfile` (bereits im Repo) -- chownt den ganzen Elternordner:
```dockerfile
RUN chown -R www-data:www-data /var/lib/nginx
```
Braucht einen echten Rebuild (`docker compose up -d --build`), reines `up -d` reicht nicht.
Zwei Sackgassen unterwegs, die NICHT die Ursache waren: leeres `docker compose logs traefik`
(normal, kein Accesslog konfiguriert) und fehlendes `proxy_http_version 1.1;` im nginx-Proxy auf
10.0.0.144 (sinnvoller Fix, hat dieses Problem aber nicht behoben). Details: siehe `CLAUDE.md`.
