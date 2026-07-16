#!/bin/sh
set -e

# /app/templates ist beim Produktivbetrieb ein gemountetes Volume (siehe docker-compose.yml),
# damit ein Platform-Admin die .tex-Vorlagen über die Webapp herunter-/hochladen kann. Auf
# einem frischen Host ist dieses Verzeichnis leer und würde die im Image mitgelieferten
# Standard-Vorlagen (./templates-default) verdecken -- einmalig hineinkopieren, sonst startet
# latex-service ohne jede Vorlage.
if [ -z "$(ls -A /app/templates 2>/dev/null)" ]; then
  echo "[entrypoint] /app/templates ist leer -- kopiere Standard-Vorlagen hinein."
  cp -a /app/templates-default/. /app/templates/
fi

exec "$@"
