#!/usr/bin/env bash
# Steuerzentrale (konsole) gegen ECHTES Docker und ECHTES Traefik: Wegwerf-Webserver + Traefik mit
# Ordner-Konfiguration + konsole mit Docker-Socket. Prüft Neustart, Logs, Dienst-Liste,
# Wartungsmodus (Traefik schaltet auf die Wartungsseite und zurück), CSRF und "Webapp weg,
# Konsole lebt". Die Passkey-Anmeldung selbst prüft tests/konsole_test.php; hier wird ein
# gültiges Sitzungs-Cookie mit dem Test-Secret erzeugt. Lokal: bash tests/e2e/konsole_run.sh
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
P=kz-e2e; NET=$P-net; WEB_PORT="${KZ_WEB_PORT:-18180}"; K_PORT="${KZ_PORT:-18190}"
SECRET=$(printf 'x%.0s' $(seq 1 64))
T=$(mktemp -d); mkdir -p "$T/data" "$T/traefik"; chmod -R 777 "$T"

cleanup() {
  docker rm -f $P-web $P-traefik $P-konsole >/dev/null 2>&1 || true
  docker network rm $NET >/dev/null 2>&1 || true
  rm -rf "$T" 2>/dev/null || true
}
trap cleanup EXIT
cleanup; mkdir -p "$T/data" "$T/traefik"; chmod -R 777 "$T"

cat > "$T/traefik/dynamic.yml" <<EOF
http:
  routers:
    main:
      rule: "Host(\`stromfueralle.at\`)"
      entryPoints: ["web"]
      service: web
  services:
    web:
      loadBalancer:
        servers:
          - url: "http://$P-web:80"
EOF

TRAEFIK_IMAGE=$(grep -oE 'image: traefik:[^ ]+' docker-compose.yml | head -1 | cut -d' ' -f2)
echo "Images bauen ..."
docker build -q -f konsole/Dockerfile -t $P-konsole . >/dev/null
docker network create $NET >/dev/null
docker run -d --name $P-web --network $NET --entrypoint python python:3.12.16-slim -m http.server 80 >/dev/null
docker run -d --name $P-traefik --network $NET -p "$WEB_PORT:80" -v "$T/traefik:/etc/traefik/dynamic:ro" "$TRAEFIK_IMAGE" \
  --providers.file.directory=/etc/traefik/dynamic --providers.file.watch=true --entrypoints.web.address=:80 >/dev/null
docker run -d --name $P-konsole --network $NET --network-alias konsole -p "$K_PORT:8090" --read-only --tmpfs /tmp \
  --cap-drop ALL --cap-add DAC_OVERRIDE --security-opt no-new-privileges:true \
  -e APP_SECRET="$SECRET" -e DOMAIN=stromfueralle.at -e KONSOLE_HOST=localhost -e KONSOLE_DEV=1 -e KONSOLE_SERVICES=$P-web,$P-traefik \
  -v "$T/data:/data" -v "$T/traefik:/traefik" -v /var/run/docker.sock:/var/run/docker.sock $P-konsole >/dev/null
for _ in $(seq 1 30); do curl -fs "http://localhost:$K_PORT/health" >/dev/null && curl -fs -H "Host: stromfueralle.at" "http://localhost:$WEB_PORT/" >/dev/null && break; sleep 1; done

IFS=" " read -r COOKIE CSRF < <(docker run --rm --entrypoint php $P-konsole -r '
spl_autoload_register(function ($c) { require "/app/src/" . substr($c, 8) . ".php"; });
$k = fn($p) => hash_hmac("sha256", "konsole:" . $p, $argv[1], true);
echo (new Konsole\Tokens($k("session")))->issue(["uid" => "u1", "name" => "E2E", "sid" => "e2e"], 600), " ",
     bin2hex(hash_hmac("sha256", "e2e", $k("csrf"), true)), "\n";' "$SECRET")
C="Cookie: konsole_s=$COOKIE"
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); echo "  ✓ $1"; else fail=$((fail + 1)); echo "  ✗ $1 (erwartet '$3', war '$2')"; fi; }
code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }
site() { code -H "Host: stromfueralle.at" "http://localhost:$WEB_PORT/"; }
waitsite() { for _ in $(seq 1 20); do [ "$(site)" = "$1" ] && break; sleep 0.5; done; site; }

echo "Steuerzentrale (echtes Docker + Traefik)"
check "ohne Anmeldung: Login-Seite" "$(code "http://localhost:$K_PORT/")" "200"
check "ohne Anmeldung: Neustart abgelehnt" "$(code -X POST -d "dienst=$P-web" "http://localhost:$K_PORT/neustart")" "401"
check "Übersicht listet Dienst aus Docker" "$(curl -s -H "$C" "http://localhost:$K_PORT/" | grep -c "<span class=\"name\">$P-web</span>")" "1"
check "Logs eines erlaubten Dienstes" "$(code -H "$C" "http://localhost:$K_PORT/logs?dienst=$P-web")" "200"
check "Logs eines fremden Containers: 404" "$(code -H "$C" "http://localhost:$K_PORT/logs?dienst=$P-konsole")" "404"
check "Neustart ohne CSRF: 403" "$(code -H "$C" -d "dienst=$P-web" "http://localhost:$K_PORT/neustart")" "403"
before=$(docker inspect -f '{{.State.StartedAt}}' $P-web)
code -H "$C" -d "csrf=$CSRF&dienst=$P-web" "http://localhost:$K_PORT/neustart" >/dev/null; sleep 2
check "Neustart startet den Container wirklich neu" "$([ "$(docker inspect -f '{{.State.StartedAt}}' $P-web)" != "$before" ] && echo ja)" "ja"
code -H "$C" --data-urlencode "csrf=$CSRF" --data-urlencode "aktion=ein" --data-urlencode "text=E2E-Wartung" --data-urlencode "bis=2099-01-01T20:00" "http://localhost:$K_PORT/wartung" >/dev/null
check "Wartung ein: Seite liefert 500 (nicht 503, sonst Proxy-Ausfallseite)" "$(waitsite 500)" "500"
check "  ... mit dem eingegebenen Text" "$(curl -s -H "Host: stromfueralle.at" "http://localhost:$WEB_PORT/" | grep -c "E2E-Wartung")" "1"
code -H "$C" -d "csrf=$CSRF&aktion=aus" "http://localhost:$K_PORT/wartung" >/dev/null
check "Wartung aus: Seite wieder 200" "$(waitsite 200)" "200"
docker stop $P-web >/dev/null
check "Webserver gestoppt: Seite 502" "$(waitsite 502)" "502"
check "  ... Konsole bleibt erreichbar" "$(code -H "$C" "http://localhost:$K_PORT/")" "200"
code -H "$C" -d "csrf=$CSRF&dienst=$P-web" "http://localhost:$K_PORT/neustart" >/dev/null
check "  ... und startet ihn wieder" "$(waitsite 200)" "200"

echo ""
if [ "$fail" -eq 0 ]; then echo "✓ Alle ${pass} Konsolen-Tests bestanden"; else echo "✗ ${fail} von $((pass + fail)) Konsolen-Tests fehlgeschlagen"; docker logs --tail 30 $P-konsole; exit 1; fi
