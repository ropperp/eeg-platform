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
DOMAIN=eegflow.at

# E-Mail für Let's Encrypt-Zertifikat
ACME_EMAIL=admin@eegflow.at

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

### 3. Datenpersistenz vorbereiten (empfohlen)

Die Datenbank, Redis und Mosquitto speichern Daten unter `/opt/eeg/`.  
Auf Raspberry Pi mit NVMe-SSD: sicherstellen, dass `/opt/` auf der SSD liegt.

```bash
sudo mkdir -p /opt/eeg/{timescaledb,redis,mosquitto/data,mosquitto/log,traefik/letsencrypt}
sudo chmod 755 /opt/eeg
```

### 4. Container starten

**Lokale Entwicklung** (kein Traefik, kein SSL, webapp direkt auf Port 80):

```bash
docker compose up -d
```

**Produktion** (mit Traefik + Let's Encrypt — Domain muss bereits auf die IP zeigen):

```bash
# docker-compose.override.yml löschen/umbenennen, dann:
docker compose --profile production up -d
```

### 5. Erstes Admin-Passwort setzen

Die Datenbank enthält beim ersten Start einen Platzhalter-Passwort-Hash.  
Passwort sofort über die Web-Oberfläche ändern:

1. `https://DOMAIN/portal/login` aufrufen
2. Mit `patrick.ropper@gmail.com` einloggen (Platzhalter-Passwort: `ChangeMe2026!`)
3. Rechts oben auf den Avatar-Kreis → **Passwort ändern**

> Das Platzhalter-Passwort ist nur beim allerersten Start gültig.  
> Alternativ direkt in der DB setzen:
> ```bash
> docker compose exec timescaledb psql -U eeg -d eeg_platform -c \
>   "UPDATE users SET password_hash = crypt('NEUES_PASSWORT', gen_salt('bf')) WHERE email = 'patrick.ropper@gmail.com';"
> ```

### 6. Prüfen, dass alles läuft

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

Wenn es neue Datenbank-Migrations gibt (Datei `database/migrate_YYYYMMDD.sql`):

```bash
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
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

---

## Häufige Probleme

| Problem | Lösung |
|---------|--------|
| Container startet nicht | `docker compose logs SERVICENAME` prüfen |
| PDF-Fehler (HTTP 500) | `docker compose logs latex-service` — pdflatex-Fehler stehen dort |
| DB-Verbindungsfehler | `docker compose ps timescaledb` — healthcheck abwarten (ca. 30s) |
| Port 80 belegt | `sudo lsof -i :80` — anderen Dienst stoppen |
| Let's Encrypt schlägt fehl | Domain muss via DNS auf die Server-IP zeigen, Port 80 offen |
