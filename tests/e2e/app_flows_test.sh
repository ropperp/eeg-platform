#!/usr/bin/env bash
# End-to-End-Abläufe gegen einen laufenden Stack (Webapp + eda-worker + DB + Redis) mit den
# Testdaten aus tests/e2e/seed.sql. Normalerweise über tests/e2e/run.sh (make test-e2e, CI-Job e2e).
#   BASE=http://localhost:18100 bash tests/e2e/app_flows_test.sh
# Prüft: Login + Session-Erneuerung, falsches Passwort, 2FA-Pflicht, Logout, CSRF, Rollenrechte,
# Platform-Admin ohne EEG, Mandantentrennung per HTTP, App-API mit Token, Beitrittsformular,
# EDA-Upload über den Worker (Job -> Status -> Ergebnisseite).
set -uo pipefail
export LC_ALL=C
BASE="${BASE:-http://localhost:18100}"
PORTAL=portal.stromfueralle.at; MAIN=stromfueralle.at
PW='E2e-Test-123!'
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
pass=0; fail=0
ok()  { pass=$((pass + 1)); echo "  ✓ $1"; }
bad() { fail=$((fail + 1)); echo "  ✗ $1"; [ -n "${2:-}" ] && echo "      $2"; }
check() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "erwartet '$3', war '$2'"; fi; }
contains() { if grep -qF -- "$3" <<<"$2"; then ok "$1"; else bad "$1" "'$3' fehlt"; fi; }

# req <sitzung> <host> <METHODE> <pfad> [curl-args...] -> setzt CODE, LOC, BODY; Cookie je Sitzung in Datei
req() {
  local s=$1 host=$2 m=$3 path=$4; shift 4
  local ck; ck=$(cat "$T/$s.ck" 2>/dev/null || true)
  curl -s -X "$m" -D "$T/h" -o "$T/b" -H "Host: $host" -H "X-Forwarded-Proto: https" ${ck:+-H "Cookie: $ck"} "$@" "$BASE$path"
  CODE=$(head -1 "$T/h" | awk '{print $2}')
  LOC=$(grep -i '^location:' "$T/h" | tr -d '\r' | awk '{print $2}')
  BODY=$(cat "$T/b")
  local nc; nc=$(grep -i '^set-cookie: eeg_session=' "$T/h" | head -1 | sed -E 's/^[^:]+: *([^;]+).*/\1/' | tr -d '\r')
  [ -n "$nc" ] && echo "$nc" > "$T/$s.ck"
  local tok; tok=$(grep -oE 'data-csrf="[0-9a-f]{64}"|var token = "[0-9a-f]{64}"' "$T/b" | head -1 | grep -oE '[0-9a-f]{64}')
  [ -n "$tok" ] && echo "$tok" > "$T/$s.tok"
  return 0
}
token() { cat "$T/$1.tok" 2>/dev/null; }
login() { # login <sitzung> <email>
  rm -f "$T/$1.ck" "$T/$1.tok"
  req "$1" $PORTAL GET /portal/login
  req "$1" $PORTAL POST /portal/login --data-urlencode "csrf_token=$(token "$1")" --data-urlencode "email=$2" --data-urlencode "password=$PW"
}

echo "Anmeldung und Sitzung"
req anon $PORTAL GET /portal/login; before=$(cat "$T/anon.ck")
contains "Login-Seite lädt passkey.js (Button \"Mit Passkey anmelden\")" "$BODY" 'src="/assets/js/passkey.js?v='
if grep -qF '<?=' <<<"$BODY"; then bad "  ... kein unausgeführter PHP-Code im HTML"; else ok "  ... kein unausgeführter PHP-Code im HTML"; fi
req anon $PORTAL POST /portal/login --data-urlencode "csrf_token=$(token anon)" --data-urlencode "email=e2e-manager@example.invalid" --data-urlencode "password=falsch"
check "falsches Passwort: keine Weiterleitung ins Portal" "$([ "$LOC" = /portal/dashboard ] && echo ja || echo nein)" "nein"
login mgr e2e-manager@example.invalid
check "Obmann-Login leitet aufs Dashboard" "$CODE $LOC" "302 /portal/dashboard"
check "Session-ID wird beim Login erneuert (Schutz vor Session-Fixation)" "$([ "$(cat "$T/mgr.ck")" != "$before" ] && echo ja)" "ja"
login totp e2e-totp@example.invalid
check "Konto mit 2FA: Passwort allein führt zur Code-Abfrage" "$LOC" "/portal/login/2fa"
req totp $PORTAL GET /portal/dashboard
check "  ... und noch nicht ins Portal" "$CODE $LOC" "302 /portal/login"
login bye e2e-manager@example.invalid; req bye $PORTAL GET /portal/logout; req bye $PORTAL GET /portal/dashboard
check "nach dem Abmelden kein Zugriff mehr" "$CODE $LOC" "302 /portal/login"

echo "CSRF"
req mgr $PORTAL POST /portal/my/support --data-urlencode "subject=x" --data-urlencode "message=y"
check "Formular-POST ohne CSRF-Token wird abgelehnt" "$CODE" "403"
req mgr $PORTAL POST /portal/support/e2e00000-0000-0000-0000-0000000000c9/reply --data-urlencode "csrf_token=falsch" --data-urlencode "message=y"
check "Formular-POST mit falschem Token wird abgelehnt" "$CODE" "403"

echo "Rollen und Mandantentrennung"
req anon2 $PORTAL GET /portal/members
check "anonym: Mitgliederliste leitet zum Login" "$CODE $LOC" "302 /portal/login"
login mem e2e-member@example.invalid
req mem $PORTAL GET /portal/members
check "Mitglied: Obmann-Seite verboten" "$CODE" "403"
req mgr $PORTAL GET /portal/members
check "Obmann: Mitgliederliste erreichbar" "$CODE" "200"
contains "  ... zeigt eigenes Mitglied" "$BODY" "Mia"
if grep -q "Fremd" <<<"$BODY"; then bad "  ... zeigt kein Mitglied der fremden EEG"; else ok "  ... zeigt kein Mitglied der fremden EEG"; fi
req mgr $PORTAL GET /portal/support/e2e00000-0000-0000-0000-0000000000c9
check "Obmann: Support-Ticket der fremden EEG nicht abrufbar" "$CODE" "404"
req mem $PORTAL GET /portal/my/support/e2e00000-0000-0000-0000-0000000000c9
check "Mitglied: fremdes Ticket nicht abrufbar" "$CODE" "404"
login adm e2e-admin@example.invalid
req adm $PORTAL GET /portal/members
check "Platform-Admin ohne gewählte EEG: Hinweis statt Serverfehler" "$CODE $(cut -d'?' -f1 <<<"$LOC")" "302 /admin"
req adm $PORTAL GET /admin
check "Platform-Admin: Admin-Bereich erreichbar" "$CODE" "200"
contains "  ... mit Kachel zur Steuerzentrale (neuer Tab)" "$BODY" 'class="card control-center-tile" href="https://admin.stromfueralle.at/" target="_blank" rel="noopener"'
req mgr $PORTAL GET /admin
check "Obmann: Admin-Bereich verboten" "$CODE" "403"

echo "App-API"
req api $MAIN GET /api/v1/support
check "ohne Token: 401" "$CODE" "401"
req api $MAIN POST /api/v1/login -H "Content-Type: application/json" -d "{\"email\":\"e2e-member@example.invalid\",\"password\":\"$PW\"}"
AT=$(python3 -c 'import sys,json; print(json.load(sys.stdin).get("access_token",""))' <<<"$BODY" 2>/dev/null)
check "Login über die App liefert Token" "$([ -n "$AT" ] && echo ja)" "ja"
req api $MAIN GET /api/v1/support -H "Authorization: Bearer $AT"
check "mit Token: eigene Tickets (200)" "$CODE" "200"
req api $MAIN GET /api/v1/support/e2e00000-0000-0000-0000-0000000000c9 -H "Authorization: Bearer $AT"
check "mit Token: fremdes Ticket 404" "$CODE" "404"
req api $MAIN GET /api/v1/support -H "Authorization: Bearer kaputt"
check "ungültiger Token: 401" "$CODE" "401"

echo "Öffentliche Seiten"
req pub $MAIN GET /
contains "Startseite: Kennzahl der Pilot-EEG serverseitig im HTML" "$BODY" "Aktuell <strong>1 Mitglied</strong>"
contains "  ... Plattform und Verein als getrennte schema.org-Einträge" "$BODY" '"@type":"SoftwareApplication"'
contains "  ... Verein mit ZVR aus der Datenbank" "$BODY" '"propertyID":"ZVR-Zahl"'
if grep -q 'data-live-value="[a-z_]*"[^>]*>0<' <<<"$BODY"; then bad "  ... keine 0 als Platzhalter für Live-Werte"; else ok "  ... keine 0 als Platzhalter für Live-Werte"; fi
for path in /funktionen /funktionen/abrechnung /funktionen/mitgliederverwaltung /funktionen/echtzeit-daten /funktionen/umstieg /energiegemeinschaft-feldkirchen /faq; do
  req pub $MAIN GET "$path"
  check "Info-Seite $path erreichbar" "$CODE $(grep -c '<h1>' <<<"$BODY")" "200 1"
done
contains "  ... FAQ als FAQPage ausgezeichnet" "$BODY" '"@type":"FAQPage"'
contains "  ... Kosten-Antwort enthalten" "$BODY" "auf Anfrage"
req pub $MAIN GET /funktionen/abrechnung
if grep -q '"FAQPage"' <<<"$BODY"; then bad "  ... FAQPage nur auf /faq (nicht doppelt)"; else ok "  ... FAQPage nur auf /faq (nicht doppelt)"; fi
req pub $MAIN GET /funktionen/gibtsnicht
check "unbekannte Funktionsseite: 404" "$CODE" "404"
req pub $MAIN GET /rc108175/beitreten/formular
check "Beitrittsformular erreichbar" "$CODE" "200"
contains "  ... mit Zustimmungstexten" "$BODY" "zustimmungen"

echo "EDA-Import über den Worker"
printf 'keine Excel-Datei' > "$T/kaputt.xlsx"; printf 'x' > "$T/text.txt"
req mgr $PORTAL POST /portal/eda/upload -F "csrf_token=$(token mgr)" -F "xlsx=@$T/text.txt;filename=text.txt"
contains "Nicht-XLSX wird abgelehnt" "$BODY" "Nur XLSX-Dateien erlaubt"
req mgr $PORTAL POST /portal/eda/upload -F "csrf_token=$(token mgr)" -F "xlsx=@$T/kaputt.xlsx;filename=kaputt.xlsx"
JOB=$(grep -oE 'job=[0-9a-f-]{36}' <<<"$BODY" | head -1 | cut -d= -f2)
check "Upload legt Job an und leitet zur Statusseite (ohne 3xx)" "$CODE $([ -n "$JOB" ] && echo job)" "200 job"
req mgr $PORTAL GET "/portal/eda/upload?job=$JOB"
# Je nach Tempo des Workers: Statusanzeige oder schon das Ergebnis -- beides ist richtig.
if grep -qF "data-eda-job=\"$JOB\"" <<<"$BODY" || grep -qF "Parser-Fehler" <<<"$BODY"; then ok "Statusseite zeigt Fortschritt bzw. Ergebnis"; else bad "Statusseite zeigt Fortschritt bzw. Ergebnis"; fi
st=""; for _ in $(seq 1 60); do req mgr $PORTAL GET "/portal/eda/jobs/$JOB"; st=$(python3 -c 'import sys,json; d=json.load(sys.stdin); print(d["status"])' <<<"$BODY" 2>/dev/null); [ "$st" = failed ] || [ "$st" = done ] && break; sleep 1; done
check "Worker verarbeitet den Job (kaputte Datei -> failed)" "$st" "failed"
req mgr $PORTAL GET "/portal/eda/upload?job=$JOB"
contains "Ergebnisseite zeigt den Parser-Fehler" "$BODY" "Parser-Fehler"
req mem $PORTAL GET "/portal/eda/jobs/$JOB"
check "Mitglied darf den Job-Status nicht abfragen" "$CODE" "403"
if [ -n "${E2E_EDA_FIXTURE:-}" ]; then
  req mgr $PORTAL GET /portal/eda/upload
  req mgr $PORTAL POST /portal/eda/upload -F "csrf_token=$(token mgr)" -F "xlsx=@$E2E_EDA_FIXTURE;filename=RC108175_Energiedatenreport_test.xlsx"
  JOB=$(grep -oE 'job=[0-9a-f-]{36}' <<<"$BODY" | head -1 | cut -d= -f2)
  for _ in $(seq 1 90); do req mgr $PORTAL GET "/portal/eda/jobs/$JOB"; st=$(python3 -c 'import sys,json; print(json.load(sys.stdin)["status"])' <<<"$BODY" 2>/dev/null); [ "$st" = failed ] || [ "$st" = done ] && break; sleep 1; done
  check "gültige Monatsdatei: Worker importiert erfolgreich" "$st" "done"
  req mgr $PORTAL GET "/portal/eda/upload?job=$JOB"
  contains "  ... Ergebnisseite zeigt die importierten Datensätze" "$BODY" "2 Datensätze importiert"
  contains "  ... und die neu angelegten Zählpunkte" "$BODY" "Neu angelegt (2)"
  contains "  ... und die Datenqualitäts-Übersicht" "$BODY" "Datenqualit"
fi

echo "Mitgliederversammlung (Protokoll)"
req mem $PORTAL GET /portal/versammlungen
check "Mitglied: Protokoll-Verwaltung verboten" "$CODE" "403"
req mgr $PORTAL GET /portal/versammlungen
check "Obmann: Liste erreichbar" "$CODE" "200"
req mgr $PORTAL POST /portal/versammlungen --data-urlencode "csrf_token=$(token mgr)" --data-urlencode "held_on=2026-11-14" --data-urlencode "kind=ordentlich"
ASM=$(grep -oE '[0-9a-f-]{36}$' <<<"$LOC")
check "Protokoll anlegen leitet zum Editor" "$CODE $([ -n "$ASM" ] && echo id)" "302 id"
req mgr $PORTAL GET "/portal/versammlungen/$ASM"
check "  ... Editor erreichbar" "$CODE" "200"
req mgr $PORTAL POST "/portal/versammlungen/$ASM/punkte" --data-urlencode "csrf_token=$(token mgr)" --data-urlencode "title=Kassabericht" --data-urlencode "votes_yes=5"
req mgr $PORTAL POST "/portal/versammlungen/$ASM/unterzeichner" --data-urlencode "csrf_token=$(token mgr)" --data-urlencode "name=E2E Obmann" --data-urlencode "role_label=Obmann"
req mgr $PORTAL POST "/portal/versammlungen/$ASM/freigeben" --data-urlencode "csrf_token=$(token mgr)"
check "  ... zur Unterschrift freigegeben" "$CODE $(grep -c 'error=' <<<"$LOC")" "302 0"
req mgr $PORTAL GET "/portal/versammlungen/$ASM"
contains "  ... Status zeigt offene Unterschrift" "$BODY" "Unterschriften: 0 von 1"
contains "  ... Unterschrift vor Ort möglich" "$BODY" "Jetzt vor Ort"
req mem $PORTAL GET "/portal/my/versammlungen/$ASM/pdf"
check "Mitglied: nicht abgeschlossenes Protokoll nicht abrufbar" "$CODE" "404"
req anon3 $PORTAL GET "/portal/unterschrift/versammlung/$(printf '0%.0s' $(seq 1 64))"
check "ungültiger Unterschrifts-Link: 404 mit Hinweis" "$CODE" "404"
contains "  ... Hinweis auf abgelaufenen Link" "$BODY" "Link nicht mehr gültig"

echo "Statuten-Generator (Einladungslink)"
req mgr $PORTAL GET /admin/statuten-einladungen
check "Obmann: Statuten-Einladungen verboten" "$CODE" "403"
req anon4 $PORTAL GET /admin/statuten-einladungen
check "anonym: Statuten-Einladungen leiten zum Login" "$CODE $LOC" "302 /portal/login"
req adm $PORTAL GET /admin/statuten-einladungen
check "Platform-Admin: Statuten-Einladungen erreichbar" "$CODE" "200"
req adm $PORTAL POST /admin/statuten-einladungen --data-urlencode "csrf_token=$(token adm)" --data-urlencode "label=E2E Interessent" \
  --data-urlencode "valid_days=30" --data-urlencode "prefill_area=lokal" --data-urlencode "prefill_state=kaernten"
INV=$(grep -oE '[0-9a-f-]{36}$' <<<"$LOC")
check "Einladung anlegen leitet zur Detailseite" "$CODE $([ -n "$INV" ] && echo id)" "302 id"
req adm $PORTAL GET "/admin/statuten-einladungen/$INV"
STOK=$(grep -oE '/statuten/[A-Za-z0-9_-]{43}"' <<<"$BODY" | head -1 | cut -d/ -f3 | tr -d '"')
check "  ... Link wird einmal angezeigt (256-Bit-Token)" "${#STOK}" "43"
req adm $PORTAL GET "/admin/statuten-einladungen/$INV"
if grep -qF "$STOK" <<<"$BODY"; then bad "  ... beim zweiten Aufruf nicht mehr sichtbar"; else ok "  ... beim zweiten Aufruf nicht mehr sichtbar"; fi
req itv $MAIN GET "/statuten/$STOK"
check "Interessent: Fragebogen ohne Login erreichbar" "$CODE" "200"
contains "  ... mit Vorbelegung (Lokalbereich)" "$BODY" 'value="lokal" selected'
contains "  ... nicht indexierbar" "$(cat "$T/h")" "X-Robots-Tag: noindex"
req itv $MAIN POST "/statuten/$STOK" --data-urlencode "csrf_token=$(token itv)" --data-urlencode "aktion=speichern" \
  --data-urlencode "name=Erneuerbare-Energie-Gemeinschaft E2E" --data-urlencode "seat=Testort" --data-urlencode "board_size=2"
check "  ... Zwischenspeichern" "$CODE" "200"
contains "  ... bestätigt" "$BODY" "Zwischengespeichert"
req itv $MAIN POST "/statuten/$STOK" --data-urlencode "csrf_token=$(token itv)" --data-urlencode "aktion=erzeugen" \
  --data-urlencode "name=Erneuerbare-Energie-Gemeinschaft E2E" --data-urlencode "seat=Testort" --data-urlencode "founders_count=1"
check "  ... Erzeugen mit Regelverstoß (1 Gründer) wird abgelehnt" "$CODE" "422"
contains "  ... mit Begründung" "$BODY" "zwei Gründungsmitglieder"
req itv $MAIN GET "/statuten/$STOK"
contains "  ... gespeicherter Stand bleibt erhalten" "$BODY" 'value="Testort"'
req itv $MAIN GET "/statuten/$STOK/pdf/statuten"
check "  ... vor dem Erzeugen kein PDF" "$CODE" "404"
req itv $MAIN GET "/statuten/$(printf 'A%.0s' $(seq 1 43))"
check "falscher Token: 404" "$CODE" "404"
req itv $MAIN POST "/statuten/$STOK" --data-urlencode "aktion=speichern"
check "POST ohne CSRF-Token abgelehnt" "$CODE" "403"
req adm $PORTAL POST "/admin/statuten-einladungen/$INV/widerrufen" --data-urlencode "csrf_token=$(token adm)"
check "Admin widerruft die Einladung" "$CODE" "302"
req itv $MAIN GET "/statuten/$STOK"
check "  ... widerrufener Link: 410" "$CODE" "410"
contains "  ... mit Hinweis" "$BODY" "zurückgezogen"

echo ""
if [ "$fail" -eq 0 ]; then echo "✓ Alle ${pass} E2E-Tests bestanden"; else echo "✗ ${fail} von $((pass + fail)) E2E-Tests fehlgeschlagen"; exit 1; fi
