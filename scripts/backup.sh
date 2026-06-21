#!/usr/bin/env bash
# scripts/backup.sh — Logischer Dump der EEG-Plattform-Datenbank
#
# Verwendung:
#   bash scripts/backup.sh
#
# Umgebungsvariablen (aus .env oder Cron-Environment):
#   CHECK_URL   Healthchecks.io Ping-URL (optional, Dead-Man-Switch).
#               Wird automatisch aus .env gelesen wenn nicht gesetzt.
#
# Datenschutz:
#   Die erzeugten Dump-Dateien enthalten echte Mitgliederdaten (Namen, IBAN, Verbrauch).
#   Sie DÜRFEN NICHT in Git committet werden. backups/ steht in .gitignore.
#   Dumps zusätzlich extern sichern (NAS, Storage Box) — siehe docs/BACKUP.md.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="${REPO_ROOT}/backups"
TIMESTAMP="$(date +%Y%m%d_%H%M)"
FILENAME="eeg_${TIMESTAMP}.dump"
FILEPATH="${BACKUP_DIR}/${FILENAME}"

# CHECK_URL aus .env laden, falls nicht bereits im Cron-Environment
if [[ -z "${CHECK_URL:-}" && -f "${REPO_ROOT}/.env" ]]; then
    CHECK_URL="$(grep -E '^CHECK_URL=' "${REPO_ROOT}/.env" | head -1 | cut -d= -f2- | sed "s/^['\"]//;s/['\"]$//")" 2>/dev/null || true
fi

# ─── Status in system_status schreiben (non-fatal) ──────────────────────────
_db_set() {
    local key="${1//\'/\'\'}"
    local val="${2//\'/\'\'}"
    docker compose -f "${REPO_ROOT}/docker-compose.yml" exec -T timescaledb \
        psql -U eeg -d eeg_platform \
        -c "INSERT INTO system_status(key,value,updated_at) VALUES('${key}','${val}',now()) ON CONFLICT(key) DO UPDATE SET value='${val}',updated_at=now();" \
        > /dev/null 2>&1 \
        || echo "[backup] Warnung: DB-Status '${1}' konnte nicht geschrieben werden" >&2
}

# ─── Fehler-Handler: DB-Status + Dead-Man-Switch-Fail-Ping ──────────────────
_on_error() {
    echo "[backup] FEHLER — Backup fehlgeschlagen (Zeile ${BASH_LINENO[0]})" >&2
    [[ -f "${FILEPATH:-}" ]] && { rm -f "${FILEPATH}"; echo "[backup] Unvollständige Dump-Datei entfernt" >&2; } || true
    _db_set "last_backup_ok" "false"
    if [[ -n "${CHECK_URL:-}" ]]; then
        curl -fsS --retry 3 --max-time 10 "${CHECK_URL}/fail" > /dev/null 2>&1 \
            && echo "[backup] Healthcheck FAIL-Ping gesendet" >&2 \
            || true
    fi
}
trap '_on_error' ERR

# ─── Backup durchführen ──────────────────────────────────────────────────────
mkdir -p "${BACKUP_DIR}"

echo "[backup] Starte Dump: ${FILENAME}"

docker compose -f "${REPO_ROOT}/docker-compose.yml" exec -T timescaledb \
    pg_dump -U eeg -d eeg_platform -Fc \
    > "${FILEPATH}"

SIZE="$(du -sh "${FILEPATH}" | cut -f1)"
echo "[backup] Fertig: ${FILEPATH} (${SIZE})"

# ─── Erfolgs-Status in DB schreiben ─────────────────────────────────────────
_db_set "last_backup_at"   "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
_db_set "last_backup_size" "${SIZE}"
_db_set "last_backup_ok"   "true"
echo "[backup] DB-Status aktualisiert"

# ─── Dead-Man-Switch: healthchecks.io anpingen ──────────────────────────────
if [[ -n "${CHECK_URL:-}" ]]; then
    curl -fsS --retry 3 --max-time 10 "${CHECK_URL}" > /dev/null \
        && echo "[backup] Dead-Man-Switch gepingt (${CHECK_URL})" \
        || echo "[backup] Warnung: Healthcheck-Ping fehlgeschlagen" >&2
fi

echo "[backup] Alles erledigt."
