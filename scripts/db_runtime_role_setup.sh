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
# NUR für webapp/src/DB.php gedacht (Vorfall 17.08.2026): mqtt-subscriber und eda-parser
# verbinden bewusst weiterhin als DB_USER (Schema-Besitzer) -- beides vertrauenswürdige interne
# Hintergrunddienste ohne einzelne, community-gebundene Nutzer-Session (sie verarbeiten
# Nachrichten/Importe für ALLE Communities), RLS würde dort ohne ein SET app.community_id vor
# jeder Abfrage nur jeden Zugriff blockieren, siehe main.py bzw. parser.py.
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

APP_DB_USER_DEFAULT="eeg_app"

if [ -n "${APP_DB_PASSWORD:-}" ] && [ "$FORCE" = false ]; then
  echo "✓ APP_DB_USER/APP_DB_PASSWORD stehen bereits in .env -- wird nicht neu generiert (--force zum Erneuern)."
  APP_DB_USER="${APP_DB_USER:-$APP_DB_USER_DEFAULT}"
else
  APP_DB_USER="${APP_DB_USER:-$APP_DB_USER_DEFAULT}"
  APP_DB_PASSWORD=$(openssl rand -hex 24)
  if grep -q '^APP_DB_USER=' .env 2>/dev/null; then
    sed -i "s/^APP_DB_USER=.*/APP_DB_USER=${APP_DB_USER}/" .env
    sed -i "s/^APP_DB_PASSWORD=.*/APP_DB_PASSWORD=${APP_DB_PASSWORD}/" .env
  else
    { echo ""; echo "# Eingeschränkte DB-Laufzeit-Rolle -- siehe scripts/db_runtime_role_setup.sh"; \
      echo "APP_DB_USER=${APP_DB_USER}"; echo "APP_DB_PASSWORD=${APP_DB_PASSWORD}"; } >> .env
  fi
  echo "✓ APP_DB_USER/APP_DB_PASSWORD generiert und in .env eingetragen (Benutzer: ${APP_DB_USER})."
fi

echo "Lege Rolle '${APP_DB_USER}' an bzw. aktualisiere Grants (als Schema-Besitzer ${DB_USER})..."
$COMPOSE exec -T timescaledb psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 \
  -v app_user="$APP_DB_USER" -v app_pass="$APP_DB_PASSWORD" -v dbname="$DB_NAME" <<'SQL'
-- Hinweis: psql interpoliert :'var'/:"var" NICHT innerhalb von dollar-quotierten Strings
-- ($do$...$do$) -- ein ursprünglicher DO-Block-Ansatz hier scheiterte deshalb live mit einem
-- Syntaxfehler (leerer, nicht ersetzter Doppelpunkt). \gexec (Server liefert einen fertigen
-- SQL-Text als Ergebniszeile zurück, den psql anschließend ausführt) umgeht das, weil die
-- Interpolation dabei außerhalb jeder Dollar-Quotierung passiert -- live gegen echtes
-- PostgreSQL 16 getestet (14.08.2026).
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION', :'app_user', :'app_pass')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user')
\gexec

SELECT format('ALTER ROLE %I PASSWORD %L', :'app_user', :'app_pass')
WHERE EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user')
\gexec

GRANT CONNECT ON DATABASE :"dbname" TO :"app_user";
GRANT USAGE ON SCHEMA public TO :"app_user";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"app_user";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"app_user";
-- Damit künftige Migrationen (weiterhin als Schema-Besitzer ausgeführt) neue Tabellen/Sequenzen
-- automatisch für die App-Rolle freigeben, ohne dass jedes Mal an ein manuelles GRANT gedacht
-- werden muss.
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app_user";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO :"app_user";
SQL
echo "✓ Rolle und Grants eingerichtet."

echo ""
echo "Starte webapp neu, damit sie mit der neuen Rolle verbindet..."
$COMPOSE up -d --force-recreate webapp

echo ""
echo "─────────────────────────────────────────────────────────────"
echo "Fertig. Die Webapp verbindet ab sofort als '${APP_DB_USER}' -- diese Rolle besitzt keine"
echo "Tabellen, die Row-Level-Security-Policies greifen jetzt tatsächlich. DB_USER (${DB_USER})"
echo "bleibt unverändert Schema-Besitzer für Migrationen. mqtt-subscriber/eda-parser verbinden"
echo "bewusst weiterhin als DB_USER (siehe Kommentar oben im Skript)."
echo "─────────────────────────────────────────────────────────────"
