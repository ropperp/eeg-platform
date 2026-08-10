#!/usr/bin/env bash
# scripts/mqtt_apply_pending.sh — prüft platform_mqtt_config.pending_apply (von der
# Platform-Admin-Oberfläche gesetzt, siehe /admin/mail-settings -> "MQTT-Zugangsdaten" ->
# "Speichern & anwenden") und wendet eine anstehende Änderung automatisch auf den echten
# Mosquitto-Broker an -- die Webapp selbst kann Docker/Dateien auf dem Host nicht direkt
# anfassen, dieses Skript läuft deshalb als Host-Cron (gleiches Muster wie
# scripts/health_monitor.sh), nicht im Container.
#
# Einrichten (einmalig, auf dem Host), z.B. jede Minute:
#   ( crontab -l 2>/dev/null; echo "* * * * * cd /opt/eeg-platform && bash scripts/mqtt_apply_pending.sh >> /var/log/eeg-mqtt-apply.log 2>&1" ) | crontab -
#
# Ohne diesen Cron-Job bleibt eine über die Plattform gespeicherte Änderung als "wird in Kürze
# angewendet" hängen -- Fallback ist dann der manuelle Aufruf von
# scripts/mqtt_secure_setup.sh --apply (steht auch im Formular selbst).

set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ ! -f .env ]; then
  echo "[mqtt_apply_pending] .env nicht gefunden -- übersprungen."
  exit 0
fi
set -a
# shellcheck disable=SC1091
source .env
set +a

if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE="docker-compose"
else
  echo "[mqtt_apply_pending] Docker Compose nicht gefunden -- übersprungen."
  exit 0
fi

PENDING=$($COMPOSE exec -T timescaledb psql -U "$DB_USER" -d "$DB_NAME" -tAc \
  "SELECT pending_apply FROM platform_mqtt_config WHERE id = 1" 2>/dev/null | tr -d '[:space:]')

if [ "$PENDING" != "t" ]; then
  exit 0   # nichts anstehend -- stiller No-Op, damit der minütliche Cron nicht das Log flutet
fi

echo "[mqtt_apply_pending] Anstehende MQTT-Zugangsdaten-Änderung gefunden -- wende an..."
if bash scripts/mqtt_secure_setup.sh --apply; then
  $COMPOSE exec -T timescaledb psql -U "$DB_USER" -d "$DB_NAME" -c \
    "UPDATE platform_mqtt_config SET pending_apply = false, applied_at = now() WHERE id = 1" >/dev/null
  echo "[mqtt_apply_pending] Angewendet und in der Plattform als erledigt markiert."
else
  echo "[mqtt_apply_pending] mqtt_secure_setup.sh --apply fehlgeschlagen -- pending_apply bleibt gesetzt, nächster Versuch beim nächsten Cron-Lauf."
  exit 1
fi
