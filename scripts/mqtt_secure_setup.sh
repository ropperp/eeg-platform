#!/usr/bin/env bash
# Richtet TLS (Port 8883, selbstsigniertes Zertifikat) und Benutzername/Passwort für den
# Mosquitto-Broker ein -- vorher lief er mit allow_anonymous=true und ganz ohne Verschlüsselung.
#
# Aufruf (im Repo-Root, auf dem Server):
#   ./scripts/mqtt_secure_setup.sh
#
# Sicher erneut ausführbar: vorhandenes Zertifikat/Passwort wird nicht angetastet, außer mit
# --force. Nach dem Lauf müssen ALLE bereits im Feld laufenden ESP32-Geräte im /config-Formular
# auf den neuen Benutzernamen/Passwort umgestellt werden (siehe Ausgabe am Ende) -- sonst können
# sie sich ab dem Neustart des Brokers nicht mehr verbinden (allow_anonymous ist danach aus).

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
    --force)      FORCE=true ;;
    --no-restart) NO_RESTART=true ;;  # von scripts/setup.sh genutzt -- Stack läuft dort noch
                                       # gar nicht, das anschließende "docker compose up" bringt
                                       # ohnehin alle Container inkl. mosquitto neu hoch.
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

CERT_DIR="/opt/eeg/mosquitto/certs"
PASSWD_FILE="/opt/eeg/mosquitto/passwd"

# ─── Zertifikat (selbstsigniert, 10 Jahre) ───────────────────────
if [ -f "$CERT_DIR/server.crt" ] && [ "$FORCE" = false ]; then
  echo "✓ Zertifikat existiert bereits unter $CERT_DIR -- wird nicht neu erzeugt (--force zum Erneuern)."
else
  echo "Erzeuge selbstsigniertes CA- + Server-Zertifikat für Mosquitto (10 Jahre gültig)..."
  sudo mkdir -p "$CERT_DIR"
  CN="${DOMAIN:-mosquitto.local}"
  openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -keyout /tmp/ca.key -out /tmp/ca.crt \
    -subj "/CN=EEG-Plattform MQTT CA"
  openssl req -nodes -newkey rsa:2048 \
    -keyout /tmp/server.key -out /tmp/server.csr \
    -subj "/CN=${CN}"
  openssl x509 -req -in /tmp/server.csr -CA /tmp/ca.crt -CAkey /tmp/ca.key -CAcreateserial \
    -out /tmp/server.crt -days 3650
  sudo mv /tmp/ca.crt /tmp/server.crt /tmp/server.key "$CERT_DIR/"
  sudo chmod 600 "$CERT_DIR/server.key"
  rm -f /tmp/ca.key /tmp/server.csr /tmp/ca.srl
  echo "✓ Zertifikat erzeugt unter $CERT_DIR (CN=${CN})."
  echo "  ESP32-Geräte prüfen dieses Zertifikat NICHT (setInsecure()) -- verschlüsselt die"
  echo "  Verbindung trotzdem, schützt aber nicht vor einem aktiven Man-in-the-Middle."
fi

# ─── Benutzername/Passwort ───────────────────────────────────────
# Wichtig: auf den tatsächlichen WERT prüfen, nicht nur ob der Schlüssel existiert --
# .env.example enthält die Zeile "MQTT_PASSWORD=" bereits (leer), die würde sonst als
# "schon konfiguriert" durchgehen und nie ein echtes Passwort erzeugen.
if [ -n "${MQTT_PASSWORD:-}" ] && [ "$FORCE" = false ]; then
  echo "✓ MQTT_USER/MQTT_PASSWORD stehen bereits in .env -- wird nicht neu generiert (--force zum Erneuern)."
else
  MQTT_USER="eeg-device"
  MQTT_PASSWORD=$(openssl rand -hex 12)
  if grep -q '^MQTT_USER=' .env 2>/dev/null; then
    sed -i "s/^MQTT_USER=.*/MQTT_USER=${MQTT_USER}/" .env
    sed -i "s/^MQTT_PASSWORD=.*/MQTT_PASSWORD=${MQTT_PASSWORD}/" .env
  else
    { echo ""; echo "# MQTT (Mosquitto) -- siehe scripts/mqtt_secure_setup.sh"; \
      echo "MQTT_USER=${MQTT_USER}"; echo "MQTT_PASSWORD=${MQTT_PASSWORD}"; } >> .env
  fi
  echo "✓ MQTT-Zugangsdaten generiert und in .env eingetragen (Benutzer: ${MQTT_USER})."
fi

echo "Schreibe Mosquitto-Passwort-Datei..."
sudo mkdir -p "$(dirname "$PASSWD_FILE")"
sudo rm -f "$PASSWD_FILE"
docker run --rm -v "$(dirname "$PASSWD_FILE")":/out eclipse-mosquitto:2 \
  mosquitto_passwd -b -c /out/passwd "$MQTT_USER" "$MQTT_PASSWORD"
sudo chmod 644 "$PASSWD_FILE"
echo "✓ Passwort-Datei geschrieben nach $PASSWD_FILE."

if [ "$NO_RESTART" = true ]; then
  echo ""
  echo "✓ --no-restart: Container werden hier nicht neu gestartet (folgt gleich im Setup-Skript)."
else
  echo ""
  echo "Starte mosquitto + mqtt-subscriber neu, damit TLS/Auth greifen..."
  $COMPOSE up -d --force-recreate mosquitto mqtt-subscriber
fi

echo ""
echo "─────────────────────────────────────────────────────────────"
echo "Fertig. WICHTIG: allow_anonymous ist jetzt aus -- jedes ESP32-Gerät braucht ab sofort"
echo "im /config-Formular (Zahnrad-Symbol -> MQTT-Einstellungen):"
echo "  mqtt-port:        8883   (TLS, empfohlen sobald extern erreichbar)"
echo "  mqtt-benutzername: ${MQTT_USER}"
echo "  mqtt-passwort:     ${MQTT_PASSWORD}"
echo "Ohne diese Änderung verlieren bereits laufende Geräte ab sofort die Verbindung."
echo "─────────────────────────────────────────────────────────────"
