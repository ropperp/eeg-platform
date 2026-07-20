# Claude-Sitzungslog

Fortlaufende Selbstdokumentation aller Claude-Arbeitssitzungen rund um die EEG-Plattform:
Datum, verwendetes Modell, Werkzeug und der professionell zusammengefasste Auftrag.
Neueste Einträge oben. Format und Regeln: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.
Einträge aus Cowork/Claude Chat liegen zusätzlich im Obsidian-Vault unter
`eeg-platform-notes/logs/JJJJ-MM-TT.md`.

---

## 2026-07-20 08:30 — Cowork — Claude Fable 5
**Auftrag:** Einführung einer Selbstdokumentation für alle Claude-Werkzeuge (Claude Code,
Claude Chat, Cowork): Jede Sitzung soll künftig Datum, verwendetes Modell und den
professionell formulierten Auftrag protokollieren; die zugehörige Anweisung soll in
`CLAUDE.md` aufgenommen und auf GitHub verfügbar gemacht werden.
**Ergebnis:** Abschnitt „Selbstdokumentation" in `CLAUDE.md` ergänzt, diese Log-Datei
angelegt (inkl. Backfill aus der Git-Historie), `obsidian/Infrastruktur.md` mitaktualisiert,
täglichen Obsidian-Sync-Task um ein Lauf-Protokoll erweitert. Push auf GitHub erfolgt durch
Patrick (Cowork pusht vereinbarungsgemäß nicht).

## 2026-07-20 07:05 — Cowork (geplanter Task) — Claude Fable 5
**Auftrag:** Täglicher automatischer Abgleich der Markdown-Dokumentation des Repos mit dem
Obsidian-Vault.
**Ergebnis:** Alle 15 Doku-Dateien bereits identisch mit `origin/main` (ccc9d07), keine
Änderungen nötig. Task anschließend auf reines Lesen vom GitHub-Stand umgestellt
(nur `git fetch`/`git show`, niemals committen/mergen/pushen).

---

## Backfill (rekonstruiert am 2026-07-20; Modell nachträglich nicht mehr feststellbar)

Claude-Code-Sitzungen laut Git-Historie (`origin/main`):

| Datum | Arbeiten |
|---|---|
| 2026-07-19 | Rechnungs-Template: Anrede, getrennte Adresszeilen, Kundennummer, SEPA-Mandatsreferenz, Zahlungstext; E-Mail-Signatur, Rechnungs-Testvorschau, EEG-Logo, Variablen-Export, manuelle Rechnungspositionen; drei Abrechnungs-Bugs behoben; konfigurierbarer Reply-To-Header |
| 2026-07-18 | Ein-Befehl-Setup (`scripts/setup.sh`) inkl. Migrations-Bugfix; Test-Endpoint für API-Keys; Kontrast-Bugfix Dark/Light-Mode; ESB-Ideen-Backlog angelegt; Logo-Upload im Platform-Admin (inkl. nginx-Routing-Fix); dezente Startseiten-Animationen; Footer-Link zur Kärnten-Netz-Netzgebietsprüfung |
| 2026-07-17 | Infoblatt (Website-PDF) zur Vorlagenverwaltung `/admin/templates` hinzugefügt |
| 2026-07-16 | Mitglieder-API-Zugänge (Vorbereitung Smart-Home-API); Mitglied-Dashboard-Platzhalter; Revert der Portal-Zugang-Änderung; LaTeX-Vorlagen-Dateiverwaltung im Platform-Admin |

Cowork-/Chat-Sitzungen der letzten Zeit (Titel laut Sitzungsliste, ohne genaue
Datumszuordnung): Rechnungslayout Solar, No-Reply-Postfach & E-Mail-Signatur (2 Sitzungen),
Fronius EVO, Hausverteiler/Zähler-Absicherung Kärnten, KHS-Schaltplan-Überarbeitung,
Obsidian-Doku-Sync (mehrere Läufe), Infoblatt mit 2 Seiten, virtueller Gemeinschaftsspeicher,
Prüfung Höfferer-Energiegemeinschafts-Vereinbarungen, deutsche Vertragsvorlagen,
Sparkasse-Lastschrift-Anforderungen, 3D-Druck Schriftzug.
