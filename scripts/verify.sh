#!/usr/bin/env bash
# scripts/verify.sh — Vollständige Verifikation des EEG-Plattform-Stacks
#
# Führt alle Checks durch und schreibt ein Protokoll nach /tmp/verify_DATUM.log
# Ausführen im Repo-Root-Verzeichnis auf dem Raspberry Pi:
#
#   bash scripts/verify.sh
#
# Reihenfolge:
#   1) Stack-Status (docker compose ps)
#   2) Backup erstellen + Restore-Probe in WEGWERF-DB
#   3) Schema + anonyme Statistiken aus der echten DB
#   4) Git-Status / Datenschutz-Check
#   5) pdflatex-Test (direkter API-Call an latex-service)
#   6) Cron-Hinweis

set -euo pipefail

LOGFILE="/tmp/verify_$(date +%Y%m%d_%H%M).log"
PASS=0
FAIL=0

log()  { echo "$*" | tee -a "$LOGFILE"; }
ok()   { log "  [OK]  $*"; ((PASS++)) || true; }
fail() { log "  [FAIL] $*"; ((FAIL++)) || true; }
h()    { log ""; log "═══ $* ═══"; }

log "EEG-Plattform Verifikation — $(date)"
log "Logfile: $LOGFILE"

# ─── 1) STACK-STATUS ─────────────────────────────────────────────────────────
h "1) STACK-STATUS"

SERVICES=(traefik timescaledb redis mosquitto mqtt-subscriber webapp latex-service)
for svc in "${SERVICES[@]}"; do
    STATUS=$(docker compose ps --format json "$svc" 2>/dev/null | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('Health','') or d.get('State',''))" 2>/dev/null || echo "unknown")
    if [[ "$STATUS" == "healthy" || "$STATUS" == "running" ]]; then
        ok "$svc: $STATUS"
    else
        fail "$svc: $STATUS"
        docker compose logs --tail=20 "$svc" 2>&1 | sed 's/^/    /' | tee -a "$LOGFILE"
    fi
done

# ─── 2) BACKUP + RESTORE-PROBE ───────────────────────────────────────────────
h "2) BACKUP + RESTORE-PROBE"

log "  Erstelle Backup..."
bash scripts/backup.sh 2>&1 | tee -a "$LOGFILE"

DUMP=$(ls -t backups/eeg_*.dump 2>/dev/null | head -1)
if [[ -z "$DUMP" ]]; then
    fail "Kein Dump gefunden in backups/"
else
    SIZE=$(du -sh "$DUMP" | cut -f1)
    ok "Dump: $DUMP ($SIZE)"

    log "  Restore-Probe in WEGWERF-DB eeg_restore_test..."
    docker compose exec -T timescaledb psql -U eeg -c \
        "DROP DATABASE IF EXISTS eeg_restore_test; CREATE DATABASE eeg_restore_test;" \
        >> "$LOGFILE" 2>&1

    docker compose exec -T timescaledb \
        pg_restore -U eeg -d eeg_restore_test --clean --if-exists --no-owner --no-privileges \
        < "$DUMP" >> "$LOGFILE" 2>&1

    TABLE_COUNT=$(docker compose exec -T timescaledb \
        psql -U eeg -d eeg_restore_test -t -c \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE';" \
        2>/dev/null | tr -d ' \n')

    if [[ "${TABLE_COUNT:-0}" -ge 10 ]]; then
        ok "Restore: $TABLE_COUNT Tabellen in eeg_restore_test"
    else
        fail "Restore: nur $TABLE_COUNT Tabellen (erwartet >= 10)"
    fi

    docker compose exec -T timescaledb psql -U eeg -c \
        "DROP DATABASE IF EXISTS eeg_restore_test;" >> "$LOGFILE" 2>&1
    ok "Wegwerf-DB eeg_restore_test gelöscht"

    # Ergebnis in BACKUP.md protokollieren
    DATUM="$(date '+%d.%m.%Y %H:%M')"
    BACKUP_MD="docs/BACKUP.md"
    if grep -q "## Restore-Testprotokoll" "$BACKUP_MD" 2>/dev/null; then
        # Zeile mit letztem Test aktualisieren
        sed -i "s/Zuletzt getestet am .*/Zuletzt getestet am $DATUM — $TABLE_COUNT Tabellen OK/" "$BACKUP_MD"
    else
        echo "" >> "$BACKUP_MD"
        echo "## Restore-Testprotokoll" >> "$BACKUP_MD"
        echo "" >> "$BACKUP_MD"
        echo "Zuletzt getestet am $DATUM — $TABLE_COUNT Tabellen OK" >> "$BACKUP_MD"
    fi
fi

# ─── 3) SCHEMA + STATISTIKEN ─────────────────────────────────────────────────
h "3) SCHEMA + ANONYME STATISTIKEN"

log "  Generiere docs/schema.sql..."
docker compose exec -T timescaledb \
    pg_dump -U eeg -d eeg_platform --schema-only --no-owner --no-privileges \
    > docs/schema.sql 2>>"$LOGFILE"
SCHEMA_SIZE=$(wc -l < docs/schema.sql)
ok "docs/schema.sql: $SCHEMA_SIZE Zeilen"

log "  Anonyme Statistiken..."
DB_STATS=$(docker compose exec -T timescaledb psql -U eeg -d eeg_platform -t <<'SQL'
SELECT
  'communities'       AS tabelle, COUNT(*)::text AS anzahl FROM communities
UNION ALL SELECT
  'members',          COUNT(*)::text FROM members
UNION ALL SELECT
  'metering_points',  COUNT(*)::text FROM metering_points
UNION ALL SELECT
  'eda_measurements', approximate_row_count('eda_measurements'::regclass)::text
UNION ALL SELECT
  'esp_measurements', approximate_row_count('esp_measurements'::regclass)::text
UNION ALL SELECT
  'eda_imports',      COUNT(*)::text FROM eda_imports;
SQL
)

log "$DB_STATS"

EDA_RANGE=$(docker compose exec -T timescaledb psql -U eeg -d eeg_platform -t -c \
    "SELECT to_char(MIN(period_from),'DD.MM.YYYY') || ' bis ' || to_char(MAX(period_to),'DD.MM.YYYY') FROM eda_imports;" \
    2>/dev/null | tr -d ' ')

DB_SIZE=$(docker compose exec -T timescaledb psql -U eeg -d eeg_platform -t -c \
    "SELECT pg_size_pretty(pg_database_size('eeg_platform'));" \
    2>/dev/null | tr -d ' ')

ok "EDA-Zeitraum: $EDA_RANGE"
ok "DB-Größe: $DB_SIZE"

# STATISTIK.md aktualisieren
HEUTE=$(date '+%d.%m.%Y')
python3 - <<PYEOF
import re, subprocess

stats_raw = """$DB_STATS"""
lines = [l.strip() for l in stats_raw.strip().split('\n') if '|' in l]
vals = {}
for l in lines:
    parts = [p.strip() for p in l.split('|')]
    if len(parts) >= 2:
        vals[parts[0]] = parts[1]

new_content = f"""| Kennzahl | Wert |
|----------|------|
| Aktive EEGs | {vals.get('communities', '--')} |
| Mitglieder gesamt | {vals.get('members', '--')} |
| Zählpunkte gesamt | {vals.get('metering_points', '--')} |
| EDA-Messwerte (ca.) | {vals.get('eda_measurements', '--')} |
| ESP32-Messwerte (ca.) | {vals.get('esp_measurements', '--')} |
| EDA-Zeitraum | $EDA_RANGE |
| Datenbankgröße | $DB_SIZE |"""

with open('docs/STATISTIK.md', 'r') as f:
    content = f.read()

# Tabelle nach "## Aktuelle Werte" ersetzen
pattern = r'(\| Aktive EEGs.*?\| Datenbankgröße.*?\|)'
replacement = new_content
new = re.sub(pattern, replacement, content, flags=re.DOTALL)
if new != content:
    with open('docs/STATISTIK.md', 'w') as f:
        f.write(new)
    print("  STATISTIK.md aktualisiert")
else:
    # Fallback: Abschnitt am Ende ersetzen
    marker = "| Aktive EEGs |"
    if marker in content:
        idx = content.index(marker)
        end_idx = content.find("\n\n", idx)
        content = content[:idx] + new_content + (content[end_idx:] if end_idx > 0 else '')
        with open('docs/STATISTIK.md', 'w') as f:
            f.write(content)
        print("  STATISTIK.md aktualisiert (fallback)")
PYEOF

ok "STATISTIK.md aktualisiert"

# ─── 4) DATENSCHUTZ-CHECK ────────────────────────────────────────────────────
h "4) DATENSCHUTZ / GIT-STATUS"

if git -C . ls-files .env | grep -q '.env'; then
    fail ".env ist in Git getrackt!"
else
    ok ".env nicht in Git"
fi

if ls backups/eeg_*.dump 2>/dev/null | xargs -r git ls-files 2>/dev/null | grep -q dump; then
    fail "Dump-Datei in Git getrackt!"
else
    ok "backups/ nicht in Git"
fi

STAGED=$(git status --porcelain 2>/dev/null | grep -v "^??" || true)
if [[ -z "$STAGED" ]]; then
    ok "Working tree clean — nichts staged"
else
    log "  Geänderte/staged Dateien:"
    log "$STAGED"
fi

# ─── 5) PDFLATEX-TEST ────────────────────────────────────────────────────────
h "5) PDFLATEX / LATEX-SERVICE TEST"

source .env 2>/dev/null || true
LATEX_KEY="${LATEX_API_KEY:-dev-key}"

log "  Teste latex-service mit Bezugsvereinbarung..."
HTTP_CODE=$(curl -s -o /tmp/test_bezug.pdf -w "%{http_code}" \
    -X POST http://localhost:3210/generate \
    -H "Content-Type: application/json" \
    -H "x-api-key: $LATEX_KEY" \
    -d '{
      "template": "bezugsvereinbarung",
      "vars": {
        "EEG_NAME": "Test EEG",
        "EEG_ADRESSE": "Testgasse 1, 9000 Klagenfurt",
        "EEG_ZVR": "1234567890",
        "EEG_MARKTPARTNER_ID": "RC999999",
        "EEG_IBAN": "AT12 3456 7890 1234 5678",
        "EEG_ORT": "Klagenfurt",
        "MITGLIED_NAME": "Max Mustermann",
        "MITGLIED_ADRESSE": "Musterweg 5, 9000 Klagenfurt",
        "MITGLIED_ADRESSE_ORT": "Klagenfurt",
        "MITGLIED_UID_ZEILE": "",
        "BEZUG_TARIF": "12,0000",
        "MITGLIEDSBEITRAG": "6,00",
        "TARIF_GUELTIG_AB": "01.01.2026",
        "RAW_ZAEHLPUNKTE_TABELLE": "AT0070000000000000001 \\& DEMO-001 \\\\",
        "ERSTELLT_AM": "20.06.2026"
      }
    }' 2>>"$LOGFILE")

if [[ "$HTTP_CODE" == "200" ]] && [[ -s /tmp/test_bezug.pdf ]]; then
    PDF_SIZE=$(du -sh /tmp/test_bezug.pdf | cut -f1)
    ok "Bezugsvereinbarung PDF: HTTP 200, Größe $PDF_SIZE"
    rm -f /tmp/test_bezug.pdf
else
    fail "Bezugsvereinbarung PDF: HTTP $HTTP_CODE — Logs: docker compose logs latex-service"
    docker compose logs --tail=30 latex-service 2>&1 | tail -30 | tee -a "$LOGFILE"
fi

log "  Teste latex-service mit Rechnung..."
HTTP_CODE2=$(curl -s -o /tmp/test_rechnung.pdf -w "%{http_code}" \
    -X POST http://localhost:3210/generate \
    -H "Content-Type: application/json" \
    -H "x-api-key: $LATEX_KEY" \
    -d '{
      "template": "rechnung",
      "vars": {
        "EEG_NAME": "Test EEG",
        "EEG_ADRESSE": "Testgasse 1, 9000 Klagenfurt",
        "EEG_UID": "",
        "MITGLIED_NAME": "Max Mustermann",
        "MITGLIED_ADRESSE": "Musterweg 5, 9000 Klagenfurt",
        "MITGLIED_UID": "",
        "RECHNUNGSNUMMER": "RC999999-2026-Q1-001",
        "RECHNUNGSDATUM": "20.06.2026",
        "ABRECHNUNGSZEITRAUM": "01.01.2026 -- 31.03.2026",
        "BEZUG_KWH": "250,00",
        "BEZUG_TARIF": "12,0000",
        "BEZUG_BETRAG": "30,00",
        "EINSPEISUNG_KWH": "0,00",
        "EINSPEISUNG_TARIF": "8,0000",
        "EINSPEISUNG_BETRAG": "0,00",
        "MITGLIEDSBEITRAG": "6,00",
        "SUMME_NETTO": "36,00",
        "SUMME_BRUTTO": "36,00",
        "RAW_STEUER_ZEILE": "\\\\multicolumn{5}{l}{\\\\footnotesize Gem.~\\\\S{}~6 Abs.~1 Z~27 UStG 1994: keine USt.} \\\\\\\\",
        "RAW_STEUER_TEXT": "Gem. \\\\ S 6 Abs. 1 Z 27 UStG 1994 (Kleinunternehmerregelung): keine USt.",
        "IBAN": "AT12 3456 7890 1234 5678",
        "BIC": "BKAUATWW",
        "ZAHLUNGSZIEL": "04.07.2026"
      }
    }' 2>>"$LOGFILE")

if [[ "$HTTP_CODE2" == "200" ]] && [[ -s /tmp/test_rechnung.pdf ]]; then
    PDF_SIZE2=$(du -sh /tmp/test_rechnung.pdf | cut -f1)
    ok "Rechnung PDF: HTTP 200, Größe $PDF_SIZE2"
    rm -f /tmp/test_rechnung.pdf
else
    fail "Rechnung PDF: HTTP $HTTP_CODE2 — Logs: docker compose logs latex-service"
    docker compose logs --tail=30 latex-service 2>&1 | tail -30 | tee -a "$LOGFILE"
fi

# ─── 6) CRON-HINWEIS ─────────────────────────────────────────────────────────
h "6) CRON"

if crontab -l 2>/dev/null | grep -q "backup.sh"; then
    ok "Cron-Job für backup.sh bereits eingerichtet"
else
    log "  [INFO] Noch kein Cron-Job. Einrichten mit:"
    log "         crontab -e"
    log "         30 2 * * * cd /opt/eeg-platform && bash scripts/backup.sh >> /var/log/eeg-backup.log 2>&1"
fi

# ─── ERGEBNIS ────────────────────────────────────────────────────────────────
h "ERGEBNIS"
log "  Bestanden: $PASS"
log "  Fehlgeschlagen: $FAIL"
log "  Logfile: $LOGFILE"

if [[ $FAIL -eq 0 ]]; then
    log ""
    log "  Alle Checks bestanden."
else
    log ""
    log "  $FAIL Check(s) fehlgeschlagen — Logfile prüfen."
    exit 1
fi
