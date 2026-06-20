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

## Makefile-Kurzkommandos

```bash
make backup                              # Dump erstellen
make restore FILE=backups/eeg_....dump  # Restore mit Bestätigung
```

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
