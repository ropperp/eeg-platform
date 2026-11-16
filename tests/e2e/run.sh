#!/usr/bin/env bash
# Baut einen Wegwerf-Stack in Docker auf (TimescaleDB, Redis, Webapp, eda-worker), spielt Schema,
# Migrationen, Rollen und Testdaten ein und startet tests/e2e/app_flows_test.sh. Räumt danach auf.
# Lokal: make test-e2e. CI: Job "e2e". Port der Webapp: E2E_PORT (Standard 18100).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
P=eeg-e2e; NET=$P-net; PORT="${E2E_PORT:-18100}"
DB_IMAGE="${TEST_PG_IMAGE:-timescale/timescaledb:2.17.2-pg16}"

cleanup() {
  if [ "${E2E_KEEP:-}" != 1 ]; then
    docker rm -f $P-db $P-redis $P-web $P-worker >/dev/null 2>&1 || true
    docker volume rm $P-storage >/dev/null 2>&1 || true
    docker network rm $NET >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT
cleanup

echo "Images bauen ..."
docker build -q -f webapp/Dockerfile -t $P-webapp . >/dev/null
docker build -q -t $P-worker ./eda-parser >/dev/null

docker network create $NET >/dev/null
docker volume create $P-storage >/dev/null
docker run -d --name $P-db --network $NET -e POSTGRES_USER=eeg -e POSTGRES_PASSWORD=test -e POSTGRES_DB=eeg_platform "$DB_IMAGE" >/dev/null
docker run -d --name $P-redis --network $NET redis:7.4.12-alpine >/dev/null
until docker exec $P-db pg_isready -U eeg -d eeg_platform -h 127.0.0.1 >/dev/null 2>&1; do sleep 1; done
sleep 2
PSQL="docker exec -i $P-db psql -U eeg -d eeg_platform" bash tests/integration/prepare_db.sh
docker exec -i $P-db psql -q -U eeg -d eeg_platform -v ON_ERROR_STOP=1 < tests/e2e/seed.sql

COMMON=(--network $NET -e DB_HOST=$P-db -e DB_NAME=eeg_platform)
# Speicher-Volume: das Webapp-Image legt storage/uploads mit www-data (82) an, Docker übernimmt das.
docker run -d --name $P-web "${COMMON[@]}" -p "$PORT:80" -v $P-storage:/var/www/html/storage \
  -e APP_ENV=production -e DOMAIN=stromfueralle.at -e APP_DB_USER=eeg_app -e APP_DB_PASSWORD=app \
  -e REDIS_HOST=$P-redis -e APP_SECRET="$(printf '0%.0s' $(seq 1 64))" $P-webapp >/dev/null
docker run -d --name $P-worker "${COMMON[@]}" --user 82:82 --read-only --tmpfs /tmp \
  -v $P-storage:/var/www/html/storage:ro -e DB_USER=eeg_worker -e DB_PASSWORD=worker \
  -e EDA_WORKER_POLL_SECONDS=1 $P-worker >/dev/null
for _ in $(seq 1 30); do curl -fs "http://localhost:$PORT/health" >/dev/null && break; sleep 1; done

# Synthetische EDA-Monatsdatei (fiktive Zählpunkte) mit dem Worker-Image erzeugen (dort ist openpyxl).
FIX_DIR=$(mktemp -d); chmod 777 "$FIX_DIR"
docker run --rm -v "$PWD/tests/fixtures:/fx:ro" -v "$FIX_DIR:/out" --entrypoint python $P-worker \
  /fx/make_eda_monthly.py /out/eda_monat.xlsx 2026-01

rc=0
BASE="http://localhost:$PORT" E2E_EDA_FIXTURE="$FIX_DIR/eda_monat.xlsx" bash tests/e2e/app_flows_test.sh || rc=$?
rm -rf "$FIX_DIR"
if [ $rc -ne 0 ]; then
  echo "--- Log webapp ---"; docker logs --tail 40 $P-web 2>&1 | grep -v '" 200\|" 302' || true
  echo "--- Log eda-worker ---"; docker logs --tail 40 $P-worker 2>&1 || true
fi
exit $rc
