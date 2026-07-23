# Claude-Sitzungslog

Fortlaufende Selbstdokumentation aller Claude-Arbeitssitzungen rund um die EEG-Plattform:
Datum, verwendetes Modell, Werkzeug und der professionell zusammengefasste Auftrag.
Neueste Einträge oben. Format und Regeln: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.
Einträge aus Cowork/Claude Chat liegen zusätzlich im Obsidian-Vault unter
`eeg-platform-notes/logs/JJJJ-MM-TT.md`.

---

## 2026-07-23 — Claude Code — Claude Opus 4.8
**Auftrag:** Git-Versionierung mit Branches und Tags einführen und künftig beim Committen/Pushen
verwenden (inkl. Erklärung des Nutzens); das starre 60-Tage-Freigabefenster der Abrechnung durch
ein an der EDA-Datenqualität orientiertes Kriterium ersetzen (Variable aus dem Eder-XLSX-Monats-
bericht); die Raspberry-Stabilitätsdoku an den tatsächlichen Befund (NVMe über PCIe, Root-FS
read-write) anpassen.
**Ergebnis:** `CHANGELOG.md` (SemVer) angelegt und Git-Workflow-Abschnitt in CLAUDE.md +
Infrastruktur.md ergänzt; Tags `v0.9.0` (Meilenstein) und `v0.9.1` gesetzt; Arbeit auf dem
Branch `claude/stromfueralle-footer-pages-trqb5c` gebündelt. Abrechnungs-Freigabe hängt nun an
`billing_runs.eda_status` + automatischer L3-Prüfung statt am Kalender (Billing::finalize/
datenqualitaetProblem/setEdaStatus, Migration `migrate_20260805`, UI + Route + `docs/
EDA_DATENQUALITAET.md`); dabei den EDA-Filter von `('L2','L3')` auf `('L1','L2')` korrigiert
(L1 = gemessener Echtwert wurde fälschlich ausgeschlossen, L3 = nicht belastbar mitgerechnet).
`docs/RASPBERRY_STABILITAET.md` überarbeitet: USB-SATA/read-only ausgeschlossen, persistentes
journald empfohlen, Verdacht auf OOM/Unterspannung/NVMe-Link refokussiert. 28 Tests grün.

## 2026-07-22 — Claude Code — Claude Opus 4.8
**Auftrag:** Umfangreiche Folge-Runde: die gelieferte neue 4-spaltige Rechnungsvorlage
PHP-seitig anbinden (inkl. Positionen pro Zählpunkt), drei aus dem Ideen-Feedback gewünschte
Features umsetzen (Rechnungs-Einzelbearbeitung vor Versand, DSGVO-Datenexport, automatisierte
Tests + CI) und die Raspberry-Diagnose an das tatsächliche Setup (Pi 5 mit SSD) anpassen.
**Ergebnis:** Neue `rechnung.tex` als Repo-Standard + PHP auf 4 Spalten und
Pro-Zählpunkt-Positionen umgestellt (mit echtem pdflatex verifiziert); `invoice_items` um
`zaehlpunkt_nr` erweitert. Abrechnung zweistufig (berechnen → einzeln bearbeiten → freigeben,
Billing::generateDrafts/finalize/recalcInvoiceSaldo, end-to-end gegen Postgres getestet).
DSGVO-Export pro Mitglied (Selbst- und Manager-Auskunft) + `docs/DSGVO.md`. Abhängigkeitsfreie
Test-Suite (`tests/`, 28 Tests) + GitHub-Actions-CI, reine Funktionen nach `src/functions.php`
ausgelagert. `docs/RASPBERRY_STABILITAET.md` auf SSD-Realität überarbeitet (USB-SATA/UAS-Reset
→ read-only-FS als Hauptverdacht). Commits: Rechnung/4-Spalten, Einzelbearbeitung, DSGVO,
Tests+CI, Raspberry-Doku.

## 2026-07-20 16:20 — Claude Code — Claude Opus 4.8
**Auftrag:** Weitere Runde Rechnungs-/Abrechnungsarbeit sowie zwei Betriebsanliegen: den
anteiligen Mitgliedsbeitrag bei unterjährigem Beitritt umsetzen, die Ursache für sporadische
Raspberry-Pi-Aufhänger (im Netz sichtbar, aber kein SSH) klären und absichern, und einen
Berater-Agenten für Diplomarbeits-/Plattform-Ideen anlegen.
**Ergebnis:** `Billing.php` rechnet den Mitgliedsbeitrag jetzt anteilig nach aktiven
Monaten im Abrechnungszeitraum (voll dabei = unverändert, verifiziert). Docker-Log-Rotation
(`x-logging`) in `docker-compose.yml` ergänzt und `docs/RASPBERRY_STABILITAET.md` (Ursachen,
Diagnose, Hardware-Watchdog-Selbstheilung) angelegt, in CLAUDE.md + Obsidian verlinkt. Neuer
Sub-Agent `.claude/agents/diplomarbeit-berater.md`. Die 4-Spalten-Umstellung der
Positionstabelle (`RAW_ZUSATZPOSITIONEN_LISTE`/`RAW_STEUER_ZEILE`) sowie die Pro-Zählpunkt-
Darstellung wurden bewusst zurückgestellt, bis die neue `rechnung.tex` vorliegt (Vorlagen-
Kopplung, sonst Kompilierfehler in Produktion).

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
