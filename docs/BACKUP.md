# Backup & Restore

## Datenschutz-Hinweis

Die Dump-Dateien enthalten **echte Mitgliederdaten** (Namen, Adressen, IBAN, Energieverbrauch).

- **Dumps dürfen nicht in Git.** `backups/` steht in `.gitignore`.
- **Dumps extern sichern** (NAS, Hetzner Storage Box o. Ä.) — siehe Abschnitt "Externe Kopie".
- Zugriff auf `backups/` auf dem Server auf `root` und den Deploy-User beschränken.

---

## Backup erstellen

```bash
bash scripts/backup.sh
```

Erzeugt: `backups/eeg_YYYYMMDD_HHMM.dump` (PostgreSQL custom format, komprimiert)

Das custom-Format (`-Fc`) ist komprimierter als Plain-SQL und erlaubt selektives Restore einzelner Tabellen mit `pg_restore -t tabellenname`.

---

## Automatisches Backup via Cron (täglich 02:30 Uhr)

Auf dem Raspberry Pi als root oder als Deploy-User (der Docker-Zugriff hat):

```bash
crontab -e
```

Eintrag:

```
30 2 * * * cd /opt/eeg-platform && bash scripts/backup.sh >> /var/log/eeg-backup.log 2>&1
```

Log prüfen:

```bash
tail -20 /var/log/eeg-backup.log
ls -lh backups/
```

---

## Backup-Monitoring

Zwei unabhängige Ebenen überwachen, ob Backups pünktlich und erfolgreich laufen.

### Ebene 1 — In-App-Status (Admin-Dashboard)

`scripts/backup.sh` schreibt nach jedem Lauf drei Schlüssel in die Tabelle `system_status`:

| Key | Inhalt |
|-----|--------|
| `last_backup_at` | ISO-8601 Zeitstempel des letzten erfolgreichen Dumps (UTC) |
| `last_backup_size` | Dateigröße (z. B. `4,2M`) |
| `last_backup_ok` | `true` / `false` |

Die Admin-Seite (`/admin`) liest diese Werte beim Laden aus und zeigt eine farbige Statusbox:

- **Grün** — letztes Backup jünger als 24 Stunden und `last_backup_ok = true`
- **Rot** — Backup älter als 24 Stunden **oder** `last_backup_ok = false`

Zusätzlich erzeugt der Admin-Route-Handler beim Laden automatisch eine Postfach-Benachrichtigung (`audience = platform_admin`, `type = backup_failed`), wenn ein Problem vorliegt — maximal einmal pro 24 Stunden (Dedup-Check).

**Migration auf bestehender Instanz** (falls die Tabelle noch fehlt):

```bash
docker compose exec -T timescaledb \
  psql -U eeg -d eeg_platform \
  < database/migrate_20260621.sql
```

### Ebene 2 — Externer Dead-Man-Switch (healthchecks.io)

Weil ein stiller Serverausfall auch die In-App-Anzeige zum Schweigen bringt, wird zusätzlich ein serverunabhängiger Dienst eingesetzt.

#### Einrichtung (einmalig, ~5 Minuten)

1. Kostenlosen Account auf **[healthchecks.io](https://healthchecks.io)** anlegen.
2. Neuen Check erstellen:
   - **Name**: `EEG Backup Raspi`
   - **Period**: `1 day`
   - **Grace time**: `1 hour`
3. Die generierte **Ping-URL** kopieren (sieht aus wie `https://hc-ping.com/xxxxxxxx-xxxx-...`).
4. URL in `.env` auf dem Server eintragen (niemals committen!):

```bash
# .env (auf dem Server, NICHT in Git)
CHECK_URL=https://hc-ping.com/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

#### Funktionsweise

- `backup.sh` pingt `$CHECK_URL` nach jedem **erfolgreichen** Backup.
- Bei einem **Fehler** pingt das Skript `$CHECK_URL/fail` — healthchecks.io meldet das sofort.
- Bleibt der Ping **länger als 25 Stunden aus** (Period + Grace), schickt healthchecks.io eine E-Mail an die hinterlegte Adresse.
- So wird auch ein stiller Cron-Ausfall, ein Raspi-Absturz oder ein Docker-Fehler erkannt.

#### Manueller Test

```bash
# Erfolgreichen Ping simulieren
curl -fsS "$CHECK_URL"

# Fehler-Ping simulieren
curl -fsS "${CHECK_URL}/fail"
```

---

## Externe Kopie (NAS oder Hetzner Storage Box)

Damit ein Raspi-Ausfall keine Daten kostet, Dumps zusätzlich extern kopieren.

### Option A: rsync auf NAS (im Heimnetz)

```bash
# In crontab nach dem Backup-Job (z. B. 02:45 Uhr):
45 2 * * * rsync -az /opt/eeg-platform/backups/ nas.local:/backups/eeg-platform/
```

### Option B: Hetzner Storage Box per SFTP

```bash
# ~/.ssh/config eintrag für die Storage Box:
# Host storagebox
#     HostName uXXXXXX.your-storagebox.de
#     User uXXXXXX
#     IdentityFile ~/.ssh/id_ed25519_storagebox

45 2 * * * rsync -az /opt/eeg-platform/backups/ storagebox:/eeg-platform/
```

### Option C: rclone auf S3-kompatiblen Speicher

```bash
rclone copy /opt/eeg-platform/backups/ remote:eeg-backups/
```

---

## Restore

```bash
bash scripts/restore.sh backups/eeg_20260620_0230.dump
```

Das Skript fragt vor dem Überschreiben zur Bestätigung (`ja` eingeben).

**Manuell** (ohne Bestätigung, z. B. in Automatisierung):

```bash
docker compose exec -T timescaledb \
  pg_restore \
    --username=eeg \
    --dbname=eeg_platform \
    --clean --if-exists \
    --no-owner --no-privileges \
  < backups/eeg_20260620_0230.dump
```

### Einzelne Tabelle wiederherstellen

```bash
docker compose exec -T timescaledb \
  pg_restore \
    --username=eeg \
    --dbname=eeg_platform \
    --table=members \
    --data-only \
  < backups/eeg_20260620_0230.dump
```

---

## Automatisches Verify-Skript

`scripts/verify.sh` führt alle Checks durch (Stack, Backup, Restore-Probe, Schema, Statistiken, pdflatex) und protokolliert das Ergebnis:

```bash
bash scripts/verify.sh
```

Das Skript schreibt automatisch ein Restore-Testprotokoll in dieses Dokument (Abschnitt "Restore-Testprotokoll").

---

## Makefile-Kurzkommandos

```bash
make backup                              # Dump erstellen
make restore FILE=backups/eeg_....dump  # Restore mit Bestätigung
```

---

## Restore-Testprotokoll

Zuletzt getestet am — (noch nicht getestet, verify.sh auf Raspi ausführen)

---

## Was NICHT gesichert wird

| Was | Warum |
|-----|-------|
| `/opt/eeg/timescaledb/` (PostgreSQL-Volume) | Darf bei laufender DB NICHT kopiert werden → Korruption |
| `webapp/storage/uploads/` | EDA-XLSX-Rohdateien; nach Import redundant |
| `webapp/storage/pdfs/` | Jederzeit aus DB neu generierbar |
| `.env` | Kein Backup nötig; separat sicher aufbewahren (Passwort-Manager) |

---

## Schema ohne Daten sichern (für Doku/Git)

```bash
make schema
```

Generiert `docs/schema.sql` (nur Tabellenstruktur, keine Daten) und kann committed werden.
