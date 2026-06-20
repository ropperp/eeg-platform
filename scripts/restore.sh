#!/usr/bin/env bash
# scripts/restore.sh — Datenbank aus einem custom-Format-Dump wiederherstellen
#
# Verwendung:
#   bash scripts/restore.sh backups/eeg_20260620_0230.dump
#
# WARNUNG:
#   --clean löscht alle vorhandenen Objekte in der DB bevor sie neu eingespielt werden.
#   Dies ist DESTRUKTIV. Nur aufrufen, wenn ein sauberes Restore gewünscht ist.
#
# Setzt voraus:
#   - Docker Compose Stack läuft (timescaledb container healthy)
#   - Aufruf aus dem Repo-Root-Verzeichnis

set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Verwendung: bash scripts/restore.sh <dump-datei>"
  echo "Beispiel:   bash scripts/restore.sh backups/eeg_20260620_0230.dump"
  exit 1
fi

DUMP_FILE="$1"

if [[ ! -f "${DUMP_FILE}" ]]; then
  echo "[restore] FEHLER: Datei nicht gefunden: ${DUMP_FILE}"
  exit 1
fi

echo "[restore] WARNUNG: Die Datenbank wird vollständig überschrieben!"
echo "[restore] Dump: ${DUMP_FILE}"
read -r -p "Fortfahren? (ja/NEIN): " CONFIRM

if [[ "${CONFIRM}" != "ja" ]]; then
  echo "[restore] Abgebrochen."
  exit 0
fi

echo "[restore] Starte Restore..."

docker compose exec -T timescaledb \
  pg_restore \
    --username=eeg \
    --dbname=eeg_platform \
    --clean \
    --if-exists \
    --no-owner \
    --no-privileges \
  < "${DUMP_FILE}"

echo "[restore] Fertig."
