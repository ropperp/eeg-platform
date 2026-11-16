#!/usr/bin/env bash
# HTTP-Header-Test gegen einen laufenden webapp-Container (ohne Datenbank reicht).
#   BASE_URL=http://localhost:18080 bash tests/http/headers_test.sh
# Prüft: dynamisches HTML/JSON nie gzip (BREACH), statische Assets gzip, "immutable" nur mit ?v=,
# Security-Header auch auf statischen Dateien (nginx erbt add_header sonst nicht).
set -uo pipefail
BASE_URL="${BASE_URL:-http://localhost:18080}"
fail=0; pass=0

headers() { curl -s -o /dev/null -D - -H 'Accept-Encoding: gzip' "${BASE_URL}$1" | tr -d '\r'; }

expect() { # expect <Beschreibung> <Pfad> <grep -i Muster> [nicht]
  local desc="$1" path="$2" pattern="$3" negate="${4:-}" h
  h=$(headers "$path")
  if { [ -z "$negate" ] && grep -qiE "$pattern" <<<"$h"; } || { [ -n "$negate" ] && ! grep -qiE "$pattern" <<<"$h"; }; then
    pass=$((pass + 1)); echo "  ✓ ${desc}"
  else
    fail=$((fail + 1)); echo "  ✗ ${desc} (${path})"; sed 's/^/      /' <<<"$h"
  fi
}

expect "HTML (PHP) wird nicht komprimiert"            /                          '^content-encoding: *gzip' nicht
expect "JSON (API) wird nicht komprimiert"            /api/v1/me                 '^content-encoding: *gzip' nicht
expect "CSS wird komprimiert"                         /assets/css/app.css        '^content-encoding: *gzip'
expect "JavaScript wird komprimiert"                  /assets/js/ui-actions.js   '^content-encoding: *gzip'
expect "Asset mit ?v= ist immutable"                  '/assets/css/app.css?v=1'  '^cache-control: .*immutable'
expect "Asset ohne ?v= ist NICHT immutable"           /assets/css/app.css        '^cache-control: .*immutable' nicht
expect "Asset ohne ?v= wird kurz gecacht"             /assets/css/app.css        '^cache-control: .*must-revalidate'
expect "nosniff auch auf statischen Dateien"          /assets/js/ui-actions.js   '^x-content-type-options: *nosniff'
expect "HSTS auch auf statischen Dateien"             /assets/css/app.css        '^strict-transport-security:'
expect "nosniff auf dynamischen Seiten"               /                          '^x-content-type-options: *nosniff'
expect "Server verrät keine nginx-Version"            /                          '^server: nginx/' nicht

echo ""
if [ "$fail" -eq 0 ]; then echo "✓ Alle ${pass} Header-Tests bestanden"; else echo "✗ ${fail} von $((pass + fail)) Header-Tests fehlgeschlagen"; exit 1; fi
