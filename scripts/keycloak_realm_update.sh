#!/usr/bin/env bash
# Bringt einen bereits angelegten Keycloak-Realm auf den Stand von keycloak/realm/stromfueralle-realm.json.
# Nötig, weil Keycloak die Realm-Datei nur beim ERSTEN Start liest (docs/adr/0007-keycloak.md).
# Sicher mehrfach ausführbar: jede Korrektur prüft zuerst, ob sie schon da ist.
#
# Korrekturen:
#   18.11.2026  Google-Login ohne 2FA scheiterte ("Ungültiger Benutzername oder Passwort"): Der Ablauf
#               "nach Fremd-Login 2FA" hatte für Konten ohne 2FA keinen erfolgreichen Schritt. Neu: am
#               Ende "Allow access" (REQUIRED). Wer 2FA hat, muss den Code weiterhin eingeben.
#   19.11.2026  Portal-Anmeldung über Keycloak (Schritt 2): Rücksprung /portal/auth/callback (der Portal-Host
#               leitet alles außer /portal/* um), Backchannel-Abmeldung /api/kc/backchannel-logout, Links
#               "Passwort vergessen"/"Mitglied werden" auf der Login-Seite, Theme "stromfueralle", Dienstkonto
#               darf sehen, ob Google eingeschaltet ist, Keycloak-Kontoseite aus (Profil, Passwort und 2FA
#               verwaltet das Portal; Google und Passkeys über das Portal-Profil).
#
# Aufruf (auf dem Server, im Repo-Root):  ./scripts/keycloak_realm_update.sh
# Fragt nach Benutzername und Passwort eines Admins im master-Realm (wie in der Admin-Konsole).
# Ohne Rückfrage (z. B. Tests): KC_ADMIN_USER und KC_ADMIN_PASSWORD setzen.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
if [ -f .env ] && [ -z "${KC_SKIP_ENV:-}" ]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi
: "${DOMAIN:?DOMAIN fehlt (.env)}"
PORTAL_URL="${KC_PORTAL_URL:-https://${PORTAL_HOST:-portal.${DOMAIN}}}"
SITE_URL="${KC_SITE_URL:-https://${DOMAIN}}"

EXEC=(${KC_EXEC:-docker compose exec})      # für Tests: KC_EXEC="docker exec", KC_SERVICE=<container>
SVC="${KC_SERVICE:-keycloak}"
NOTTY="${KC_NOTTY:--T}"                     # docker exec kennt kein -T: dort KC_NOTTY=-i
REALM=stromfueralle
CFG=/tmp/kcadm-update.config
kc() { "${EXEC[@]}" $NOTTY "$SVC" /opt/keycloak/bin/kcadm.sh "$@" --config "$CFG"; }

if [ -n "${KC_ADMIN_USER:-}" ]; then
  ADMIN="$KC_ADMIN_USER"
else
  read -r -p "Admin-Benutzer im master-Realm: " ADMIN
fi
if [ -n "${KC_ADMIN_PASSWORD:-}" ]; then
  PW="$KC_ADMIN_PASSWORD"
else
  read -r -s -p "Passwort: " PW; echo
fi
# Passwort über stdin in den Container, nicht als Argument (sonst stünde es in der Prozessliste des Hosts).
printf '%s\n' "$PW" | "${EXEC[@]}" $NOTTY "$SVC" bash -c 'read -r PW; /opt/keycloak/bin/kcadm.sh config credentials \
  --config "$1" --server http://localhost:8080 --realm master --user "$2" --password "$PW"' _ "$CFG" "$ADMIN" >/dev/null
unset PW
trap '"${EXEC[@]}" $NOTTY "$SVC" rm -f "$CFG" >/dev/null 2>&1 || true' EXIT
echo "✓ Angemeldet."

# --- 18.11.2026: Ablauf nach Google-Login braucht einen Schritt, der immer gelingt ---------------
FLOW="nach%20Fremd-Login%202FA"
list_exec() { kc get "authentication/flows/$FLOW/executions" -r "$REALM" --fields id,providerId,requirement --format csv --noquotes; }
if list_exec | grep -q ',allow-access-authenticator,REQUIRED'; then
  echo "✓ Google-Login ohne 2FA: bereits korrigiert."
else
  if ! list_exec | grep -q ',allow-access-authenticator,'; then
    kc create "authentication/flows/$FLOW/executions/execution" -r "$REALM" -s provider=allow-access-authenticator >/dev/null
  fi
  ID=$(list_exec | grep ',allow-access-authenticator,' | cut -d, -f1 | tr -d '\r')
  kc update "authentication/flows/$FLOW/executions" -r "$REALM" -b "{\"id\":\"$ID\",\"requirement\":\"REQUIRED\"}"
  echo "✓ Google-Login ohne 2FA: korrigiert."
fi

# --- 19.11.2026: Portal-Anmeldung über Keycloak ------------------------------------------------------
PORTAL_ID=$(kc get clients -r "$REALM" -q clientId=portal --fields id --format csv --noquotes | tr -d '\r')
kc update "clients/$PORTAL_ID" -r "$REALM" \
  -s "rootUrl=$PORTAL_URL" \
  -s "redirectUris=[\"$PORTAL_URL/portal/auth/callback\"]" \
  -s "webOrigins=[\"$PORTAL_URL\"]" \
  -s "attributes.\"post.logout.redirect.uris\"=$PORTAL_URL/*##$SITE_URL/*" \
  -s "attributes.\"sfa.forgot.url\"=$PORTAL_URL/portal/forgot-password" \
  -s "attributes.\"sfa.join.url\"=$SITE_URL/beitreten" \
  -s 'attributes."backchannel.logout.url"=http://webapp/api/kc/backchannel-logout' \
  -s 'attributes."backchannel.logout.session.required"=true'
echo "✓ Client portal: Rücksprung, Abmeldung und Links aktualisiert."
kc update "realms/$REALM" -s loginTheme=stromfueralle
echo "✓ Login-Seite im Plattform-Design."
kc add-roles -r "$REALM" --uusername service-account-portal --cclientid realm-management --rolename view-identity-providers >/dev/null
echo "✓ Dienstkonto portal sieht, ob Google eingeschaltet ist."
for c in account-console account; do
  CID=$(kc get clients -r "$REALM" -q clientId=$c --fields id --format csv --noquotes | tr -d '\r')
  [ -n "$CID" ] && kc update "clients/$CID" -r "$REALM" -s enabled=false
done
echo "✓ Keycloak-Kontoseite aus (Verwaltung im Portal-Profil)."

echo "Fertig."
