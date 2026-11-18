#!/usr/bin/env bash
# Richtet den Anmeldedienst Keycloak (auth.<DOMAIN>) ein. Entscheidung: docs/adr/0007-keycloak.md
#
# Was das Skript macht (sicher erneut ausführbar, vorhandene Werte bleiben):
#   1. Zufällige Zugangsdaten in .env ergänzen, falls sie fehlen: KC_DB_PASSWORD, Bootstrap-Admin,
#      Client-Secrets für portal, app und konsole.
#   2. Eigene Datenbank "keycloak" mit eigener Rolle "keycloak" in der bestehenden TimescaleDB anlegen
#      (kein Superuser, besitzt nur diese eine Datenbank, kein Zugriff auf die Plattform-Daten).
#   3. COMPOSE_PROFILES um "keycloak" ergänzen und den Container starten. Beim ersten Start legt
#      Keycloak den Realm aus keycloak/realm/ an.
#
# Danach (einmalig): bestehende Konten übernehmen, siehe docs/BETRIEBSHANDBUCH.md
#   docker compose exec -T webapp php /var/www/html/bin/keycloak_import_users.php --dry-run
#
# Aufruf (im Repo-Root, auf dem Server):  ./scripts/keycloak_setup.sh

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ ! -f .env ]; then
  echo ".env nicht gefunden -- bitte zuerst ./scripts/setup.sh ausführen."
  exit 1
fi
set -a
# shellcheck disable=SC1091
source .env
set +a

COMPOSE="docker compose"

# ensure_env <VAR> <Kommentar> [Wert]: trägt VAR in .env ein, wenn sie dort fehlt oder leer ist.
ensure_env() {
  local var="$1" comment="$2" value="${3:-}"
  if [ -n "${!var:-}" ]; then
    echo "✓ ${var} steht bereits in .env."
    return
  fi
  [ -n "$value" ] || value=$(openssl rand -hex 24)
  if grep -q "^${var}=" .env; then
    sed -i "s|^${var}=.*|${var}=${value}|" .env
  else
    { echo ""; echo "# ${comment} -- siehe scripts/keycloak_setup.sh"; echo "${var}=${value}"; } >> .env
  fi
  printf -v "$var" '%s' "$value"
  export "${var?}"
  echo "✓ ${var} in .env eingetragen."
}

ensure_env KC_DB_PASSWORD "Keycloak: Passwort der DB-Rolle keycloak"
ensure_env KC_BOOTSTRAP_ADMIN_PASSWORD "Keycloak: Admin im master-Realm (nur per SSH-Tunnel erreichbar)"
ensure_env KC_PORTAL_CLIENT_SECRET "Keycloak: Client-Secret der Webapp (Client portal)"
ensure_env KC_APP_CLIENT_SECRET "Keycloak: Client-Secret für den App-Passwort-Login (Client app)"
ensure_env KC_KONSOLE_CLIENT_SECRET "Keycloak: Client-Secret der Steuerzentrale (Client konsole)"

echo "Lege DB-Rolle und Datenbank 'keycloak' an (falls noch nicht vorhanden)..."
$COMPOSE exec -T timescaledb psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 -v kc_pass="$KC_DB_PASSWORD" < scripts/sql/keycloak_db.sql
echo "✓ Datenbank bereit."

case ",${COMPOSE_PROFILES:-}," in
  *,keycloak,*) echo "✓ COMPOSE_PROFILES enthält keycloak bereits." ;;
  *)
    new="${COMPOSE_PROFILES:+${COMPOSE_PROFILES},}keycloak"
    if grep -q "^COMPOSE_PROFILES=" .env; then
      sed -i "s|^COMPOSE_PROFILES=.*|COMPOSE_PROFILES=${new}|" .env
    else
      { echo ""; echo "# Optionale Dienste (docker-compose.yml, profiles)"; echo "COMPOSE_PROFILES=${new}"; } >> .env
    fi
    export COMPOSE_PROFILES="$new"
    echo "✓ COMPOSE_PROFILES=${new}"
    ;;
esac

echo "Baue und starte keycloak (der erste Start dauert auf dem Pi 1-2 Minuten)..."
$COMPOSE up -d --build keycloak
# Die Webapp neu erzeugen, damit sie KC_PORTAL_CLIENT_SECRET aus der .env bekommt (für das Import-Skript
# und später die Anmeldung). Ohne diesen Schritt meldet der Import "KC_PORTAL_CLIENT_SECRET fehlt".
$COMPOSE up -d webapp

# master-Realm (nur Admin-Konsole) auf die Tunnel-Adresse legen. Sonst schickt sein Anmeldeformular an
# https://auth.<DOMAIN>/realms/master/..., und genau das sperrt Traefik absichtlich (Login hängt).
echo "Warte, bis keycloak bereit ist..."
for _ in $(seq 1 60); do
  [ "$($COMPOSE ps --format '{{.Health}}' keycloak 2>/dev/null)" = "healthy" ] && break
  sleep 5
done
$COMPOSE exec -T keycloak bash -c '/opt/keycloak/bin/kcadm.sh config credentials --config /tmp/kcadm.config \
    --server http://localhost:8080 --realm master --user admin --password "$KC_BOOTSTRAP_ADMIN_PASSWORD" >/dev/null \
  && /opt/keycloak/bin/kcadm.sh update realms/master --config /tmp/kcadm.config -s attributes.frontendUrl=http://localhost:8081' \
  && echo "✓ master-Realm nur über http://localhost:8081 (SSH-Tunnel)." \
  || echo "! master-Realm nicht umgestellt (Bootstrap-Admin schon gelöscht?), siehe docs/BETRIEBSHANDBUCH.md."

# Korrekturen am bestehenden Realm (Realm-Datei gilt nur beim ersten Start), mit dem Bootstrap-Admin.
KC_ADMIN_USER=admin KC_ADMIN_PASSWORD="$KC_BOOTSTRAP_ADMIN_PASSWORD" ./scripts/keycloak_realm_update.sh \
  || echo "! Realm-Korrekturen nicht ausgeführt, später: ./scripts/keycloak_realm_update.sh"

echo ""
echo "─────────────────────────────────────────────────────────────"
echo "Fertig. Noch nötig:"
echo "  1. nginx-Proxy: auth.${DOMAIN} wie portal.${DOMAIN} auf Traefik (docs/NGINX_PROXY.md),"
echo "     Zertifikat um auth.${DOMAIN} erweitern."
echo "  2. Konten übernehmen (Probelauf zuerst):"
echo "     docker compose exec -T webapp php /var/www/html/bin/keycloak_import_users.php --dry-run"
echo "  3. Admin-Konsole nur per SSH-Tunnel: ssh -L 8081:127.0.0.1:8081 <server>, dann"
echo "     http://localhost:8081/admin (Benutzer admin, Passwort KC_BOOTSTRAP_ADMIN_PASSWORD)."
echo "─────────────────────────────────────────────────────────────"
