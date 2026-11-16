#!/usr/bin/env bash
# Richtet eine eigene, eingeschränkte Postgres-Rolle für den Laufzeitzugriff der WEBAPP ein
# (OWASP-Audit, 13.08.2026, Befund "Row-Level Security wird nie ausgewertet").
#
# Hintergrund: Die Webapp verbindet bisher mit DERSELBEN Rolle, die per POSTGRES_USER in
# docker-compose.yml auch database/init.sql ausführt und damit jede Tabelle BESITZT. Postgres
# wertet Row-Level-Security-Policies für den Tabellenbesitzer grundsätzlich NICHT aus (siehe
# migrate_20260822.sql) -- die dort längst vorhandenen community_isolation-Policies griffen
# deshalb bislang nie. Diese neue, eingeschränkte Rolle besitzt keine einzige Tabelle, erhält
# nur gezielte GRANTs -- für sie gelten die Policies ganz normal.
#
# Zweite Rolle WORKER_DB_USER (seit 16.11.2026) für mqtt-subscriber und EDA-Parser: Diese
# Hintergrunddienste verarbeiten Daten ALLER Communities (Vorfall 17.08.2026: mit RLS blockiert)
# und bekommen deshalb BYPASSRLS. Vorher liefen sie als DB_USER, der im Postgres-Image Superuser
# ist; das Dienstkonto darf dagegen nur Daten lesen/schreiben, kein Schema ändern.
#
# WICHTIG -- Reihenfolge beim Deploy: dieses Skript MUSS vor "docker compose up -d --build"
# laufen (bzw. Teil desselben Deploy-Schritts sein). webapp/src/DB.php bevorzugt
# APP_DB_USER/APP_DB_PASSWORD, fällt aber automatisch auf die bisherige DB_USER/DB_PASSWORD-
# Rolle zurück, solange APP_DB_USER leer ist -- die Plattform bleibt also in ihrem bisherigen
# Zustand nutzbar, bis dieses Skript einmal gelaufen UND die Container neu gestartet worden
# sind. Kein Datenverlust: die bestehende DB_USER-Rolle bleibt unverändert Besitzer aller
# Tabellen, dieses Skript legt nur eine ZUSÄTZLICHE Rolle an.
#
# Aufruf (im Repo-Root, auf dem Server):
#   ./scripts/db_runtime_role_setup.sh
#
# Sicher erneut ausführbar: ein bereits vorhandenes Passwort wird nicht angetastet, außer mit
# --force (setzt dann auch das Passwort der bestehenden Rolle in Postgres selbst neu).

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
for arg in "$@"; do
  case "$arg" in
    --force) FORCE=true ;;
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

# ensure_credentials <USER_VAR> <PASSWORD_VAR> <Standardname> <Kommentar>
# Erzeugt ein Passwort und trägt beides in .env ein, wenn es dort noch fehlt (oder mit --force).
ensure_credentials() {
  local user_var="$1" pass_var="$2" default_user="$3" comment="$4"
  local user="${!user_var:-$default_user}" pass="${!pass_var:-}"
  if [ -n "$pass" ] && [ "$FORCE" = false ]; then
    echo "✓ ${user_var}/${pass_var} stehen bereits in .env -- wird nicht neu generiert (--force zum Erneuern)."
  else
    pass=$(openssl rand -hex 24)
    if grep -q "^${user_var}=" .env 2>/dev/null; then
      sed -i "s/^${user_var}=.*/${user_var}=${user}/" .env
    else
      { echo ""; echo "# ${comment} -- siehe scripts/db_runtime_role_setup.sh"; echo "${user_var}=${user}"; } >> .env
    fi
    if grep -q "^${pass_var}=" .env 2>/dev/null; then
      sed -i "s/^${pass_var}=.*/${pass_var}=${pass}/" .env
    else
      echo "${pass_var}=${pass}" >> .env
    fi
    echo "✓ ${user_var}/${pass_var} generiert und in .env eingetragen (Benutzer: ${user})."
  fi
  printf -v "$user_var" '%s' "$user"
  printf -v "$pass_var" '%s' "$pass"
}

ensure_credentials APP_DB_USER APP_DB_PASSWORD eeg_app "Eingeschränkte DB-Laufzeit-Rolle der Webapp (RLS greift)"
# Dienstkonto für mqtt-subscriber und EDA-Parser: verarbeitet Daten ALLER Communities und braucht
# deshalb BYPASSRLS, ist aber -- anders als DB_USER aus dem Postgres-Image -- kein Superuser und
# darf kein Schema ändern.
ensure_credentials WORKER_DB_USER WORKER_DB_PASSWORD eeg_worker "DB-Dienstkonto für mqtt-subscriber und EDA-Parser"

echo "Lege Rolle '${APP_DB_USER}' an bzw. aktualisiere Grants (als Schema-Besitzer ${DB_USER})..."
$COMPOSE exec -T timescaledb psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 \
  -v app_user="$APP_DB_USER" -v app_pass="$APP_DB_PASSWORD" \
  -v worker_user="$WORKER_DB_USER" -v worker_pass="$WORKER_DB_PASSWORD" -v dbname="$DB_NAME" < scripts/sql/runtime_roles.sql
echo "✓ Rolle und Grants eingerichtet."

echo ""
echo "Starte webapp, mqtt-subscriber und eda-worker neu, damit sie mit den neuen Rollen verbinden..."
$COMPOSE up -d --force-recreate webapp mqtt-subscriber eda-worker

echo ""
echo "─────────────────────────────────────────────────────────────"
echo "Fertig. Die Webapp verbindet ab sofort als '${APP_DB_USER}' -- diese Rolle besitzt keine"
echo "Tabellen, die Row-Level-Security-Policies greifen jetzt tatsächlich. DB_USER (${DB_USER})"
echo "bleibt unverändert Schema-Besitzer für Migrationen. mqtt-subscriber und eda-worker verbinden"
echo "als '${WORKER_DB_USER}' (BYPASSRLS, aber kein Superuser)."
echo "─────────────────────────────────────────────────────────────"
