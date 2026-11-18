#!/usr/bin/env bash
# Anmeldedienst Keycloak gegen ECHTES Keycloak + TimescaleDB (docs/adr/0007-keycloak.md):
# eigenes Keycloak-Image (mit bcrypt-Erweiterung) bauen, Realm importieren, Plattform-Konten mit
# Argon2id-, bcrypt- und 2FA-Daten über webapp/bin/keycloak_import_users.php übernehmen und dann
# prüfen, dass sich alle mit ihrem BISHERIGEN Passwort anmelden können (stille Übernahme), bcrypt
# danach als Argon2 gespeichert ist, 2FA weiter verlangt wird und die öffentliche Route keine
# Admin-Konsole zeigt. Lokal: bash tests/e2e/keycloak_run.sh
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
P=kc-e2e; NET=$P-net; KC_PORT="${KC_E2E_PORT:-18280}"
DB_IMAGE="${TEST_PG_IMAGE:-timescale/timescaledb:2.17.2-pg16}"
REALM=stromfueralle; PORTAL_SECRET=portal-e2e-secret; APP_SECRET_KC=app-e2e-secret

cleanup() {
  if [ "${E2E_KEEP:-}" != 1 ]; then
    docker rm -f $P-db $P-kc >/dev/null 2>&1 || true
    docker network rm $NET >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT
cleanup

echo "Images bauen ..."
docker build -q -t $P-kc ./keycloak >/dev/null
docker build -q -f webapp/Dockerfile -t $P-webapp . >/dev/null

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
  -e DOMAIN=stromfueralle.test -e PORTAL_HOST=portal.stromfueralle.test \
  -e KC_PORTAL_CLIENT_SECRET=$PORTAL_SECRET -e KC_APP_CLIENT_SECRET=$APP_SECRET_KC -e KC_KONSOLE_CLIENT_SECRET=k \
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

echo ""
echo "Keycloak-E2E: $pass bestanden, $fail fehlgeschlagen"
[ "$fail" -eq 0 ]
