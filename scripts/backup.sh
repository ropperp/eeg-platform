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
# Gültigkeit: den Dump zum Prüfen in den Container kopieren. pg_restore -l braucht eine
# SEEKBARE Datei -- über eine Pipe/stdin schlägt es bei custom-format (-Fc) immer fehl.
docker cp "$TMP" timescaledb:/tmp/verify_dump >/dev/null 2>&1
if ! $COMPOSE exec -T timescaledb pg_restore -l /tmp/verify_dump >/dev/null 2>&1; then
    $COMPOSE exec -T timescaledb rm -f /tmp/verify_dump >/dev/null 2>&1
    fail "Dump ist nicht lesbar (pg_restore -l fehlgeschlagen -> beschädigt)."
fi
$COMPOSE exec -T timescaledb rm -f /tmp/verify_dump >/dev/null 2>&1

# 3) Übernehmen + rotieren
mv "$TMP" "$FINAL"
SIZE=$(du -sh "$FINAL" | cut -f1)
log "OK: $(basename "$FINAL") (${SIZE})"

# ─────────────────────────────────────────────────────────────────────────────
# 4) ZUSÄTZLICH: getrennter Stammdaten-Dump (Mitglieder, Rechnungen, Verträge,
#    Zählpunkte, Konfiguration ...) OHNE die Messwert-Hypertables.
#
#    Warum getrennt? Die Messwerte (esp_measurements/eda_measurements) wachsen mit jeder
#    Sekunden-/Viertelstundenmessung stark an, die Stammdaten bleiben klein. Mit diesem
#    zweiten Dump lassen sich Mitglieder-/Abrechnungsdaten wiederherstellen, OHNE die
#    riesigen Messwerte mitzuschleppen -- und umgekehrt bleibt der Verlust von Messwerten
#    verkraftbar, ohne die Mitgliederdaten zu gefährden.
#
#    Wichtig/technisch: esp_measurements und eda_measurements sind TimescaleDB-*Hypertables*.
#    Deren echte Daten liegen in Chunk-Tabellen unter _timescaledb_internal, weshalb sich
#    Hypertables NICHT zuverlässig einzeln per `pg_dump -t` sichern lassen. Deshalb:
#      * Messwerte  -> stecken im vollständigen Dump oben (eeg_*.dump)
#      * Stammdaten -> zusätzlich hier als eigener, kleiner Dump (eeg_stamm_*.dump)
#    Die Tabellenliste wird dynamisch ermittelt (alle public-Tabellen minus Hypertables),
#    damit sie beim Erweitern des Schemas nicht veraltet.
# ─────────────────────────────────────────────────────────────────────────────
STAMM_FINAL="${BACKUP_DIR}/eeg_stamm_${TIMESTAMP}.dump"
STAMM_TMP="${BACKUP_DIR}/.eeg_stamm_${TIMESTAMP}.dump.part"

TABLE_ARGS="$($COMPOSE exec -T timescaledb psql -U eeg -d eeg_platform -At -c "
    SELECT string_agg('-t public.' || quote_ident(tablename), ' ' ORDER BY tablename)
      FROM pg_tables
     WHERE schemaname = 'public'
       AND tablename NOT IN (
           SELECT hypertable_name FROM timescaledb_information.hypertables
            WHERE hypertable_schema = 'public'
       );" 2>/dev/null | tr -d '\r')"

if [ -z "$TABLE_ARGS" ]; then
    # Kein harter Abbruch: der vollständige Dump oben ist bereits gesichert. Aber melden,
    # denn ohne Stammdaten-Dump fehlt die schnelle "nur Mitgliederdaten"-Wiederherstellung.
    log "WARNUNG: Tabellenliste für den Stammdaten-Dump konnte nicht ermittelt werden -- übersprungen."
else
    # shellcheck disable=SC2086
    $COMPOSE exec -T timescaledb pg_dump -U eeg -d eeg_platform -Fc $TABLE_ARGS > "$STAMM_TMP"
    if [ "${PIPESTATUS[0]}" -ne 0 ]; then
        rm -f "$STAMM_TMP"
        fail "Stammdaten-Dump (Mitglieder/Rechnungen ohne Messwerte) fehlgeschlagen."
    fi
    STAMM_BYTES=$(stat -c%s "$STAMM_TMP" 2>/dev/null || echo 0)
    if [ "$STAMM_BYTES" -lt "$MIN_BYTES" ]; then
        rm -f "$STAMM_TMP"
        fail "Stammdaten-Dump verdächtig klein (${STAMM_BYTES} Byte). Vermutlich unvollständig."
    fi
    docker cp "$STAMM_TMP" timescaledb:/tmp/verify_stamm >/dev/null 2>&1
    if ! $COMPOSE exec -T timescaledb pg_restore -l /tmp/verify_stamm >/dev/null 2>&1; then
        $COMPOSE exec -T timescaledb rm -f /tmp/verify_stamm >/dev/null 2>&1
        rm -f "$STAMM_TMP"
        fail "Stammdaten-Dump ist nicht lesbar (pg_restore -l fehlgeschlagen -> beschädigt)."
    fi
    $COMPOSE exec -T timescaledb rm -f /tmp/verify_stamm >/dev/null 2>&1
    mv "$STAMM_TMP" "$STAMM_FINAL"
    log "OK: $(basename "$STAMM_FINAL") ($(du -sh "$STAMM_FINAL" | cut -f1), nur Stammdaten)"
fi

# Alte Dumps aufräumen (die neuesten $KEEP je Art behalten)
for pattern in 'eeg_2*.dump' 'eeg_stamm_*.dump'; do
    ls -1t "${BACKUP_DIR}"/$pattern 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
        log "Entferne alten Dump: $(basename "$old")"
        rm -f "$old"
    done
done

# Statusdatei für die Backup-Übersicht im Admin-Bereich (/admin/backups). Wird bei JEDEM
# erfolgreichen Lauf neu geschrieben -- fehlt sie oder ist sie alt, war das letzte Backup nicht
# erfolgreich (die Admin-Seite warnt dann sichtbar).
cat > "${BACKUP_DIR}/last_backup.json" <<EOF
{
  "zeitpunkt": "$(date '+%Y-%m-%d %H:%M:%S')",
  "unix": $(date +%s),
  "voll_datei": "$(basename "$FINAL")",
  "voll_bytes": $(stat -c%s "$FINAL" 2>/dev/null || echo 0),
  "stamm_datei": "$( [ -f "$STAMM_FINAL" ] && basename "$STAMM_FINAL" || echo '' )",
  "stamm_bytes": $( [ -f "$STAMM_FINAL" ] && stat -c%s "$STAMM_FINAL" || echo 0 ),
  "host": "$(hostname)"
}
EOF

log "Fertig."
