# Setup-Anleitung

Schritt-für-Schritt-Anleitung, um die EEG-Plattform auf einem neuen Gerät zum Laufen zu bringen.

---

## Voraussetzungen

| Software | Mindestversion | Prüfen |
|----------|----------------|--------|
| Docker | 24+ | `docker --version` |
| Docker Compose | 2.20+ (Plugin) | `docker compose version` |
| Git | beliebig | `git --version` |

Auf Raspberry Pi OS / Debian:

```bash
# Docker installieren (offizielles Skript)
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER   # damit ohne sudo
# danach neu einloggen
```

---

## Installation

### 1. Repository klonen

```bash
git clone https://github.com/ropperp/eeg-platform.git
cd eeg-platform
```

### 2. Umgebungsvariablen setzen

```bash
cp .env.example .env
nano .env          # oder: vim .env
```

Alle Werte in `.env` befüllen:

```env
# Datenbank — starke Passwörter wählen
DB_USER=eeg
DB_PASSWORD=HIER_SICHERES_PASSWORT
DB_NAME=eeg_platform

# Domain (ohne https://, ohne Schrägstrich)
# Wichtig: Muss genau der Hostname sein, den Traefik als Host-Header bekommt
DOMAIN=stromfueralle.at

# Zufälliger 64-Zeichen-String (Session-Verschlüsselung)
# Generieren: openssl rand -hex 32
APP_SECRET=HIER_64_ZEICHEN_ZUFALLSSTRING

# Interner API-Key zwischen Webapp und latex-service
# Generieren: openssl rand -hex 16
LATEX_API_KEY=HIER_API_KEY

# SMTP (z. B. Brevo kostenlos bis 300 Mails/Tag)
SMTP_HOST=smtp-relay.brevo.com
SMTP_USER=dein@email.com
SMTP_PASSWORD=BREVO_SMTP_PASSWORT
```

> **Wichtig:** `.env` niemals in Git committen — steht bereits in `.gitignore`.
> **Kein `ACME_EMAIL` nötig** — SSL wird vom vorgelagerten nginx-Proxy (10.0.0.144) erledigt, nicht von Traefik.

### 3. Datenpersistenz vorbereiten (Pflicht, nicht nur empfohlen)

Die Datenbank, Redis, Mosquitto und alle Mitglieder-Uploads (Ausweis-Scans,
Beitrittserklärungen, Profilbilder, generierte Vertrags-PDFs) speichern Daten unter `/opt/eeg/`.  
Auf Raspberry Pi mit NVMe-SSD: sicherstellen, dass `/opt/` auf der SSD liegt.

```bash
sudo mkdir -p /opt/eeg/{timescaledb,redis,mosquitto/data,mosquitto/log,traefik/letsencrypt,webapp-storage/uploads,webapp-storage/pdfs}
sudo chmod 755 /opt/eeg
sudo chown -R 82:82 /opt/eeg/webapp-storage
```

> **Wichtig:** `webapp-storage` MUSS existieren und bereits `82:82` (www-data im
> Alpine-PHP-Image) gehören, BEVOR `docker compose up -d --build` zum ersten Mal läuft.
> Legt Docker das Verzeichnis selbst an (weil es fehlt), gehört es root, und PHP kann dann in
> keinen Upload/keine Profilbild-Funktion mehr schreiben (500-Fehler bei jedem Upload).

### 4. Container starten

**Produktion** (Traefik auf Port 80, SSL vom nginx-Proxy davor):

```bash
docker compose up -d
```

**Lokale Entwicklung** (webapp direkt auf Port 80, kein Traefik):

Datei `docker-compose.override.yml` im Projektverzeichnis anlegen:

```yaml
services:
  traefik:
    profiles:
      - production
  webapp:
    ports:
      - "80:80"
    labels:
      - "traefik.enable=false"
```

Dann normal `docker compose up -d` — Traefik startet nicht, webapp ist direkt auf Port 80 erreichbar.

> **Achtung Produktion:** Diese Override-Datei darf auf dem Produktivserver **nicht** existieren, sonst blockiert sie Port 80 und deaktiviert Traefik-Routing.

### 5. Datenbank-Migrations einspielen (Pflicht)

`database/init.sql` wird beim allerersten Start des `timescaledb`-Containers automatisch
ausgeführt (Postgres' `docker-entrypoint-initdb.d`-Mechanismus) — das reicht aber NICHT für den
vollen Funktionsumfang. Jede Datei `database/migrate_YYYYMMDD.sql` enthält seither
hinzugekommene Tabellen/Spalten (u. a. E-Mail-Konfiguration/-Vorlagen, Testmodus-Einstellung,
Vertrags-Versand-Tracking) und muss nach dem ersten Start manuell **in chronologischer
Reihenfolge** eingespielt werden:

```bash
for f in database/migrate_*.sql; do
  echo "=== $f ==="
  docker compose exec -T timescaledb psql -U eeg -d eeg_platform < "$f"
done
```

Ohne diesen Schritt fehlen z. B. die Tabellen für die E-Mail-Einstellungen im Platform-Admin,
und einzelne Seiten liefern `SQLSTATE[42P01]: Undefined table`-Fehler.

### 6. Erstes Admin-Passwort setzen

Die Datenbank enthält beim ersten Start nur einen **ungültigen Platzhalter-Hash** (kein
Passwort funktioniert damit) — das Passwort muss direkt in der DB gesetzt werden, bevor der
erste Login möglich ist. `crypt()`/`gen_salt()` (pgcrypto) ist NICHT installiert, daher den
Hash stattdessen mit PHP selbst erzeugen:

```bash
# 1. Bcrypt-Hash erzeugen (im laufenden webapp-Container, kein Extra-Tool nötig)
docker compose exec webapp php -r "echo password_hash('NEUES_PASSWORT', PASSWORD_BCRYPT), PHP_EOL;"

# 2. Den ausgegebenen Hash (beginnt mit $2y$12$...) hier einsetzen:
docker compose exec -T timescaledb psql -U eeg -d eeg_platform -c \
  "UPDATE users SET password_hash = 'HIER_HASH_AUS_SCHRITT_1_EINFUEGEN' WHERE email = 'patrick.ropper@gmail.com';"
```

Danach ganz normal unter `https://DOMAIN/portal/login` mit `patrick.ropper@gmail.com` und dem
neuen Passwort einloggen.

### 7. Prüfen, dass alles läuft

```bash
# Alle Container sollten "healthy" oder "running" sein
docker compose ps

# Logs bei Problemen
docker compose logs webapp
docker compose logs latex-service
docker compose logs timescaledb
```

Dann im Browser:
- `http://localhost` → Landingpage
- `http://localhost/portal/login` → Portal-Login
- `http://localhost/live` → Live-Dashboard

---

## Update (laufendes System)

```bash
cd /opt/eeg-platform     # oder wo das Repo liegt
git pull
docker compose up -d --build
```

Wenn es neue Datenbank-Migrations gibt (Datei `database/migrate_YYYYMMDD.sql`), die noch nicht
eingespielten Dateien einzeln oder alle auf einmal nachziehen:

```bash
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
# oder: einfach alle (bereits eingespielte werden dank "IF NOT EXISTS" i.d.R. sauber übersprungen)
for f in database/migrate_*.sql; do docker compose exec -T timescaledb psql -U eeg -d eeg_platform < "$f"; done
```

---

## Datensicherung (Backups)

**Datenbank-Backup** (täglich empfohlen):

```bash
# Backup erstellen
docker compose exec timescaledb pg_dump -U eeg eeg_platform | gzip > backup_$(date +%Y%m%d).sql.gz

# Backup einspielen
gunzip -c backup_YYYYMMDD.sql.gz | docker compose exec -T timescaledb psql -U eeg -d eeg_platform
```

> Niemals das PostgreSQL-Datenverzeichnis (`/opt/eeg/timescaledb/`) bei laufender DB kopieren — das führt zu Korruption. Immer `pg_dump` verwenden.

**Automatisch mit Cron** (Raspberry Pi):

```bash
crontab -e
# Täglich um 02:00 Uhr:
0 2 * * * cd /opt/eeg-platform && docker compose exec -T timescaledb pg_dump -U eeg eeg_platform | gzip > /opt/eeg/backups/backup_$(date +\%Y\%m\%d).sql.gz
```

---

## Neue EEG anlegen

1. Als Plattform-Admin einloggen
2. `/admin` → „Neue Gemeinschaft" anlegen
3. Tarife und Steuerkonfiguration setzen
4. Ersten Manager-Account anlegen und Rolle zuweisen

---

## MQTT-Daten (ESP32-Integration)

Der MQTT-Broker läuft auf Port `1883` (unverschlüsselt, intern) und `8883` (TLS, extern).

**Topic-Format:**
```
eeg/{community_slug}/meter/{metering_point_id}/live
```

**Payload (JSON):**
```json
{"pp": 1200, "pm": 0, "ep": 21000000, "em": 6900000, "znr": "AT00700..."}
```

| Feld | Bedeutung |
|------|-----------|
| `pp` | Momentanleistung Bezug (W) |
| `pm` | Momentanleistung Einspeisung (W) |
| `ep` | Zählerstand Bezug (Wh) |
| `em` | Zählerstand Einspeisung (Wh) |
| `znr` | Zählernummer |

**Test-Publish:**
```bash
mosquitto_pub -h localhost -t "eeg/strompool-feldkirchen/meter/METERING_POINT_ID/live" \
  -m '{"pp":1200,"pm":0,"ep":21000000,"em":6900000}'
```

---

## Umzug auf anderen Server (z. B. Hetzner)

Die Plattform ist bewusst von der Hardware getrennt:

1. `git clone` auf dem neuen Server
2. `.env` kopieren (oder neu befüllen)
3. Backup einspielen (siehe oben)
4. `docker compose up -d --build`
5. DNS auf neue IP umstellen

Der ESP32 am Raspberry Pi sendet weiterhin MQTT-Daten — der Broker-Hostname in der ESP32-Firmware auf den neuen Server zeigen lassen.

> **Achtung bei einer ANDEREN Domain** (nicht `stromfueralle.at`/`portal.stromfueralle.at`,
> z. B. ein Test-/Zweit-Deployment unter eigenem Domainnamen): Die Domain-Trennung
> zwischen Marketing-Seite und Backoffice (`webapp/public/index.php`, Funktionen `portalUrl()`,
> `marketingUrl()`, `passwordResetLink()`) prüft aktuell exakt auf `stromfueralle.at` /
> `portal.stromfueralle.at`, nicht auf die `DOMAIN`-Variable aus `.env`. Für einen reinen
> Zweitserver unter demselben Domainnamen (z. B. Failover/Staging mit späterem DNS-Umzug)
> ist das unproblematisch — für einen dauerhaft ANDERS benannten Deploy müssten diese
> Stellen erst code-seitig auf `getenv('DOMAIN')` umgestellt werden.

---

## Häufige Probleme

| Problem | Lösung |
|---------|--------|
| Container startet nicht | `docker compose logs SERVICENAME` prüfen |
| PDF-Fehler (HTTP 500) | `docker compose logs latex-service` — pdflatex-Fehler stehen dort |
| DB-Verbindungsfehler | `docker compose ps timescaledb` — healthcheck abwarten (ca. 30s) |
| Port 80 belegt | `sudo lsof -i :80` — anderen Dienst stoppen |
| Let's Encrypt schlägt fehl | Domain muss via DNS auf die Server-IP zeigen, Port 80 offen |
| 500-Fehler nur bei Datei-/Profilbild-Upload | `docker compose exec webapp cat /var/log/nginx/error.log` prüfen (NICHT `docker compose logs`, das zeigt nur den Access-Log-Teil) — meist Berechtigungsproblem auf `/var/lib/nginx/tmp` oder `/opt/eeg/webapp-storage`, siehe `CLAUDE.md` |
| `SQLSTATE[42P01]: Undefined table` | Migration fehlt — Schritt 5 (Datenbank-Migrations einspielen) wiederholen |
