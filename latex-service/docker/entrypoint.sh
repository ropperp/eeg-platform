#!/bin/sh
set -e

# /app/templates ist beim Produktivbetrieb ein gemountetes Volume (siehe docker-compose.yml),
# damit ein Platform-Admin die .tex-Vorlagen über die Webapp herunter-/hochladen kann. Früher
# wurde hier einmalig beim allerersten Start (leeres Volume) der komplette Inhalt von
# ./templates-default hineinkopiert, damit latex-service nicht ohne jede Vorlage dasteht.
#
# Vorfall 04.10.2026: genau dieses einmalige Kopieren war die Ursache dafür, dass Vorlagen-Fixes
# aus dem Git-Repo den Produktivserver nie erreicht haben -- sobald eine Datei EINMAL im Volume
# lag (was nach dem allerersten Start für ALLE Vorlagen der Fall war, nicht nur tatsächlich vom
# Admin angepasste), wurde sie bei jedem künftigen `git pull && docker compose up -d --build` für
# immer ignoriert, ganz unabhängig davon, ob sie sich im Image inzwischen geändert hatte. service.js
# löst das jetzt selbst: resolveTemplatePath() fällt bei jeder PDF-Erzeugung live auf
# ./templates-default zurück, wenn die angeforderte Datei im Volume fehlt -- das Kopieren hier ist
# dadurch überflüssig geworden (und würde das Problem bei jeder Neuinstallation sofort
# reproduzieren), deshalb bewusst entfernt statt nur "repariert".
exec "$@"
