#!/usr/bin/env bash
# Richtet ein Passwort (requirepass) für Redis ein -- vorher lief der Container komplett
# unauthentifiziert (jeder mit Zugriff auf das interne Docker-Netz eeg-net konnte Sessions
# lesen/fälschen und sich damit als jeder eingeloggte Nutzer ausgeben, siehe OWASP-Audit
# 13.08.2026). Redis ist auf keinem Host-Port veröffentlicht -- praktisch nur relevant, falls
# ein anderer Container im selben Netz kompromittiert wird -- aber ohne echten Aufwand zu
# beheben, daher trotzdem eingerichtet.
#
# Aufruf (im Repo-Root, auf dem Server):
#   ./scripts/redis_secure_setup.sh
#
# Sicher erneut ausführbar: ein bereits vorhandenes Passwort wird nicht angetastet, außer mit
# --force. webapp/src/Auth.php baut den Redis-Verbindungsstring der Session zur Laufzeit aus
# REDIS_PASSWORD (leer = wie bisher unauthentifiziert) -- ohne dieses Skript ändert sich also
# nichts, die Plattform bleibt in ihrem bisherigen (unauthentifizierten) Zustand funktionsfähig.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ ! -f .env ]; then
  echo ".env nicht gefunden -- bitte zuerst ./scripts/setup.sh ausführen (legt .env an)."
  exit 1
fi
set -a
# shellcheck disable=SC1091
source .env
set +a

FORCE=false
NO_RESTART=false
for arg in "$@"; do
  case "$arg" in
    --force) FORCE=true ;;
    --no-restart) NO_RESTART=true ;;  # von scripts/setup.sh genutzt -- Stack läuft dort noch
                                       # gar nicht, der erste Start folgt gleich im Setup-Skript
  esac
done

if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE="docker-compose"
else
  echo "Docker Compose wurde nicht gefunden."
  exit 1
fi

# Wichtig: auf den tatsächlichen WERT prüfen, nicht nur ob der Schlüssel existiert -- siehe
# gleiches Muster in mqtt_secure_setup.sh.
if [ -n "${REDIS_PASSWORD:-}" ] && [ "$FORCE" = false ]; then
  echo "✓ REDIS_PASSWORD steht bereits in .env -- wird nicht neu generiert (--force zum Erneuern)."
else
  REDIS_PASSWORD=$(openssl rand -hex 24)
  if grep -q '^REDIS_PASSWORD=' .env 2>/dev/null; then
    sed -i "s/^REDIS_PASSWORD=.*/REDIS_PASSWORD=${REDIS_PASSWORD}/" .env
  else
    { echo ""; echo "# Redis -- siehe scripts/redis_secure_setup.sh"; \
      echo "REDIS_PASSWORD=${REDIS_PASSWORD}"; } >> .env
  fi
  echo "✓ REDIS_PASSWORD generiert und in .env eingetragen."
fi

CONFIG_DIR="/opt/eeg/redis-config"
echo "Schreibe redis.conf mit requirepass nach ${CONFIG_DIR}..."
sudo mkdir -p "$CONFIG_DIR"
# Selbstheilung (Vorfall 17.08.2026): läuft dieses Skript NACH einem "docker compose up", das
# den redis-Container schon mit dem neuen docker-compose.yml gestartet hat, BEVOR diese Datei
# existierte, hat Docker für den Bind-Mount automatisch ein leeres VERZEICHNIS an dieser Stelle
# angelegt (Standard-Docker-Verhalten bei einer fehlenden Bind-Mount-Quelle) -- "tee" könnte dort
# keine Datei mehr hinschreiben. Eigentlich MUSS dieses Skript vor "docker compose up -d --build"
# laufen (siehe docs/DEPLOY_OWASP_AUDIT.md), aber falls doch nicht: hier automatisch aufräumen,
# statt mit einem kryptischen "tee: .../redis.conf: Is a directory" abzubrechen.
if [ -d "$CONFIG_DIR/redis.conf" ]; then
  echo "⚠ ${CONFIG_DIR}/redis.conf ist fälschlich ein Verzeichnis (Docker hat es so angelegt, weil"
  echo "  dieses Skript nach \"docker compose up\" statt davor lief) -- wird jetzt entfernt."
  sudo rm -rf "$CONFIG_DIR/redis.conf"
fi
printf 'requirepass %s\n' "$REDIS_PASSWORD" | sudo tee "$CONFIG_DIR/redis.conf" > /dev/null
sudo chmod 644 "$CONFIG_DIR/redis.conf"
echo "✓ redis.conf geschrieben."

if [ "$NO_RESTART" = true ]; then
  echo ""
  echo "✓ --no-restart: Container werden hier nicht neu gestartet (folgt gleich im Setup-Skript)."
else
  echo ""
  echo "Starte redis + webapp neu, damit das Passwort greift..."
  $COMPOSE up -d --force-recreate redis webapp
fi

echo ""
echo "─────────────────────────────────────────────────────────────"
echo "Fertig. Redis verlangt ab sofort ein Passwort -- webapp/src/Auth.php liest REDIS_PASSWORD"
echo "automatisch aus der Umgebung und baut den Verbindungsstring entsprechend. Bereits"
echo "eingeloggte Nutzer bleiben eingeloggt (ihre Session-Daten in Redis wurden nicht"
echo "angetastet, nur der Zugriffsweg wurde jetzt abgesichert)."
echo "─────────────────────────────────────────────────────────────"
