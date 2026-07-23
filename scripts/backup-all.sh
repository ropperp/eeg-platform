#!/usr/bin/env bash
# scripts/backup-all.sh — KOMPLETTSICHERUNG in EINER Datei.
#
# Packt Datenbank UND alle Dateien in ein einziges Archiv:
#   eeg_full_JJJJMMTT_HHMM.tar.gz
#     ├── database.dump        (pg_dump custom format der eeg_platform)
#     ├── storage.tar.gz       (webapp-storage: Uploads, Beitrittserklärungen/SEPA, PDFs)
#     └── MANIFEST.txt         (Zeitpunkt, Versionen, Wiederherstellungs-Hinweis)
#
# Damit lässt sich mit scripts/restore.sh auf einem FRISCHEN Gerät alles zurückholen,
# genau wie es war. Ideal zum manuellen Herunterladen/Aufbewahren ("eine Datei, alles drin").
#
# Verwendung:  bash scripts/backup-all.sh
# Bei Fehler:  Alarm-Mail ans Admin-Postfach (scripts/backup_alert.php).

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

BACKUP_DIR="${REPO_ROOT}/backups"
STORAGE_DIR="/opt/eeg/webapp-storage"
TS="$(date +%Y%m%d_%H%M)"
FINAL="${BACKUP_DIR}/eeg_full_${TS}.tar.gz"
WORK="$(mktemp -d)"
COMPOSE="docker compose"
KEEP=8

mkdir -p "$BACKUP_DIR"
log() { echo "[backup-all $(date '+%F %T')] $*"; }
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

fail() {
    local reason="$1"
    log "FEHLER: ${reason}"
    if $COMPOSE exec -T -e ALERT_REASON="Komplettsicherung: ${reason}" -e ALERT_HOST="$(hostname)" \
         webapp php < "${REPO_ROOT}/scripts/backup_alert.php" 2>>"${BACKUP_DIR}/.alert.log"; then
        log "Alarm-Mail ausgelöst."
    else
        log "Alarm-Mail konnte NICHT gesendet werden (siehe ${BACKUP_DIR}/.alert.log)."
    fi
    exit 1
}

# 1) Datenbank
log "Sichere Datenbank ..."
$COMPOSE exec -T timescaledb pg_dump -U eeg -d eeg_platform -Fc > "${WORK}/database.dump"
[ "${PIPESTATUS[0]}" -eq 0 ] || fail "pg_dump fehlgeschlagen."
[ -s "${WORK}/database.dump" ] || fail "DB-Dump ist leer."
docker cp "${WORK}/database.dump" timescaledb:/tmp/verify_all >/dev/null 2>&1
$COMPOSE exec -T timescaledb pg_restore -l /tmp/verify_all >/dev/null 2>&1 || fail "DB-Dump nicht lesbar."
$COMPOSE exec -T timescaledb rm -f /tmp/verify_all >/dev/null 2>&1

# 2) Dateien
if [ -d "$STORAGE_DIR" ]; then
    log "Sichere Dateien (webapp-storage) ..."
    tar -czf "${WORK}/storage.tar.gz" -C "$STORAGE_DIR" --exclude='uploads/*.xlsx' . 2>/dev/null || fail "Datei-Archiv fehlgeschlagen."
else
    log "WARNUNG: ${STORAGE_DIR} nicht gefunden -- Komplettsicherung enthält nur die Datenbank."
    : > "${WORK}/storage.tar.gz"
fi

# 3) Manifest
{
    echo "EEG-Plattform Komplettsicherung"
    echo "Erstellt: $(date '+%F %T %Z') auf $(hostname)"
    echo "DB-Dump : database.dump  ($(du -h "${WORK}/database.dump" | cut -f1))"
    echo "Dateien : storage.tar.gz ($(du -h "${WORK}/storage.tar.gz" | cut -f1))"
    echo ""
    echo "Wiederherstellen:  bash scripts/restore.sh $(basename "$FINAL")"
} > "${WORK}/MANIFEST.txt"

# 4) In eine Datei packen
tar -czf "$FINAL" -C "$WORK" database.dump storage.tar.gz MANIFEST.txt || fail "Zusammenpacken fehlgeschlagen."
SIZE=$(du -sh "$FINAL" | cut -f1)
log "OK: $(basename "$FINAL") (${SIZE})"

# 5) Rotation
ls -1t "${BACKUP_DIR}"/eeg_full_*.tar.gz 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
    log "Entferne alte Komplettsicherung: $(basename "$old")"; rm -f "$old"
done
log "Fertig. Wiederherstellen mit:  bash scripts/restore.sh $(basename "$FINAL")"
