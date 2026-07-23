#!/usr/bin/env bash
# scripts/sync-to-nas.sh — Kopiert alle lokalen Backups (DB-Dump + Storage-Archiv) per rsync
# über SSH auf die Synology NAS.
#
# Funktioniert unabhängig davon, WO der EEG-Server steht (Raspberry Pi im selben Heimnetz wie
# die NAS, oder später ein gehosteter Server z. B. bei Hetzner) -- solange NAS_HOST erreichbar
# ist. Im selben Heimnetz reicht die lokale IP/Hostname der NAS. Für einen extern gehosteten
# Server (Server steht NICHT im selben Netz wie die NAS) NAS_HOST auf die Tailscale-IP/den
# Tailscale-Hostnamen der NAS setzen, siehe docs/BACKUP.md, Abschnitt "Server extern gehostet".
#
# Verwendung:
#   NAS_HOST=192.168.1.50 NAS_USER=eeg-backup NAS_PATH=/volume1/eeg-backup bash scripts/sync-to-nas.sh
# Oder die Variablen unten direkt fest eintragen und ohne Prefix aufrufen.
#
# Setzt voraus:
#   - SSH-Key-Login zur NAS eingerichtet (kein Passwort-Prompt im Cron-Job!):
#       ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_nas -N ""
#       ssh-copy-id -i ~/.ssh/id_ed25519_nas.pub NAS_USER@NAS_HOST
#   - rsync auf dem EEG-Server installiert (bei Debian/Raspberry Pi OS meist vorinstalliert)

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

NAS_HOST="${NAS_HOST:-HIER_NAS_HOSTNAME_ODER_IP}"
NAS_USER="${NAS_USER:-HIER_NAS_BENUTZER}"
NAS_PATH="${NAS_PATH:-/volume1/eeg-backup}"
NAS_SSH_KEY="${NAS_SSH_KEY:-$HOME/.ssh/id_ed25519_nas}"

BACKUP_DIR="${REPO_ROOT}/backups"
COMPOSE="docker compose"

# Bei Fehler eine Alarm-Mail ans Admin-Postfach senden (die externe NAS-Kopie ist der eigentliche
# Katastrophenschutz -- fällt sie unbemerkt aus, liegt am Ende alles nur auf dem Pi).
fail() {
    local reason="$1"
    echo "[sync-to-nas] FEHLER: ${reason}"
    ( cd "$REPO_ROOT" && $COMPOSE exec -T -e ALERT_REASON="NAS-Sync: ${reason}" -e ALERT_HOST="$(hostname)" \
        webapp php < "${REPO_ROOT}/scripts/backup_alert.php" 2>>"${BACKUP_DIR}/.alert.log" ) \
      && echo "[sync-to-nas] Alarm-Mail ausgelöst." \
      || echo "[sync-to-nas] Alarm-Mail konnte NICHT gesendet werden."
    exit 1
}

if [[ "${NAS_HOST}" == "HIER_NAS_HOSTNAME_ODER_IP" ]]; then
  fail "NAS_HOST nicht gesetzt (NAS_HOST/NAS_USER/NAS_PATH als Env-Variablen mitgeben)."
fi

if [[ ! -d "${BACKUP_DIR}" ]] || [[ -z "$(ls -A "${BACKUP_DIR}" 2>/dev/null)" ]]; then
  fail "Keine Backups in ${BACKUP_DIR} gefunden -- erst backup.sh/backup-storage.sh ausführen."
fi

echo "[sync-to-nas] Synchronisiere ${BACKUP_DIR}/ -> ${NAS_USER}@${NAS_HOST}:${NAS_PATH}/"

if ! rsync -avz \
      -e "ssh -i ${NAS_SSH_KEY} -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=20" \
      "${BACKUP_DIR}/" \
      "${NAS_USER}@${NAS_HOST}:${NAS_PATH}/"; then
  fail "rsync zur NAS fehlgeschlagen (NAS erreichbar? SSH-Key gültig? Pfad vorhanden?)."
fi

echo "[sync-to-nas] Fertig."
