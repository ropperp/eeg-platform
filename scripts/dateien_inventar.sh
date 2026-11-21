#!/usr/bin/env bash
# Inventar der Dateien auf dem Server (Pfad, Größe, Änderungsdatum, Besitzer, SHA-256), damit man sehen
# kann, welche Fassung wo liegt und was neuer ist. Liest nur, ändert nichts. Ausgabe liegt AUSSERHALB
# des Repos (Repo ist öffentlich, Mitglieder-Uploads enthalten Personendaten): ~/eeg-inventar-<Datum>/
#
#   cd /opt/eeg-platform && bash scripts/dateien_inventar.sh
#   Danach auf dem eigenen Rechner:  scp -r admin@<server>:eeg-inventar-<Datum> .
#
# Enthalten:
#   manifest.tsv           alle Dateien aus /opt/eeg/latex-templates und /opt/eeg/webapp-storage (nur Metadaten)
#   latex-templates.tar.gz Inhalt von /opt/eeg/latex-templates (Vorlagen, Infoblatt, Logos; keine Personendaten)
#   git.txt                Stand des Repos (Commit, Tag) und Vorlagen des Images mit Änderungsdatum zum Vergleich
set -euo pipefail

OUT="${HOME}/eeg-inventar-$(date +%Y-%m-%d)"
mkdir -p "$OUT"
DIRS=(/opt/eeg/latex-templates /opt/eeg/webapp-storage)

TMP="$(mktemp)"
for d in "${DIRS[@]}"; do
  [ -d "$d" ] || { echo "Hinweis: $d fehlt" >&2; continue; }
  find "$d" -type f -printf '%p\t%s\t%TY-%Tm-%Td %TH:%TM\t%u\t' -exec sha256sum {} \; 2>/dev/null \
    | awk -F'\t' '{ split($5, h, " "); print $1 "\t" $2 "\t" $3 "\t" $4 "\t" h[1] }' >> "$TMP" || true
done
{ printf 'pfad\tgroesse_byte\tgeaendert_iso\tbesitzer\tsha256\n'; sort -t$'\t' -k3,3r "$TMP"; } > "$OUT/manifest.tsv"
rm -f "$TMP"

[ -d /opt/eeg/latex-templates ] && tar -czf "$OUT/latex-templates.tar.gz" -C /opt/eeg latex-templates

{
  echo "Stand: $(date -Is)"
  git -C "$(dirname "$0")/.." log -1 --format='Commit: %h %ci %s'
  git -C "$(dirname "$0")/.." describe --tags --always
  echo; echo "Vorlagen im Repo (Image-Stand), Änderungsdatum der Datei im Checkout:"
  ls -l --time-style=long-iso "$(dirname "$0")/../latex-service/templates/" | awk '{print $6, $7, $8}'
} > "$OUT/git.txt"

echo "Fertig: $OUT"
ls -lh "$OUT"
echo "Neueste Dateien zuerst (manifest.tsv, erste 15):"
head -n 16 "$OUT/manifest.tsv" | cut -f1,3,2
