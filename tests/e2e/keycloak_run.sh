#!/usr/bin/env bash
# Anmeldedienst Keycloak gegen ECHTES Keycloak + TimescaleDB (docs/adr/0007-keycloak.md):
# eigenes Keycloak-Image (mit bcrypt-Erweiterung) bauen, Realm importieren, Plattform-Konten mit
# Argon2id-, bcrypt- und 2FA-Daten über webapp/bin/keycloak_import_users.php übernehmen und dann
# prüfen, dass sich alle mit ihrem BISHERIGEN Passwort anmelden können (stille Übernahme), bcrypt
# danach als Argon2 gespeichert ist, 2FA weiter verlangt wird und die öffentliche Route keine
# Admin-Konsole zeigt. Lokal: bash tests/e2e/keycloak_run.sh
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
P=kc-e2e; NET=$P-net; KC_PORT="${KC_E2E_PORT:-18280}"; WEB_PORT="${KC_E2E_WEB_PORT:-18290}"
WEB=http://localhost:$WEB_PORT; KZ_PORT="${KC_E2E_KZ_PORT:-18295}"; KZ=http://localhost:$KZ_PORT
DB_IMAGE="${TEST_PG_IMAGE:-timescale/timescaledb:2.17.2-pg16}"
REALM=stromfueralle; PORTAL_SECRET=portal-e2e-secret; APP_SECRET_KC=app-e2e-secret

cleanup() {
  if [ "${E2E_KEEP:-}" != 1 ]; then
    docker rm -f $P-db $P-kc $P-redis $P-web $P-kz >/dev/null 2>&1 || true
    docker network rm $NET >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT
cleanup

echo "Images bauen ..."
docker build -q -t $P-kc ./keycloak >/dev/null
docker build -q -f webapp/Dockerfile -t $P-webapp . >/dev/null
docker build -q -f konsole/Dockerfile -t $P-konsole . >/dev/null

docker network create $NET >/dev/null
docker run -d --name $P-db --network $NET -e POSTGRES_USER=eeg -e POSTGRES_PASSWORD=test -e POSTGRES_DB=eeg_platform "$DB_IMAGE" >/dev/null
until docker exec $P-db pg_isready -U eeg -d eeg_platform -h 127.0.0.1 >/dev/null 2>&1; do sleep 1; done
sleep 2
PSQL="docker exec -i $P-db psql -U eeg -d eeg_platform" bash tests/integration/prepare_db.sh
docker exec -i $P-db psql -q -U eeg -d eeg_platform -v ON_ERROR_STOP=1 -v kc_pass=kc-test < scripts/sql/keycloak_db.sql >/dev/null

docker run -d --name $P-kc --network $NET --network-alias keycloak -p "$KC_PORT:8080" \
  --cap-drop ALL --security-opt no-new-privileges:true \
  -e KC_DB_URL=jdbc:postgresql://$P-db:5432/keycloak -e KC_DB_USERNAME=keycloak -e KC_DB_PASSWORD=kc-test \
  -e KC_HOSTNAME=http://localhost:$KC_PORT -e KC_HOSTNAME_BACKCHANNEL_DYNAMIC=true -e KC_HTTP_ENABLED=true \
  -e KC_BOOTSTRAP_ADMIN_USERNAME=admin -e KC_BOOTSTRAP_ADMIN_PASSWORD=admin \
  -e DOMAIN=stromfueralle.test -e PORTAL_HOST=portal.stromfueralle.test -e KC_PORTAL_URL=$WEB -e KC_SITE_URL=$WEB -e KC_KONSOLE_URL=$KZ \
  -e KC_PORTAL_CLIENT_SECRET=$PORTAL_SECRET -e KC_APP_CLIENT_SECRET=$APP_SECRET_KC -e KC_KONSOLE_CLIENT_SECRET=konsole-e2e-secret \
  $P-kc >/dev/null

# Testkonten: Hashes und 2FA-Secret mit dem PHP der Plattform erzeugen (wie im echten Betrieb).
PW='Altes-Passwort-1'
PHPRUN=(docker run --rm --network $NET --entrypoint php $P-webapp)
read -r ARGON BCRYPT TOTP < <("${PHPRUN[@]}" -r 'require "/var/www/html/src/functions.php";
  echo password_hash($argv[1], PASSWORD_ARGON2ID, ["memory_cost"=>65536,"time_cost"=>3,"threads"=>1]), " ",
       password_hash($argv[1], PASSWORD_BCRYPT, ["cost"=>12]), " ", totpGenerateSecret(), "\n";' "$PW")
docker exec -i $P-db psql -q -U eeg -d eeg_platform -v ON_ERROR_STOP=1 \
  -v argon="$ARGON" -v bcrypt="$BCRYPT" -v totp="$TOTP" -v enc_totp="$(docker run --rm -e APP_SECRET=e2e --entrypoint php $P-webapp -r 'require "/var/www/html/src/functions.php"; echo encryptSecret($argv[1]);' "$TOTP")" >/dev/null <<'SQL'
INSERT INTO users (email, password_hash, first_name, last_name) VALUES ('argon@e2e.test', :'argon', 'Ar', 'Gon');
INSERT INTO users (email, password_hash, first_name, last_name) VALUES ('bcrypt@e2e.test', :'bcrypt', 'Be', 'Crypt');
INSERT INTO users (email, password_hash, first_name, last_name, totp_enabled, totp_secret) VALUES ('totp@e2e.test', :'bcrypt', 'To', 'Tp', true, :'enc_totp');
INSERT INTO users (email, password_hash, first_name, last_name, active) VALUES ('inaktiv@e2e.test', :'argon', 'In', 'Aktiv', false);
INSERT INTO users (email, password_hash, first_name, last_name) VALUES ('admin@e2e.test', :'argon', 'Ad', 'Min');
INSERT INTO user_roles (community_id, user_id, role) SELECT NULL, id, 'platform_admin' FROM users WHERE email = 'admin@e2e.test';
SQL

echo -n "Keycloak startet "
for _ in $(seq 1 120); do curl -fs "http://localhost:$KC_PORT/realms/$REALM" >/dev/null && break; echo -n .; sleep 2; done; echo

pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); echo "  ✓ $1"; else fail=$((fail + 1)); echo "  ✗ $1 (erwartet '$3', war '$2')"; fi; }
TOKEN_URL="http://localhost:$KC_PORT/realms/$REALM/protocol/openid-connect/token"
# login <email> <passwort> [totp] -> "ok" oder Keycloak-Fehlercode
login() {
  curl -s "$TOKEN_URL" -d grant_type=password -d client_id=app -d client_secret=$APP_SECRET_KC -d scope=openid \
    --data-urlencode "username=$1" --data-urlencode "password=$2" ${3:+--data-urlencode "totp=$3"} \
    | python3 -c 'import sys,json; d=json.load(sys.stdin); print("ok" if "access_token" in d else d.get("error","?"))'
}
IMPORT=(docker run --rm --network $NET -e DB_HOST=$P-db -e DB_NAME=eeg_platform -e APP_ENV=production
  -e APP_DB_USER=eeg_app -e APP_DB_PASSWORD=app -e APP_SECRET=e2e
  -e KEYCLOAK_INTERNAL_URL=http://keycloak:8080 -e KC_PORTAL_CLIENT_SECRET=$PORTAL_SECRET
  --entrypoint php $P-webapp /var/www/html/bin/keycloak_import_users.php)

echo "Keycloak: Übernahme der Konten"
OUT=$("${IMPORT[@]}" --dry-run)
check "Probelauf ändert nichts" "$(docker exec $P-db psql -tA -U eeg -d eeg_platform -c 'SELECT count(*) FROM users WHERE keycloak_id IS NOT NULL')" "0"
check "Probelauf erkennt bcrypt-Konten" "$(echo "$OUT" | awk '$1=="bcrypt"{print $2}')" "2"
OUT=$("${IMPORT[@]}") || { echo "$OUT"; exit 1; }
check "Import legt alle Konten an" "$(echo "$OUT" | awk '$1=="angelegt"{print $2}')" "5"
check "Import übernimmt 2FA" "$(echo "$OUT" | awk '$1=="totp"{print $2}')" "1"
OUT=$("${IMPORT[@]}")
check "zweiter Lauf: nichts mehr zu tun" "$(echo "$OUT" | head -1 | awk '{print $1}')" "0"

echo "Keycloak: Anmeldung mit dem bisherigen Passwort"
check "Argon2id-Konto"                 "$(login argon@e2e.test "$PW")" "ok"
check "bcrypt-Konto (Erweiterung)"     "$(login bcrypt@e2e.test "$PW")" "ok"
check "bcrypt-Konto zweites Mal"       "$(login bcrypt@e2e.test "$PW")" "ok"
check "falsches Passwort abgelehnt"    "$(login bcrypt@e2e.test "falsch")" "invalid_grant"
check "deaktiviertes Konto abgelehnt"  "$(login inaktiv@e2e.test "$PW")" "invalid_grant"
check "2FA-Konto ohne Code abgelehnt"  "$(login totp@e2e.test "$PW")" "invalid_grant"
CODE=$("${PHPRUN[@]}" -r 'require "/var/www/html/src/functions.php"; echo totpCodeAt($argv[1], time());' "$TOTP")
check "2FA-Konto mit Code"             "$(login totp@e2e.test "$PW" "$CODE")" "ok"

echo "Keycloak: stille Umstellung und Rollen"
SA=$(curl -s "$TOKEN_URL" -d grant_type=client_credentials -d client_id=portal -d client_secret=$PORTAL_SECRET -d scope=kc-admin-api | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')
KID=$(docker exec $P-db psql -tA -U eeg -d eeg_platform -c "SELECT keycloak_id FROM users WHERE email='bcrypt@e2e.test'")
ALG=$(curl -s -H "Authorization: Bearer $SA" "http://localhost:$KC_PORT/admin/realms/$REALM/users/$KID/credentials" \
  | python3 -c 'import sys,json; print([json.loads(c["credentialData"])["algorithm"] for c in json.load(sys.stdin) if c["type"]=="password"][0])')
check "bcrypt nach erstem Login als Argon2 gespeichert" "$ALG" "argon2"
ROLES=$(curl -s "$TOKEN_URL" -d grant_type=password -d client_id=app -d client_secret=$APP_SECRET_KC -d username=admin@e2e.test --data-urlencode "password=$PW" \
  | python3 -c 'import sys,json,base64; t=json.load(sys.stdin)["access_token"].split(".")[1]; t+="="*(-len(t)%4); print("platform_admin" in json.loads(base64.urlsafe_b64decode(t))["realm_access"]["roles"])')
check "Platform-Admin bekommt Rolle platform_admin" "$ROLES" "True"
check "Dienstkonto portal darf keine Realm-Einstellungen ändern" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X PUT -H "Authorization: Bearer $SA" -H 'Content-Type: application/json' -d '{"bruteForceProtected":false}' "http://localhost:$KC_PORT/admin/realms/$REALM")" "403"

echo "Keycloak: Realm-Update-Skript (bestehender Realm)"
UPD=$(KC_SKIP_ENV=1 DOMAIN=stromfueralle.test KC_PORTAL_URL=$WEB KC_SITE_URL=$WEB KC_KONSOLE_URL=$KZ KC_EXEC="docker exec" KC_NOTTY=-i \
  KC_SERVICE=$P-kc KC_ADMIN_USER=admin KC_ADMIN_PASSWORD=admin ./scripts/keycloak_realm_update.sh 2>&1) || { echo "$UPD"; exit 1; }
check "Update-Skript läuft durch" "$(echo "$UPD" | tail -1)" "Fertig."
UPD=$(KC_SKIP_ENV=1 DOMAIN=stromfueralle.test KC_PORTAL_URL=$WEB KC_SITE_URL=$WEB KC_KONSOLE_URL=$KZ KC_EXEC="docker exec" KC_NOTTY=-i \
  KC_SERVICE=$P-kc KC_ADMIN_USER=admin KC_ADMIN_PASSWORD=admin ./scripts/keycloak_realm_update.sh 2>&1) || { echo "$UPD"; exit 1; }
check "Update-Skript ein zweites Mal (idempotent)" "$(echo "$UPD" | tail -1)" "Fertig."
check "Keycloak-Kontoseite ist aus" "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$KC_PORT/realms/$REALM/account/")" "404"

echo "Portal-Anmeldung über Keycloak (AUTH_PROVIDER=keycloak)"
docker run -d --name $P-redis --network $NET redis:7.4.12-alpine >/dev/null
start_web() {
  docker rm -f $P-web >/dev/null 2>&1 || true
  docker run -d --name $P-web --network $NET --network-alias webapp -p "$WEB_PORT:80" \
    -e DB_HOST=$P-db -e DB_NAME=eeg_platform -e APP_ENV=production -e APP_DB_USER=eeg_app -e APP_DB_PASSWORD=app \
    -e REDIS_HOST=$P-redis -e APP_SECRET=e2e -e DOMAIN=localhost -e AUTH_PROVIDER="$1" \
    -e KEYCLOAK_INTERNAL_URL=http://keycloak:8080 -e KC_PORTAL_CLIENT_SECRET=$PORTAL_SECRET \
    -e KEYCLOAK_PUBLIC_URL=http://localhost:$KC_PORT -e KEYCLOAK_PORTAL_URL=$WEB -e KEYCLOAK_POST_LOGOUT_URL=$WEB/ \
    $P-webapp >/dev/null
  for _ in $(seq 1 30); do curl -fs "$WEB/health" >/dev/null && break; sleep 1; done
}
start_web keycloak
docker run -d --name $P-kz --network $NET --network-alias konsole -p "$KZ_PORT:8090" --tmpfs /data \
  -e APP_SECRET="$(printf 'k%.0s' $(seq 1 64))" -e DOMAIN=localhost -e KONSOLE_HOST=localhost -e KONSOLE_DEV=1 \
  -e KONSOLE_BASE_URL=$KZ -e KC_KONSOLE_CLIENT_SECRET=konsole-e2e-secret -e KEYCLOAK_PUBLIC_URL=http://localhost:$KC_PORT \
  -e KEYCLOAK_INTERNAL_URL=http://keycloak:8080 -e DB_HOST=$P-db -e DB_NAME=eeg_platform -e APP_DB_USER=eeg_app -e APP_DB_PASSWORD=app \
  -e KONSOLE_SERVICES=$P-web $P-konsole >/dev/null
for _ in $(seq 1 30); do curl -fs "$KZ/health" >/dev/null && break; sleep 1; done
# Wie auf dem Server: vor dem Umschalten importierte Konten haben noch keinen Abgleich-Stand.
docker exec $P-db psql -q -U eeg -d eeg_platform -c 'UPDATE users SET keycloak_state = NULL' >/dev/null
SYNC=(docker exec $P-web php /var/www/html/bin/keycloak_sync.php)
IMPORT_ALL=(docker exec $P-web php /var/www/html/bin/keycloak_import_users.php --all)
OUT=$("${IMPORT_ALL[@]}") || { echo "$OUT"; exit 1; }
check "Umschalten: alle Konten einmal abgeglichen" "$(echo "$OUT" | awk '$1=="aktualisiert"{print $2}')" "5"
OUT=$("${IMPORT_ALL[@]}")
check "zweiter Abgleich: nichts zu tun" "$(echo "$OUT" | awk '$1=="unveraendert"{print $2}')" "5"

export COOKIES=$(mktemp)
B=(python3 tests/e2e/keycloak_browser.py "$WEB")
PAGE=$("${B[@]}" loginpage)
check "Login-Seite im Plattform-Design" "$(echo "$PAGE" | grep -q 'css/stromfueralle.css' && echo ja)" "ja"
check "Login-Seite: Passwort vergessen führt ins Portal" "$(echo "$PAGE" | grep -o "href=\"$WEB/portal/forgot-password\"" | head -1)" "href=\"$WEB/portal/forgot-password\""
rm -f "$COOKIES"
# Konto mit Rolle (ohne Rolle hat das Dashboard nichts zu zeigen): der Platform-Admin.
check "Anmeldung über Keycloak" "$("${B[@]}" login admin@e2e.test "$PW")" "ok"
check "danach im Dashboard" "$("${B[@]}" dashboard)" "drin"
K=(python3 tests/e2e/keycloak_browser.py "$KZ")
check "Steuerzentrale: ohne erneute Anmeldung drin" "$("${K[@]}" konsole)" "drin"
check "Abmelden (Portal und Keycloak)" "$("${B[@]}" logout)" "ok"
check "nach dem Abmelden draußen" "$("${B[@]}" dashboard)" "draussen"
check "Keycloak fragt nach dem Abmelden wieder" "$("${B[@]}" loginpage | grep -c 'id="kc-form-login"')" "1"
check "Abmelden im Portal: Steuerzentrale auch abgemeldet" "$("${K[@]}" konsole)" "anmeldeseite"

echo "Steuerzentrale über Keycloak"
rm -f "$COOKIES"
check "ohne Rolle platform_admin kein Zugang" "$("${K[@]}" konsole-login argon@e2e.test "$PW")" "kein-zugang"
rm -f "$COOKIES"
check "Platform-Admin direkt an der Steuerzentrale" "$("${K[@]}" konsole-login admin@e2e.test "$PW")" "drin"
check "danach auch im Portal ohne Eingabe" "$("${B[@]}" login admin@e2e.test "$PW")" "ok"
check "Abmelden in der Steuerzentrale" "$("${K[@]}" konsole-logout)" "ok"
check "Portal danach auch abgemeldet" "$("${B[@]}" dashboard)" "draussen"
check "Steuerzentrale danach: Keycloak fragt wieder (keine stille Neuanmeldung)" "$("${K[@]}" konsole)" "anmeldeseite"

echo "Abmelden in der Steuerzentrale, während das Portal offen ist"
rm -f "$COOKIES"
check "Anmeldung im Portal" "$("${B[@]}" login admin@e2e.test "$PW")" "ok"
check "Steuerzentrale ohne Eingabe" "$("${K[@]}" konsole)" "drin"
check "im Portal etwas tun (Sitzung aktiv)" "$("${B[@]}" dashboard)" "drin"
check "Abmelden in der Steuerzentrale" "$("${K[@]}" konsole-logout)" "ok"
check "Steuerzentrale: Keycloak fragt wieder" "$("${K[@]}" konsole)" "anmeldeseite"
check "Portal: abgemeldet" "$("${B[@]}" dashboard)" "draussen"
check "Passkey-Notzugang bleibt erreichbar" "$(curl -s "$KZ/?passkey=1" | grep -c 'data-login')" "1"

echo "Abgleich Portal -> Keycloak"
psql_e2e() { docker exec -i $P-db psql -tA -U eeg -d eeg_platform -v ON_ERROR_STOP=1 "$@"; }
NEWPW='Neues-Passwort-2'
NEWHASH=$("${PHPRUN[@]}" -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' "$NEWPW")
psql_e2e -c "UPDATE users SET password_hash = '$NEWHASH' WHERE email = 'argon@e2e.test'" >/dev/null
"${SYNC[@]}" >/dev/null
check "Passwort im Portal geändert: neues gilt"   "$(login argon@e2e.test "$NEWPW")" "ok"
check "Passwort im Portal geändert: altes nicht"  "$(login argon@e2e.test "$PW")" "invalid_grant"
psql_e2e -c "UPDATE users SET active = false WHERE email = 'bcrypt@e2e.test'" >/dev/null
"${SYNC[@]}" >/dev/null
check "Konto gesperrt: Keycloak lehnt ab" "$(login bcrypt@e2e.test "$PW")" "invalid_grant"
psql_e2e -c "UPDATE users SET email = 'neu@e2e.test' WHERE email = 'admin@e2e.test'" >/dev/null
"${SYNC[@]}" >/dev/null
check "E-Mail geändert: Anmeldung mit neuer Adresse" "$(login neu@e2e.test "$PW")" "ok"
psql_e2e -c "INSERT INTO users (email, password_hash, first_name, last_name) VALUES ('frisch@e2e.test', '$NEWHASH', 'Fr', 'Isch')" >/dev/null
"${SYNC[@]}" >/dev/null
check "neues Konto im Portal: sofort in Keycloak" "$(login frisch@e2e.test "$NEWPW")" "ok"
GONE=$(psql_e2e -c "SELECT keycloak_id FROM users WHERE email = 'frisch@e2e.test'")
psql_e2e -c "DELETE FROM users WHERE email = 'frisch@e2e.test'" >/dev/null
"${SYNC[@]}" >/dev/null
check "Konto im Portal gelöscht: auch in Keycloak weg" \
  "$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $(curl -s "$TOKEN_URL" -d grant_type=client_credentials -d client_id=portal -d client_secret=$PORTAL_SECRET -d scope=kc-admin-api | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')" "http://localhost:$KC_PORT/admin/realms/$REALM/users/$GONE")" "404"
check "Warteschlange leer" "$(psql_e2e -c 'SELECT count(*) FROM keycloak_sync_queue')" "0"

echo "Abmelden von außen (Backchannel)"
rm -f "$COOKIES"
check "Anmeldung (Platform-Admin mit neuer E-Mail)" "$("${B[@]}" login neu@e2e.test "$PW")" "ok"
SA=$(curl -s "$TOKEN_URL" -d grant_type=client_credentials -d client_id=portal -d client_secret=$PORTAL_SECRET -d scope=kc-admin-api | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')
AID=$(psql_e2e -c "SELECT keycloak_id FROM users WHERE email = 'neu@e2e.test'")
curl -s -o /dev/null -X POST -H "Authorization: Bearer $SA" "http://localhost:$KC_PORT/admin/realms/$REALM/users/$AID/logout"
sleep 1
check "Keycloak-Sitzung beendet: Portal-Sitzung auch" "$("${B[@]}" dashboard)" "draussen"

echo "Testmodus (AUTH_PROVIDER=keycloak-test)"
start_web keycloak-test
check "normaler Login bleibt der bisherige" "$(curl -s -o /dev/null -w '%{http_code}' "$WEB/portal/login")" "200"
check "mit ?keycloak=1 zu Keycloak" "$(curl -s -o /dev/null -w '%{redirect_url}' "$WEB/portal/login?keycloak=1" | grep -c "localhost:$KC_PORT/realms/$REALM/")" "1"
rm -f "$COOKIES"
check "Anmeldung im Testmodus über Keycloak" "$(LOGIN_PATH='/portal/login?keycloak=1' "${B[@]}" login neu@e2e.test "$PW")" "ok"
start_web keycloak

echo "Rückfall, wenn Keycloak ausfällt"
docker stop $P-kc >/dev/null
check "Login-Seite des Portals mit Hinweis" "$(curl -s "$WEB/portal/login" | grep -c 'Anmeldedienst ist gerade nicht erreichbar')" "1"
check "Sync bei ausgefallenem Keycloak hängt nicht und behält die Arbeit" \
  "$(psql_e2e -c "UPDATE users SET first_name = 'Neu' WHERE email = 'argon@e2e.test'" >/dev/null; timeout 30 "${SYNC[@]}" >/dev/null 2>&1; psql_e2e -c 'SELECT count(*) FROM keycloak_sync_queue')" "1"
rm -f "$COOKIES"

echo ""
echo "Keycloak-E2E: $pass bestanden, $fail fehlgeschlagen"
[ "$fail" -eq 0 ]
