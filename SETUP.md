# Setup-Anleitung

Schritt-für-Schritt-Anleitung, um die EEG-Plattform auf einem neuen Gerät zum Laufen zu bringen.

---

## Voraussetzungen

| Software | Mindestversion | Prüfen |
|----------|----------------|--------|
| Docker | 24+ | `docker --version` |
| Docker Compose | 2.20+ (Plugin) | `docker compose version` |
| Git | beliebig | `git --version` |

Docker noch nicht installiert? → **[docs/DOCKER_INSTALL.md](docs/DOCKER_INSTALL.md)**
(macOS/Windows/Linux, jeweils per Webseite-Installer oder Kommandozeile).

---

## Schnellstart (empfohlen): ein Befehl

Nach dem Klonen erledigt `scripts/setup.sh` alles automatisch: `.env` mit zufällig generierten
Secrets anlegen, Datenverzeichnisse mit korrekten Rechten vorbereiten, Container bauen und
starten, alle Datenbank-Migrationen einspielen und den ersten Platform-Admin-Zugang anlegen
(fragt dafür interaktiv nach E-Mail-Adresse und Passwort).

```bash
git clone https://github.com/ropperp/eeg-platform.git
cd eeg-platform
./scripts/setup.sh
```

Das Skript fragt der Reihe nach:
1. **Domain** (z.B. `stromfueralle.at`) -- leer lassen für rein lokales Testen ohne echte Domain.
2. **Admin-E-Mail-Adresse** und **Admin-Passwort** (mind. 8 Zeichen, mit Wiederholung) --
   das ist der erste Platform-Admin-Zugang, mit dem man sich danach einloggt.

Am Ende steht die Login-URL da. Fertig -- kein manuelles `.env`-Editieren, keine
Berechtigungs-Klimmzüge, kein Passwort-Hash von Hand erzeugen.

> Das Skript ist sicher mehrfach ausführbar: eine bereits vorhandene `.env` wird nicht
> angefasst, bereits eingespielte Migrationen überspringen sich selbst, und ein erneuter
> Durchlauf des Admin-Schritts setzt nur das Passwort des bestehenden Accounts neu.

**Lokale Entwicklung ohne Traefik** (webapp direkt auf Port 80 statt über den Reverse-Proxy):
vor dem Skript-Aufruf eine `docker-compose.override.yml` anlegen -- siehe Abschnitt
["Lokale Entwicklung"](#lokale-entwicklung) unten. Auf dem Produktivserver darf diese Datei
**nicht** existieren.

---

## Manuelle Installation (Schritt für Schritt)

Für alle, die lieber jeden Schritt selbst sehen/kontrollieren wollen, oder zum Nachvollziehen,
was `scripts/setup.sh` eigentlich automatisch macht.

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

# SMTP (optional -- kann auch leer bleiben und später über Platform-Admin ->
# E-Mail-Einstellungen per Microsoft Graph konfiguriert werden)
SMTP_HOST=smtp-relay.brevo.com
SMTP_USER=dein@email.com
SMTP_PASSWORD=BREVO_SMTP_PASSWORT
```

> **Wichtig:** `.env` niemals in Git committen — steht bereits in `.gitignore`.
> SSL wird beim Produktivsystem vom vorgelagerten nginx-Proxy erledigt, nicht von Traefik
> (Traefik hört hier nur auf Port 80, kein Let's Encrypt nötig).

### 3. Datenpersistenz vorbereiten (Pflicht, nicht nur empfohlen)

Die Datenbank, Redis, Mosquitto und alle Mitglieder-Uploads (Ausweis-Scans,
Beitrittserklärungen, Profilbilder, generierte Vertrags-PDFs, LaTeX-Vorlagen) speichern Daten
unter `/opt/eeg/`. Auf Raspberry Pi mit NVMe-SSD: sicherstellen, dass `/opt/` auf der SSD liegt.

```bash
sudo mkdir -p /opt/eeg/{timescaledb,redis,mosquitto/data,mosquitto/log,traefik/letsencrypt,webapp-storage/uploads,webapp-storage/pdfs,latex-templates}
sudo chmod 755 /opt/eeg
sudo chown -R 82:82 /opt/eeg/webapp-storage /opt/eeg/latex-templates
```

> **Wichtig:** `webapp-storage` und `latex-templates` MÜSSEN existieren und bereits `82:82`
> (www-data im Alpine-PHP-Image) gehören, BEVOR `docker compose up -d --build` zum ersten Mal
> läuft. Legt Docker die Verzeichnisse selbst an (weil sie fehlen), gehören sie root, und PHP
> kann dann in keine Upload-/Profilbild-/Vorlagen-Funktion mehr schreiben (500-Fehler bzw.
> "Datei konnte nicht gespeichert werden" bei jedem Upload). `latex-templates` wird von
> latex-service (läuft als root, daher unkritisch) UND von webapp (www-data) gemeinsam
> beschrieben -- root darf trotz `82:82`-Eigentümer weiterhin schreiben, deshalb reicht
> dieselbe Eigentümerschaft wie bei webapp-storage.
>
> `latex-templates` bleibt beim allerersten Start leer -- latex-service kopiert dann beim
> Hochfahren einmalig seine mitgelieferten Standard-Vorlagen hinein (siehe
> `latex-service/docker/entrypoint.sh`), ohne dieses Verzeichnis würden diese Standard-Vorlagen
> das erste `git pull` überleben aber nicht, da sie sonst nur im Image liegen.

### 4. Container starten

**Produktion** (Traefik auf Port 80, SSL vom nginx-Proxy davor):

```bash
docker compose up -d --build
```

**Lokale Entwicklung** (webapp direkt auf Port 80, kein Traefik): <a name="lokale-entwicklung"></a>

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

Dann normal `docker compose up -d --build` — Traefik startet nicht, webapp ist direkt auf
Port 80 erreichbar.

> **Achtung Produktion:** Diese Override-Datei darf auf dem Produktivserver **nicht** existieren, sonst blockiert sie Port 80 und deaktiviert Traefik-Routing.

### 5. Datenbank-Migrationen einspielen (Pflicht)

`database/init.sql` wird beim allerersten Start des `timescaledb`-Containers automatisch
ausgeführt (Postgres' `docker-entrypoint-initdb.d`-Mechanismus) — das reicht aber NICHT für den
vollen Funktionsumfang. Jede Datei `database/migrate_YYYYMMDD.sql` enthält seither
hinzugekommene Tabellen/Spalten (u. a. E-Mail-Konfiguration/-Vorlagen, Testmodus-Einstellung,
Vertrags-Versand-Tracking, API-Keys) und muss nach dem ersten Start **in chronologischer
Reihenfolge** eingespielt werden:

```bash
for f in database/migrate_*.sql; do
  echo "=== $f ==="
  docker compose exec -T timescaledb psql -U eeg -d eeg_platform < "$f"
done
```

Ohne diesen Schritt fehlen z. B. die Tabellen für die E-Mail-Einstellungen im Platform-Admin,
und einzelne Seiten liefern `SQLSTATE[42P01]: Undefined table`-Fehler.

### 6. Ersten Platform-Admin-Zugang anlegen

Anders als früher gibt es **keinen** fest im Repo eingetragenen Admin-Account mehr (kein
Platzhalter-Passwort, keine hartcodierte E-Mail-Adresse) -- der erste Zugang wird hier direkt
mit einem selbst gewählten Passwort angelegt:

```bash
# 1. Bcrypt-Hash erzeugen (im laufenden webapp-Container, kein Extra-Tool nötig)
docker compose exec webapp php -r "echo password_hash('NEUES_PASSWORT', PASSWORD_BCRYPT), PHP_EOL;"

# 2. Admin-User + Rollen anlegen (E-Mail und den Hash aus Schritt 1 einsetzen)
docker compose exec -T timescaledb psql -U eeg -d eeg_platform -v ON_ERROR_STOP=1 \
  -v admin_email="DEINE_ADMIN_EMAIL" -v admin_hash="HASH_AUS_SCHRITT_1" <<'SQL'
INSERT INTO users (email, password_hash, first_name, last_name)
VALUES (:'admin_email', :'admin_hash', 'Platform', 'Admin')
ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash;

INSERT INTO user_roles (community_id, user_id, role)
SELECT c.id, u.id, 'platform_admin' FROM communities c, users u WHERE u.email = :'admin_email'
ON CONFLICT DO NOTHING;

INSERT INTO user_roles (community_id, user_id, role)
SELECT c.id, u.id, 'manager' FROM communities c, users u WHERE u.email = :'admin_email'
ON CONFLICT DO NOTHING;
SQL
```

Danach ganz normal unter `https://DOMAIN/portal/login` mit der gewählten E-Mail-Adresse und
dem neuen Passwort einloggen. (Dieser komplette Schritt 6 ist genau das, was
`scripts/setup.sh` automatisch und interaktiv erledigt.)

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

Wenn es neue Datenbank-Migrationen gibt (Datei `database/migrate_YYYYMMDD.sql`), die noch nicht
eingespielten Dateien einzeln oder alle auf einmal nachziehen:

```bash
docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_YYYYMMDD.sql
# oder: einfach alle (bereits eingespielte werden dank "IF NOT EXISTS" i.d.R. sauber übersprungen)
for f in database/migrate_*.sql; do docker compose exec -T timescaledb psql -U eeg -d eeg_platform < "$f"; done
```

---

## Datensicherung (Backups)

Vollständige Anleitung (Backup, externe Kopie auf NAS, Restore inkl. Uploads) →
**[docs/BACKUP.md](docs/BACKUP.md)**

Kurzfassung:

```bash
make backup           # DB-Dump
make backup-storage   # Uploads (Avatare, Ausweis-Scans, Beitrittserklärungen)
make backup-all       # beides zusammen
```

> Niemals das PostgreSQL-Datenverzeichnis (`/opt/eeg/timescaledb/`) bei laufender DB kopieren
> — das führt zu Korruption. Immer die Skripte (`pg_dump`-basiert) verwenden, nie die Dateien
> direkt kopieren.

Empfohlener Cron-Rhythmus (täglich, DB-Dump → Storage-Archiv → externe Kopie zeitlich
versetzt) und Details zur NAS-Synchronisation: siehe `docs/BACKUP.md`.

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
| 500-Fehler nur bei Datei-/Profilbild-/Vorlagen-Upload | `docker compose exec webapp cat /var/log/nginx/error.log` prüfen (NICHT `docker compose logs`, das zeigt nur den Access-Log-Teil) — meist Berechtigungsproblem auf `/var/lib/nginx/tmp` oder `/opt/eeg/webapp-storage`/`/opt/eeg/latex-templates` (siehe Schritt 3), siehe auch `CLAUDE.md` |
| `SQLSTATE[42P01]: Undefined table` | Migration fehlt — Schritt 5 (Datenbank-Migrationen einspielen) wiederholen |
| `docker` bzw. `docker compose` nicht gefunden | Docker noch nicht installiert -- siehe [docs/DOCKER_INSTALL.md](docs/DOCKER_INSTALL.md) |
