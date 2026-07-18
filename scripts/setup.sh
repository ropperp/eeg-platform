#!/usr/bin/env bash
# Einmaliges Setup der EEG-Plattform: .env anlegen (mit zufällig generierten Secrets),
# Datenverzeichnisse mit korrekten Rechten anlegen, Container bauen/starten, alle
# Datenbank-Migrations einspielen und den ersten Platform-Admin-Zugang interaktiv anlegen.
#
# Aufruf (im Repo-Root):
#   ./scripts/setup.sh
#
# Sicher erneut ausführbar: eine bereits vorhandene .env wird nicht verändert, bereits
# eingespielte Migrations überspringen sich selbst (CREATE TABLE IF NOT EXISTS etc.), und ein
# erneut angelegter Admin-Zugang aktualisiert nur das Passwort des bestehenden Accounts.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# ─── Docker-Voraussetzungen prüfen ──────────────────────────────
if ! command -v docker >/dev/null 2>&1; then
  echo "Docker wurde nicht gefunden. Bitte zuerst installieren -- siehe docs/DOCKER_INSTALL.md"
  exit 1
fi
if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE="docker-compose"
else
  echo "Docker Compose (Plugin oder eigenständig) wurde nicht gefunden. Siehe docs/DOCKER_INSTALL.md"
  exit 1
fi
echo "✓ Docker gefunden: $(docker --version)"

# ─── .env anlegen (nur wenn noch nicht vorhanden) ───────────────
if [ -f .env ]; then
  echo "✓ .env existiert bereits -- wird nicht verändert."
else
  echo ""
  echo "Für welche Domain soll die Plattform laufen? (z.B. stromfueralle.at)"
  echo "Leer lassen für lokales Testen ohne echte Domain (localhost)."
  read -r -p "Domain: " DOMAIN_INPUT
  DOMAIN_INPUT=${DOMAIN_INPUT:-localhost}

  # Zufällige Secrets -- niemand muss mehr von Hand openssl aufrufen und Werte eintragen.
  APP_SECRET=$(openssl rand -hex 32)
  LATEX_API_KEY=$(openssl rand -hex 16)
  DB_PASSWORD=$(openssl rand -hex 16)

  awk -v domain="$DOMAIN_INPUT" -v app_secret="$APP_SECRET" -v latex_key="$LATEX_API_KEY" -v db_pass="$DB_PASSWORD" '
    /^DOMAIN=/       { print "DOMAIN=" domain; next }
    /^APP_SECRET=/   { print "APP_SECRET=" app_secret; next }
    /^LATEX_API_KEY=/{ print "LATEX_API_KEY=" latex_key; next }
    /^DB_PASSWORD=/  { print "DB_PASSWORD=" db_pass; next }
    { print }
  ' .env.example > .env

  echo "✓ .env angelegt (Domain: ${DOMAIN_INPUT}, Secrets zufällig generiert)."
  echo "  SMTP-Zugangsdaten sind absichtlich leer -- E-Mail-Versand kann später bequem über"
  echo "  Platform-Admin -> E-Mail-Einstellungen (Microsoft Graph) eingerichtet werden."
fi

# .env für die folgenden Schritte einlesen (DB_USER/DB_NAME werden für psql-Aufrufe gebraucht).
set -a
# shellcheck disable=SC1091
source .env
set +a

# ─── Persistente Datenverzeichnisse ─────────────────────────────
echo ""
echo "Lege persistente Datenverzeichnisse unter /opt/eeg an ..."
sudo mkdir -p /opt/eeg/{timescaledb,redis,mosquitto/data,mosquitto/log,traefik/letsencrypt,webapp-storage/uploads,webapp-storage/pdfs,latex-templates}
sudo chmod 755 /opt/eeg
sudo chown -R 82:82 /opt/eeg/webapp-storage /opt/eeg/latex-templates
echo "✓ /opt/eeg vorbereitet."

# ─── Container bauen & starten ───────────────────────────────────
echo ""
echo "Baue und starte Container (das kann beim ersten Mal einige Minuten dauern) ..."
$COMPOSE up -d --build

# ─── Auf Datenbank warten ────────────────────────────────────────
echo ""
echo -n "Warte auf Datenbank ..."
for _ in $(seq 1 60); do
  if $COMPOSE exec -T timescaledb pg_isready -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1; then
    echo " bereit."
    break
  fi
  echo -n "."
  sleep 2
done

# ─── Migrationen einspielen ──────────────────────────────────────
echo ""
echo "Spiele Datenbank-Migrationen ein ..."
for f in database/migrate_*.sql; do
  echo "  -> $(basename "$f")"
  $COMPOSE exec -T timescaledb psql -U "$DB_USER" -d "$DB_NAME" < "$f" > /dev/null
done
echo "✓ Migrationen eingespielt."

# ─── Platform-Admin-Zugang anlegen ───────────────────────────────
echo ""
echo "=== Platform-Admin-Zugang einrichten ==="
read -r -p "Admin-E-Mail-Adresse: " ADMIN_EMAIL
while true; do
  read -r -s -p "Admin-Passwort (min. 8 Zeichen): " ADMIN_PASSWORD
  echo ""
  if [ "${#ADMIN_PASSWORD}" -lt 8 ]; then
    echo "Zu kurz -- bitte mindestens 8 Zeichen."
    continue
  fi
  read -r -s -p "Passwort wiederholen: " ADMIN_PASSWORD_REPEAT
  echo ""
  if [ "$ADMIN_PASSWORD" != "$ADMIN_PASSWORD_REPEAT" ]; then
    echo "Passwörter stimmen nicht überein -- bitte erneut."
    continue
  fi
  break
done

# Hash im webapp-Container erzeugen (kein zusätzliches Tool nötig). Das Passwort wird über eine
# Umgebungsvariable übergeben statt in den PHP-Code interpoliert zu werden -- sonst könnten
# Sonderzeichen im Passwort (Anführungszeichen etc.) den generierten Code kaputt machen.
ADMIN_HASH=$($COMPOSE exec -T -e ADMIN_PW="$ADMIN_PASSWORD" webapp php -r 'echo password_hash(getenv("ADMIN_PW"), PASSWORD_BCRYPT);')

$COMPOSE exec -T timescaledb psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 \
  -v admin_email="$ADMIN_EMAIL" -v admin_hash="$ADMIN_HASH" <<'SQL'
INSERT INTO users (email, password_hash, first_name, last_name)
VALUES (:'admin_email', :'admin_hash', 'Platform', 'Admin')
ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash;

INSERT INTO user_roles (community_id, user_id, role)
SELECT c.id, u.id, 'platform_admin' FROM communities c, users u WHERE u.email = :'admin_email'
ON CONFLICT DO NOTHING;

INSERT INTO user_roles (community_id, user_id, role)
SELECT c.id, u.id, 'manager' FROM communities c, users u WHERE u.email = :'admin_email'
ON CONFLICT DO NOTHING;
SQL

echo "✓ Platform-Admin-Zugang eingerichtet."
echo ""
echo "════════════════════════════════════════════════════════════"
echo "Fertig! Login unter https://${DOMAIN}/portal/login (bzw. http://localhost/portal/login"
echo "bei lokalem Testen) mit:"
echo "  E-Mail:    ${ADMIN_EMAIL}"
echo "  Passwort:  (das gerade vergebene)"
echo "════════════════════════════════════════════════════════════"
