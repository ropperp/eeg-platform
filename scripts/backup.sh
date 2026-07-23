#!/usr/bin/env bash
# scripts/backup.sh — Täglicher logischer Dump der EEG-Plattform-Datenbank, mit Prüfung und
# E-Mail-Alarm ans Admin-Postfach, falls das Backup NICHT sauber durchläuft.
#
# Verwendung:
#   bash scripts/backup.sh
#   (im Cron täglich 02:00 -- siehe docs/BACKUP.md)
#
# Ablauf / Absicherung:
#   1. pg_dump (custom format) in eine temporäre Datei.
#   2. Prüfen, dass der Dump wirklich gültig ist: Exit-Code 0, Datei ausreichend groß UND
#      `pg_restore -l` listet Tabellen (fängt "0 Byte / kaputt / leer" ab).
#   3. Erst dann als finales eeg_JJJJMMTT_HHMM.dump ablegen und alte Dumps rotieren.
#   4. Bei JEDEM Fehler: laut ins Log schreiben UND eine Alarm-Mail ans Admin-Postfach senden
#      (über den vorhandenen Microsoft-Graph-Versand der Plattform, siehe backup_alert.php).
#
# Datenschutz:
#   Die Dumps enthalten echte Mitgliederdaten (Namen, IBAN, Verbrauch). NICHT in Git committen
#   (backups/ steht in .gitignore). Zusätzlich extern sichern -- siehe docs/BACKUP.md.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

BACKUP_DIR="${REPO_ROOT}/backups"
KEEP=14                                   # so viele Tages-Dumps behalten
MIN_BYTES=2000                            # kleiner => vermutlich leer/kaputt
TIMESTAMP="$(date +%Y%m%d_%H%M)"
FINAL="${BACKUP_DIR}/eeg_${TIMESTAMP}.dump"
TMP="${BACKUP_DIR}/.eeg_${TIMESTAMP}.dump.part"
COMPOSE="docker compose"

mkdir -p "$BACKUP_DIR"

log() { echo "[backup $(date '+%F %T')] $*"; }

# Schickt eine Alarm-Mail ans Admin-Postfach und beendet das Skript mit Fehlercode.
fail() {
    local reason="$1"
    log "FEHLER: ${reason}"
    rm -f "$TMP"
    # Best effort: Alarm-Mail über den webapp-Container (nutzt Mailer/Microsoft Graph aus der DB).
    # Das PHP-Skript wird per stdin in den Container gefüttert -> kein Image-Rebuild nötig.
    if $COMPOSE exec -T -e ALERT_REASON="${reason}" -e ALERT_HOST="$(hostname)" \
         webapp php < "${REPO_ROOT}/scripts/backup_alert.php" 2>>"${BACKUP_DIR}/.alert.log"; then
        log "Alarm-Mail ausgelöst."
    else
        log "Alarm-Mail konnte NICHT gesendet werden (siehe ${BACKUP_DIR}/.alert.log)."
    fi
    exit 1
}

log "Starte Dump nach $(basename "$FINAL")"

# 1) Dump erzeugen (in Temp-Datei). pg_dump-Exitcode über PIPESTATUS prüfen, nicht den von '>'.
$COMPOSE exec -T timescaledb pg_dump -U eeg -d eeg_platform -Fc > "$TMP"
if [ "${PIPESTATUS[0]}" -ne 0 ]; then
    fail "pg_dump lieferte einen Fehler (Datenbank erreichbar? Container healthy?)."
fi

# 2) Gültigkeit prüfen
if [ ! -s "$TMP" ]; then
    fail "Dump-Datei ist leer (0 Byte)."
fi
BYTES=$(stat -c%s "$TMP" 2>/dev/null || echo 0)
if [ "$BYTES" -lt "$MIN_BYTES" ]; then
    fail "Dump verdächtig klein (${BYTES} Byte < ${MIN_BYTES}). Vermutlich unvollständig."
fi
if ! $COMPOSE exec -T timescaledb pg_restore -l /dev/stdin < "$TMP" >/dev/null 2>&1; then
    fail "Dump ist nicht lesbar (pg_restore -l fehlgeschlagen -> beschädigt)."
fi

# 3) Übernehmen + rotieren
mv "$TMP" "$FINAL"
SIZE=$(du -sh "$FINAL" | cut -f1)
log "OK: $(basename "$FINAL") (${SIZE})"

# Alte Dumps aufräumen (die neuesten $KEEP behalten)
ls -1t "${BACKUP_DIR}"/eeg_*.dump 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
    log "Entferne alten Dump: $(basename "$old")"
    rm -f "$old"
done

log "Fertig."
