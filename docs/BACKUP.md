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

## Uploads sichern (Avatare, Mitglieder-Dateien)

```bash
bash scripts/backup-storage.sh
```

Erzeugt: `backups/storage_YYYYMMDD_HHMM.tar.gz` (nur `uploads/avatars/` + `uploads/members/`,
die EDA-XLSX-Rohdateien werden bewusst ausgelassen). Muss auf dem Host laufen (nicht im
Container), da nur dort `/opt/eeg/webapp-storage` gemountet ist.

Beides zusammen: `make backup-all` (DB-Dump + Storage-Archiv).

---

## Automatisches Backup via Cron (täglich 02:30 Uhr)

Auf dem Raspberry Pi als root oder als Deploy-User (der Docker-Zugriff hat):

```bash
crontab -e
```

Eintrag (DB-Dump + Uploads):

```
30 2 * * * cd /opt/eeg-platform && bash scripts/backup.sh >> /var/log/eeg-backup.log 2>&1
35 2 * * * cd /opt/eeg-platform && bash scripts/backup-storage.sh >> /var/log/eeg-backup.log 2>&1
```

Log prüfen:

```bash
tail -20 /var/log/eeg-backup.log
ls -lh backups/
```

---

## Externe Kopie (NAS oder Hetzner Storage Box)

Damit ein Raspi-Ausfall keine Daten kostet, Backups (DB-Dump UND Storage-Archiv, siehe oben)
zusätzlich extern kopieren.

### Option A: rsync auf Synology NAS (`scripts/sync-to-nas.sh`)

Einmalig SSH-Key-Login zur NAS einrichten (kein Passwort-Prompt im Cron-Job):

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_nas -N ""
ssh-copy-id -i ~/.ssh/id_ed25519_nas.pub NAS_BENUTZER@NAS_HOST
```

Voraussetzung auf der Synology: Systemsteuerung → Terminal & SNMP → SSH-Dienst aktivieren.

Danach in crontab, nach den Backup-Jobs (z. B. 02:45 Uhr):

```
45 2 * * * cd /opt/eeg-platform && NAS_HOST=192.168.1.50 NAS_USER=eeg-backup NAS_PATH=/volume1/eeg-backup bash scripts/sync-to-nas.sh >> /var/log/eeg-backup.log 2>&1
```

Oder manuell testen: `make sync-nas NAS_HOST=192.168.1.50 NAS_USER=eeg-backup`

#### Server extern gehostet (nicht im selben Netz wie die NAS)

`scripts/sync-to-nas.sh` funktioniert unverändert, unabhängig davon, wo der EEG-Server läuft --
`NAS_HOST` muss nur erreichbar sein. Steht der Server (z. B. später bei Hetzner, siehe
`SETUP.md` → „Umzug auf anderen Server") NICHT im selben Netz wie die NAS, gibt es zwei Wege:

- **Empfohlen: Tailscale** (oder ein anderes WireGuard-basiertes Mesh-VPN). Kostenloses
  Synology-Paket im Package Center, zusätzlich `tailscale` auf dem EEG-Server installieren
  (`curl -fsSL https://tailscale.com/install.sh | sh`). Beide Geräte bekommen eine feste
  `100.x.x.x`-Adresse im Tailscale-Netz, die NAS ist darüber erreichbar OHNE Portfreigabe im
  Router -- `NAS_HOST` einfach auf diese Tailscale-IP (oder den Tailscale-Hostnamen) setzen.
  Kein offener SSH-Port nach außen nötig, funktioniert auch hinter CGNAT/wechselnder IP.
- **Alternative: Port-Forwarding auf der Fritzbox/dem Router** direkt auf den SSH-Port der
  NAS. Funktioniert, exponiert aber SSH direkt ins Internet -- nur mit Key-Login (kein
  Passwort-Login), non-Standard-Port und idealerweise Fail2ban/Login-Sperre auf der Synology
  vertretbar. Bei einer festen IP oder DynDNS auf die NAS zusätzlich nötig.

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

### Uploads wiederherstellen (Avatare, Mitglieder-Dateien)

Der DB-Restore allein reicht NICHT für eine vollständige Wiederherstellung -- die Dateien
selbst (Profilbilder, Ausweis-Scans, Beitrittserklärungen) liegen nur im Storage-Archiv:

```bash
# Erst sicherstellen, dass das Zielverzeichnis existiert und die richtigen Rechte hat
# (siehe SETUP.md Schritt 3 -- 82:82, sonst kann PHP nach dem Restore nicht mehr schreiben):
sudo mkdir -p /opt/eeg/webapp-storage
tar -xzf backups/storage_20260716_1530.tar.gz -C /opt/eeg/webapp-storage
sudo chown -R 82:82 /opt/eeg/webapp-storage
```

Erst danach ist ein Wiederanlauf "wie zuvor" (alle Rechnungen, Verträge, Avatare,
Beitrittserklärungen und Mitgliederdaten) tatsächlich vollständig -- DB-Restore und
Storage-Restore gehören für eine echte Katastrophen-Wiederherstellung immer zusammen.

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
make backup                                              # Dump erstellen
make backup-storage                                      # Uploads (Avatare/Mitglieder-Dateien) archivieren
make backup-all                                           # Beides zusammen
make sync-nas NAS_HOST=... NAS_USER=... [NAS_PATH=...]   # Auf Synology NAS kopieren
make restore FILE=backups/eeg_....dump                    # Restore mit Bestätigung
```

---

## Restore-Testprotokoll

Zuletzt getestet am — (noch nicht getestet, verify.sh auf Raspi ausführen)

---

## Was NICHT gesichert wird

> **Korrektur 16.07.2026:** Diese Tabelle behauptete bislang pauschal, `webapp/storage/uploads/`
> sei komplett redundant. Das stimmt seit der Verträge/Dateien-Migration (14.07.2026) NICHT
> mehr -- dort liegen jetzt auch Profilbilder (`uploads/avatars/`) und von Mitgliedern/Managern
> hochgeladene Dokumente (`uploads/members/<id>/` -- Ausweis-Scans, Beitrittserklärungen).
> Diese Dateien existieren NUR auf der Platte und lassen sich durch keinen DB-Restore
> wiederherstellen. Siehe `scripts/backup-storage.sh` weiter unten.

| Was | Warum |
|-----|-------|
| `/opt/eeg/timescaledb/` (PostgreSQL-Volume) | Darf bei laufender DB NICHT kopiert werden → Korruption. Stattdessen `pg_dump` (`scripts/backup.sh`). |
| `webapp/storage/uploads/*.xlsx` (direkt in `uploads/`, nicht in Unterordnern) | EDA-XLSX-Rohdateien aus dem Import; nach dem Import redundant |
| `webapp/storage/pdfs/` | Aktuell ungenutzt -- Vertrags-PDFs werden bei Bedarf live generiert und nie auf die Platte geschrieben |
| `.env` | Kein Backup im selben Repo/NAS-Ordner; separat sicher aufbewahren (Passwort-Manager), da er DB-Passwort, APP_SECRET, SMTP-Zugangsdaten enthält |

**Wird jetzt zusätzlich gesichert** (`scripts/backup-storage.sh`, siehe unten):

| Was | Warum |
|-----|-------|
| `webapp/storage/uploads/avatars/` | Profilbilder -- vom Nutzer hochgeladen, nicht regenerierbar |
| `webapp/storage/uploads/members/<id>/` | Ausweis-Scans, Beitrittserklärungen, sonstige Mitglieder-Uploads -- nicht regenerierbar |

---

## Schema ohne Daten sichern (für Doku/Git)

```bash
make schema
```

Generiert `docs/schema.sql` (nur Tabellenstruktur, keine Daten) und kann committed werden.
