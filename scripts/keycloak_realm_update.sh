#!/usr/bin/env bash
# Bringt einen bereits angelegten Keycloak-Realm auf den Stand von keycloak/realm/stromfueralle-realm.json.
# Nötig, weil Keycloak die Realm-Datei nur beim ERSTEN Start liest (docs/adr/0007-keycloak.md).
# Sicher mehrfach ausführbar: jede Korrektur prüft zuerst, ob sie schon da ist.
#
# Korrekturen:
#   18.11.2026  Google-Login ohne 2FA scheiterte ("Ungültiger Benutzername oder Passwort"): Der Ablauf
#               "nach Fremd-Login 2FA" hatte für Konten ohne 2FA keinen erfolgreichen Schritt. Neu: am
#               Ende "Allow access" (REQUIRED). Wer 2FA hat, muss den Code weiterhin eingeben.
#
# Aufruf (auf dem Server, im Repo-Root):  ./scripts/keycloak_realm_update.sh
# Fragt nach Benutzername und Passwort eines Admins im master-Realm (wie in der Admin-Konsole).

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

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
  kc config credentials --server http://localhost:8080 --realm master --user "$ADMIN" --password "$KC_ADMIN_PASSWORD" >/dev/null
else
  # Passwort fragt kcadm selbst ab (braucht ein Terminal, deshalb ohne -T).
  "${EXEC[@]}" "$SVC" /opt/keycloak/bin/kcadm.sh config credentials --config "$CFG" \
    --server http://localhost:8080 --realm master --user "$ADMIN" >/dev/null
fi
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

echo "Fertig."
