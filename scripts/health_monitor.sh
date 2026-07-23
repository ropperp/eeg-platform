#!/usr/bin/env bash
#
# scripts/health_monitor.sh — Wächter über alle EEG-Container.
#
# Prüft für jeden Dienst den Docker-Healthstatus. Ist ein Container "unhealthy" (oder gestoppt),
# wird er 1–2× automatisch neu gestartet; bleibt es dabei, geht eine Alarm-Mail ans Admin-Postfach
# (scripts/health_alert.php im webapp-Container, gleiche Microsoft-Graph-Anbindung wie sonst).
#
# Gedacht für einen Cron-Job auf dem Host, z. B. alle 5 Minuten:
#   */5 * * * * cd /opt/eeg-platform && bash scripts/health_monitor.sh >> /var/log/eeg-health.log 2>&1
#
# Eine Cooldown-Datei je Container verhindert, dass bei einem anhaltenden Problem alle 5 Minuten
# neu gestartet und gemailt wird (Standard: 6 h). Sobald der Container wieder gesund ist, wird die
# Cooldown-Datei entfernt.

set -uo pipefail

REPO_DIR="${EEG_REPO_DIR:-/opt/eeg-platform}"
SERVICES="${EEG_SERVICES:-traefik timescaledb redis mosquitto mqtt-subscriber webapp latex-service}"
STAMP_DIR="${EEG_HEALTH_STAMP_DIR:-/opt/eeg/health-monitor}"
COOLDOWN_SECONDS="${EEG_HEALTH_COOLDOWN:-21600}"   # 6 h
HOST="$(hostname)"

cd "$REPO_DIR" 2>/dev/null || { echo "[health_monitor] REPO_DIR $REPO_DIR nicht gefunden"; exit 1; }
mkdir -p "$STAMP_DIR"

# Healthstatus eines Containers: healthy | unhealthy | starting | none | missing | notrunning:<state>
container_health() {
    local name="$1" state health
    state="$(docker inspect --format '{{.State.Status}}' "$name" 2>/dev/null)" || { echo "missing"; return; }
    if [ "$state" != "running" ]; then echo "notrunning:$state"; return; fi
    health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name" 2>/dev/null)"
    echo "${health:-none}"
}

send_alert() {
    local container="$1" status="$2" action="$3"
    docker compose exec -T \
        -e ALERT_CONTAINER="$container" \
        -e ALERT_STATUS="$status" \
        -e ALERT_ACTION="$action" \
        -e ALERT_HOST="$HOST" \
        webapp php < "$REPO_DIR/scripts/health_alert.php" \
        || echo "[health_monitor] Alarm-Mail für $container konnte nicht gesendet werden"
}

# Wartet, bis der Container wieder healthy (oder ohne Healthcheck: running) ist. Rückgabe 0 = ok.
wait_recovered() {
    local name="$1" i hh
    for i in $(seq 1 12); do
        sleep 5
        hh="$(container_health "$name")"
        [ "$hh" = "healthy" ] || [ "$hh" = "none" ] && return 0
    done
    return 1
}

in_cooldown() {
    local stamp="$1"
    [ -f "$stamp" ] || return 1
    local age now mtime
    now="$(date +%s)"
    mtime="$(stat -c %Y "$stamp" 2>/dev/null || echo 0)"
    age=$(( now - mtime ))
    [ "$age" -lt "$COOLDOWN_SECONDS" ]
}

for svc in $SERVICES; do
    h="$(container_health "$svc")"
    stamp="$STAMP_DIR/$svc.alerted"

    case "$h" in
        healthy|none|starting)
            rm -f "$stamp"   # wieder gesund -> Cooldown zurücksetzen
            ;;
        unhealthy)
            if in_cooldown "$stamp"; then
                echo "[health_monitor] $svc weiterhin unhealthy (Cooldown aktiv) — kein erneuter Eingriff"
                continue
            fi
            echo "[health_monitor] $svc unhealthy — versuche bis zu 2 Neustarts"
            recovered=1
            for attempt in 1 2; do
                docker restart "$svc" >/dev/null 2>&1
                if wait_recovered "$svc"; then recovered=0; break; fi
            done
            if [ "$recovered" -eq 0 ]; then
                send_alert "$svc" "war unhealthy" "automatisch neu gestartet — läuft wieder"
                rm -f "$stamp"
            else
                send_alert "$svc" "unhealthy" "2× Neustart erfolglos — bitte manuell prüfen"
                touch "$stamp"
            fi
            ;;
        notrunning:*)
            if in_cooldown "$stamp"; then continue; fi
            echo "[health_monitor] $svc nicht laufend ($h) — versuche Start"
            docker compose up -d "$svc" >/dev/null 2>&1
            if wait_recovered "$svc"; then
                send_alert "$svc" "war gestoppt (${h#notrunning:})" "automatisch neu gestartet — läuft wieder"
                rm -f "$stamp"
            else
                send_alert "$svc" "gestoppt (${h#notrunning:})" "Neustart erfolglos — bitte manuell prüfen"
                touch "$stamp"
            fi
            ;;
        missing)
            if in_cooldown "$stamp"; then continue; fi
            send_alert "$svc" "fehlt/nicht gefunden" "Container existiert nicht — bitte prüfen"
            touch "$stamp"
            ;;
    esac
done
