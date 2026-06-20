#!/usr/bin/env bash
# scripts/backup.sh — Logischer Dump der EEG-Plattform-Datenbank
#
# Verwendung:
#   bash scripts/backup.sh
#
# Setzt voraus:
#   - Docker Compose Stack läuft (timescaledb container healthy)
#   - Aufruf aus dem Repo-Root-Verzeichnis
#
# Datenschutz:
#   Die erzeugten Dump-Dateien enthalten echte Mitgliederdaten (Namen, IBAN, Verbrauch).
#   Sie DÜRFEN NICHT in Git committet werden. backups/ steht in .gitignore.
#   Dumps zusätzlich extern sichern (NAS, Storage Box) — siehe docs/BACKUP.md.

set -euo pipefail

BACKUP_DIR="$(dirname "$0")/../backups"
TIMESTAMP="$(date +%Y%m%d_%H%M)"
FILENAME="eeg_${TIMESTAMP}.dump"
FILEPATH="${BACKUP_DIR}/${FILENAME}"

mkdir -p "${BACKUP_DIR}"

echo "[backup] Starte Dump: ${FILENAME}"

docker compose exec -T timescaledb \
  pg_dump -U eeg -d eeg_platform -Fc \
  > "${FILEPATH}"

SIZE=$(du -sh "${FILEPATH}" | cut -f1)
echo "[backup] Fertig: ${FILEPATH} (${SIZE})"
