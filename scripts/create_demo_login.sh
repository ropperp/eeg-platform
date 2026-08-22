#!/usr/bin/env bash
# Legt (oder aktualisiert) den EINEN Demo-Login für Präsentation/Diplomarbeit-Review an
# (Patrick, 05.09.2026: "ich möchte schon bitte gerne einen einzigen Login haben"). Der Account
# bekommt bewusst KEINE Rollen -- die vier vorgesehenen Rollen (Plattform-Admin, Obmann,
# Verbraucher 1, Einspeiser 1) werden danach im Platform-Admin-Backoffice unter
# "Benutzer verwalten" -> diesen Nutzer öffnen -> "Rolle hinzufügen" selbst zugewiesen (bei
# "member" jeweils die passende Mitglied-Identität im Feld "Mitglied-Identität" auswählen --
# siehe scripts/create_demo_members.php, das "Verbraucher 1"/"Einspeiser 1" anlegt).
#
# users.is_demo wird gesetzt, wodurch dieser Login PLATTFORMWEIT UND ROLLENÜBERGREIFEND nur
# noch lesen kann (siehe Router.php/AppApiAuth::requireAppAuth()) -- unabhängig davon, welche
# Rollen ihm später zugewiesen werden.
#
# Aufruf (im Repo-Root, auf dem Server):
#   ./scripts/create_demo_login.sh
#
# Sicher erneut ausführbar: bei bereits bestehender E-Mail wird nur das Passwort aktualisiert,
# Rollen (auch keine) bleiben unangetastet.

set -euo pipefail
cd "$(dirname "$0")/.."

COMPOSE="docker compose"
if [ -f .env ]; then set -a; source .env; set +a; fi
DB_USER="${DB_USER:-eeg}"
DB_NAME="${DB_NAME:-eeg_platform}"

echo "=== Demo-Login einrichten (Präsentation/Diplomarbeit-Review) ==="
read -r -p "E-Mail-Adresse für den Demo-Login: " DEMO_EMAIL
while true; do
  read -r -s -p "Passwort (min. 8 Zeichen): " DEMO_PASSWORD
  echo ""
  if [ "${#DEMO_PASSWORD}" -lt 8 ]; then
    echo "Zu kurz -- bitte mindestens 8 Zeichen."
    continue
  fi
  read -r -s -p "Passwort wiederholen: " DEMO_PASSWORD_REPEAT
  echo ""
  if [ "$DEMO_PASSWORD" != "$DEMO_PASSWORD_REPEAT" ]; then
    echo "Passwörter stimmen nicht überein -- bitte erneut."
    continue
  fi
  break
done

# Hash im webapp-Container erzeugen (gleiches Muster wie setup.sh) -- Passwort über eine
# Umgebungsvariable übergeben statt in den PHP-Code interpoliert, damit Sonderzeichen im
# Passwort keinen Syntaxfehler im generierten Code verursachen können.
DEMO_HASH=$($COMPOSE exec -T -e DEMO_PW="$DEMO_PASSWORD" webapp php -r 'echo password_hash(getenv("DEMO_PW"), PASSWORD_BCRYPT);')

# first_name/last_name bewusst NICHT Patricks echter Name (Patrick, 05.09.2026: "Bei Plattform,
# Admin und Obmann auch keine personenbezogenen Daten") -- rein technisch beschreibend.
$COMPOSE exec -T timescaledb psql -U "$DB_USER" -d "$DB_NAME" -v ON_ERROR_STOP=1 \
  -v demo_email="$DEMO_EMAIL" -v demo_hash="$DEMO_HASH" <<'SQL'
INSERT INTO users (email, password_hash, first_name, last_name, is_demo)
VALUES (:'demo_email', :'demo_hash', 'Demo', 'Zugang', true)
ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash, is_demo = true;
SQL

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✓ Demo-Login eingerichtet (noch OHNE Rollen):"
echo "  E-Mail:    ${DEMO_EMAIL}"
echo "  Passwort:  (das gerade vergebene)"
echo ""
echo "Nächster Schritt: falls noch nicht geschehen, zuerst die beiden fiktiven"
echo "Mitglied-Identitäten anlegen:"
echo "  docker compose exec -T webapp php < scripts/create_demo_members.php"
echo ""
echo "Danach im Platform-Admin-Backoffice (Menü \"Benutzer verwalten\") diesen Account öffnen und"
echo "unter \"Rolle hinzufügen\" nacheinander zuweisen:"
echo "  - platform_admin (kein EEG-Feld nötig)"
echo "  - manager        (EEG auswählen)"
echo "  - member         (EEG + Mitglied-Identität \"Verbraucher 1\" auswählen)"
echo "  - member         (EEG + Mitglied-Identität \"Einspeiser 1\" auswählen)"
echo "════════════════════════════════════════════════════════════"
