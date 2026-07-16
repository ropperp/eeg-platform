#!/usr/bin/env bash
# scripts/backup-storage.sh — Archiv der unwiederbringlichen Uploads (Avatare,
# Mitglieder-Dateien: Ausweis-Scans, Beitrittserklärungen etc.)
#
# Verwendung:
#   bash scripts/backup-storage.sh
#
# Ergänzt scripts/backup.sh (nur DB-Dump) um die Dateien, die NUR auf der Platte liegen und
# durch keinen DB-Restore wiederhergestellt werden können.
#
# Setzt voraus:
#   - Aufruf auf dem Host, auf dem /opt/eeg/webapp-storage gemountet ist (nicht im Container)
#   - Aufruf aus dem Repo-Root-Verzeichnis
#
# Datenschutz:
#   Das Archiv enthält echte Mitgliederdaten (Ausweis-Scans, Profilbilder). NICHT in Git,
#   backups/ steht in .gitignore. Extern sichern -- siehe docs/BACKUP.md.

set -euo pipefail

STORAGE_DIR="/opt/eeg/webapp-storage"
BACKUP_DIR="$(dirname "$0")/../backups"
TIMESTAMP="$(date +%Y%m%d_%H%M)"
FILENAME="storage_${TIMESTAMP}.tar.gz"
FILEPATH="${BACKUP_DIR}/${FILENAME}"

mkdir -p "${BACKUP_DIR}"

if [[ ! -d "${STORAGE_DIR}" ]]; then
  echo "[backup-storage] FEHLER: ${STORAGE_DIR} nicht gefunden -- läuft dieses Skript auf dem falschen Host?"
  exit 1
fi

echo "[backup-storage] Archiviere ${STORAGE_DIR}/uploads/{avatars,members} ..."

# Nur die unwiederbringlichen Unterordner sichern -- die direkt in uploads/ liegenden
# EDA-XLSX-Rohdateien (uniqid()_dateiname.xlsx, aus dem EDA-Import) sind nach dem Import
# redundant und werden bewusst ausgelassen, um das Archiv klein zu halten.
tar -czf "${FILEPATH}" \
  -C "${STORAGE_DIR}" \
  --ignore-failed-read \
  uploads/avatars uploads/members 2>/dev/null || true

if [[ ! -s "${FILEPATH}" ]]; then
  echo "[backup-storage] WARNUNG: Archiv leer oder fehlgeschlagen (noch keine Uploads vorhanden?)."
  exit 0
fi

SIZE=$(du -sh "${FILEPATH}" | cut -f1)
echo "[backup-storage] Fertig: ${FILEPATH} (${SIZE})"
