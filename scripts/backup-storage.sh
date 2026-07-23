#!/usr/bin/env bash
# scripts/backup-storage.sh — Archiv der unwiederbringlichen Dateien der Plattform:
# Uploads (Ausweis-Scans, Beitrittserklärungen = zugleich SEPA-Mandate, Profilbilder) UND die
# generierten Vertrags-/Rechnungs-PDFs. Diese Dateien liegen NUR auf der Platte und lassen sich
# durch keinen DB-Restore wiederherstellen -- deshalb ergänzt dieses Skript scripts/backup.sh.
#
# Verwendung:
#   bash scripts/backup-storage.sh   (im Cron täglich 02:05 -- siehe docs/BACKUP.md)
#
# Absicherung:
#   - Sichert /opt/eeg/webapp-storage KOMPLETT (uploads/ inkl. members, avatars; pdfs/; ...),
#     bis auf die redundanten EDA-XLSX-Rohdateien (nach dem Import überflüssig).
#   - Prüft, dass das Archiv wirklich Dateien enthält (fängt das frühere "45-Byte-leer"-Problem).
#   - Bei JEDEM Fehler: Alarm-Mail ans Admin-Postfach (scripts/backup_alert.php).

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

STORAGE_DIR="/opt/eeg/webapp-storage"
BACKUP_DIR="${REPO_ROOT}/backups"
KEEP=14
TIMESTAMP="$(date +%Y%m%d_%H%M)"
FINAL="${BACKUP_DIR}/storage_${TIMESTAMP}.tar.gz"
TMP="${BACKUP_DIR}/.storage_${TIMESTAMP}.tar.gz.part"
COMPOSE="docker compose"

mkdir -p "$BACKUP_DIR"
log() { echo "[backup-storage $(date '+%F %T')] $*"; }

fail() {
    local reason="$1"
    log "FEHLER: ${reason}"
    rm -f "$TMP"
    if $COMPOSE exec -T -e ALERT_REASON="Datei-Backup: ${reason}" -e ALERT_HOST="$(hostname)" \
         webapp php < "${REPO_ROOT}/scripts/backup_alert.php" 2>>"${BACKUP_DIR}/.alert.log"; then
        log "Alarm-Mail ausgelöst."
    else
        log "Alarm-Mail konnte NICHT gesendet werden (siehe ${BACKUP_DIR}/.alert.log)."
    fi
    exit 1
}

[ -d "$STORAGE_DIR" ] || fail "${STORAGE_DIR} nicht gefunden -- falscher Host?"

# Gibt es überhaupt zu sichernde Dateien (ohne die redundanten EDA-XLSX)?
SRC_FILES=$(find "$STORAGE_DIR" -type f -not -name '*.xlsx' 2>/dev/null | wc -l)
if [ "$SRC_FILES" -eq 0 ]; then
    log "Keine sicherungswürdigen Dateien in ${STORAGE_DIR} (noch keine Uploads/PDFs) -- übersprungen."
    exit 0
fi

log "Archiviere ${STORAGE_DIR} (${SRC_FILES} Dateien, ohne EDA-XLSX) ..."
tar -czf "$TMP" -C "$STORAGE_DIR" --exclude='uploads/*.xlsx' . 2>/dev/null
[ "${PIPESTATUS[0]}" -eq 0 ] || fail "tar lieferte einen Fehler."

# Prüfen: Archiv lesbar UND enthält Dateien (nicht das frühere 45-Byte-Leer-Archiv)
if ! gzip -t "$TMP" 2>/dev/null; then
    fail "Archiv ist beschädigt (gzip -t fehlgeschlagen)."
fi
ARCHIVED=$(tar -tzf "$TMP" 2>/dev/null | grep -c -v '/$' || true)
if [ "$ARCHIVED" -eq 0 ]; then
    fail "Archiv enthält KEINE Dateien, obwohl ${SRC_FILES} vorhanden sind."
fi

mv "$TMP" "$FINAL"
SIZE=$(du -sh "$FINAL" | cut -f1)
log "OK: $(basename "$FINAL") (${SIZE}, ${ARCHIVED} Dateien)"

# Rotation: die neuesten $KEEP behalten
ls -1t "${BACKUP_DIR}"/storage_*.tar.gz 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
    log "Entferne altes Archiv: $(basename "$old")"
    rm -f "$old"
done

log "Fertig."
