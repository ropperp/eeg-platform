#!/usr/bin/env bash
# scripts/restore.sh — Wiederherstellung der Plattform aus einem Backup.
#
# Versteht ZWEI Eingaben:
#   1. Komplettsicherung  eeg_full_JJJJMMTT_HHMM.tar.gz  (aus scripts/backup-all.sh)
#         -> stellt Datenbank UND alle Dateien wieder her.
#   2. Reiner DB-Dump     eeg_JJJJMMTT_HHMM.dump         (aus scripts/backup.sh)
#         -> stellt nur die Datenbank wieder her.
#
# Verwendung:
#   bash scripts/restore.sh backups/eeg_full_20260723_1900.tar.gz
#   bash scripts/restore.sh backups/eeg_20260723_1631.dump
#
# Voraussetzung: Docker-Stack läuft mindestens mit timescaledb (`docker compose up -d`).
# ACHTUNG: überschreibt die aktuelle Datenbank eeg_platform (und ggf. die Dateien).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

SRC="${1:-}"
STORAGE_DIR="/opt/eeg/webapp-storage"
COMPOSE="docker compose"

if [ -z "$SRC" ] || [ ! -f "$SRC" ]; then
    echo "Verwendung: bash scripts/restore.sh <eeg_full_....tar.gz | eeg_....dump>"
    echo "Vorhandene Backups (neueste zuerst):"
    ls -1t backups/eeg_full_*.tar.gz backups/eeg_*.dump 2>/dev/null | head || echo "  (keine gefunden)"
    exit 1
fi

echo "=========================================================="
echo " WIEDERHERSTELLUNG aus: $SRC"
echo " Das ÜBERSCHREIBT die aktuelle Datenbank eeg_platform!"
echo "=========================================================="
read -r -p "Wirklich fortfahren? Tippe 'JA': " ok
[ "$ok" = "JA" ] || { echo "Abgebrochen."; exit 1; }

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
DBDUMP=""; STORAGE=""

case "$SRC" in
    *.tar.gz)
        echo "[restore] Entpacke Komplettsicherung ..."
        tar -xzf "$SRC" -C "$WORK"
        [ -f "${WORK}/database.dump" ] || { echo "[restore] FEHLER: database.dump fehlt im Archiv."; exit 1; }
        DBDUMP="${WORK}/database.dump"
        [ -s "${WORK}/storage.tar.gz" ] && STORAGE="${WORK}/storage.tar.gz"
        [ -f "${WORK}/MANIFEST.txt" ] && { echo "----- MANIFEST -----"; cat "${WORK}/MANIFEST.txt"; echo "--------------------"; }
        ;;
    *)
        DBDUMP="$SRC"   # reiner DB-Dump
        ;;
esac

# 1) Datenbank
echo "[restore] Warte auf timescaledb ..."
until $COMPOSE exec -T timescaledb pg_isready -U eeg >/dev/null 2>&1; do sleep 2; done
echo "[restore] Spiele Datenbank ein (eeg_platform wird neu angelegt) ..."
docker cp "$DBDUMP" timescaledb:/tmp/restore.dump
$COMPOSE exec -T timescaledb psql -U eeg -d postgres -c \
  "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='eeg_platform';" >/dev/null
$COMPOSE exec -T timescaledb psql -U eeg -d postgres -c "DROP DATABASE IF EXISTS eeg_platform;" >/dev/null
$COMPOSE exec -T timescaledb psql -U eeg -d postgres -c "CREATE DATABASE eeg_platform OWNER eeg;" >/dev/null
# TimescaleDB-Restore-Verfahren (Hypertables/Catalog): pre_restore -> pg_restore -> post_restore
$COMPOSE exec -T timescaledb psql -U eeg -d eeg_platform -c "CREATE EXTENSION IF NOT EXISTS timescaledb;" >/dev/null
$COMPOSE exec -T timescaledb psql -U eeg -d eeg_platform -c "SELECT timescaledb_pre_restore();" >/dev/null
$COMPOSE exec -T timescaledb pg_restore -U eeg -d eeg_platform --no-owner /tmp/restore.dump || true
$COMPOSE exec -T timescaledb psql -U eeg -d eeg_platform -c "SELECT timescaledb_post_restore();" >/dev/null
$COMPOSE exec -T timescaledb rm -f /tmp/restore.dump >/dev/null 2>&1 || true

# 2) Dateien (nur bei Komplettsicherung)
if [ -n "$STORAGE" ]; then
    echo "[restore] Stelle Dateien wieder her -> ${STORAGE_DIR} ..."
    sudo mkdir -p "$STORAGE_DIR"
    sudo tar -xzf "$STORAGE" -C "$STORAGE_DIR"
    sudo chown -R 82:82 "$STORAGE_DIR"     # www-data (UID 82) im Alpine-Webapp-Image
else
    echo "[restore] Hinweis: reiner DB-Dump -> Dateien (Uploads/PDFs) NICHT wiederhergestellt."
fi

# 3) Kontrolle
echo "[restore] Kontrolle:"
$COMPOSE exec -T timescaledb psql -U eeg -d eeg_platform -c \
  "SELECT 'members' t, count(*) FROM members UNION ALL SELECT 'users', count(*) FROM users UNION ALL SELECT 'communities', count(*) FROM communities;"
echo "[restore] Fertig. Bei Bedarf 'docker compose up -d --build' und Login testen."
