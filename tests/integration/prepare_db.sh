#!/usr/bin/env bash
# Bereitet eine leere Test-Datenbank für tests/integration/run.php vor: Schema, alle Migrationen
# (in derselben Reihenfolge wie scripts/setup.sh) und die Laufzeit-Rollen aus
# scripts/sql/runtime_roles.sql. Bricht bei jedem SQL-Fehler ab.
#
# PSQL: psql-Aufruf als Schema-Besitzer, z. B.
#   PSQL="psql -h localhost -U eeg -d eeg_platform"                  (CI)
#   PSQL="docker exec -i eeg-pgtest psql -U eeg -d eeg_platform"     (lokal, siehe Makefile)
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

PSQL="${PSQL:-psql}"
run() { $PSQL -q -v ON_ERROR_STOP=1 "$@" >/dev/null; }
# Datei ohne NOTICE-Rauschen einspielen (Migrationen sind idempotent und melden viel "skipping").
apply() { { echo "SET client_min_messages = warning;"; cat "$1"; } | run; }

apply database/init.sql
for f in database/migrate_*.sql; do
  apply "$f"
done
run -v app_user="${TEST_APP_USER:-eeg_app}" -v app_pass="${TEST_APP_USER_PASSWORD:-app}" \
    -v worker_user="${TEST_WORKER_USER:-eeg_worker}" -v worker_pass="${TEST_WORKER_USER_PASSWORD:-worker}" \
    -v dbname="${TEST_DB_NAME:-eeg_platform}" < scripts/sql/runtime_roles.sql
echo "✓ Test-Datenbank vorbereitet (Schema, $(ls database/migrate_*.sql | wc -l | tr -d ' ') Migrationen, Rollen)."
