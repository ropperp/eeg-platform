# Claude-Sitzungslog

Fortlaufende Selbstdokumentation aller Claude-Arbeitssitzungen rund um die EEG-Plattform:
Datum, verwendetes Modell, Werkzeug und der professionell zusammengefasste Auftrag.
Neueste Einträge oben. Format und Regeln: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.
Einträge aus Cowork/Claude Chat liegen zusätzlich im Obsidian-Vault unter
`eeg-platform-notes/logs/JJJJ-MM-TT.md`.

---

## 2026-08-31 (94) — Claude Code — Claude Sonnet 5
**Prompt:** Fortsetzung derselben Daniel-Ropper-Frage nach Eintrag 93 -- nach dem Label-Fix
zeigte "Meine Einspeisung" weiterhin kein Grau. Dazwischen zwei Nebenthemen: "bitte überprüfe,
ob daten drinnen stehen. und wenn ein upload gelöscht wird, sollen auch die Daten aus der DB
gelöscht werden. das drüberspeichern funktioniert irgendwie nicht so ganz" (Löschen räumte bisher
nur den Protokolleintrag, nicht die Messwerte auf) sowie "unter mitglieder ist ein roter punkt
mit einer 1. aber dann ist bei keinem mitglied eine bemerkung" (Sidebar-Badge-Diskrepanz). Am
Ende: "Habe die zwei Tabellen gerade mal selber angesehen [...] Wie hast du das eigentlich bei
den Verbrauchern gemacht gehabt? [...] Bitte probier das noch mal." samt beider echter
EDA-Exportdateien als Anhang. Dazwischen außerdem eine weitergeleitete Netzbetreiber-Mail (KNG
Kärnten Netz) zur Neudefinition der Nahebereiche ab 05.10.2026, zur Dokumentation.
**Auftrag:** Vier Anliegen: (1) Ursache für die weiterhin fehlende graue Gesamtfläche bei
Einspeisern klären und beheben, (2) Löschen eines Viertelstundenwerte-Imports soll auch die
Messwerte entfernen, (3) Diskrepanz zwischen Sidebar-Badge und Mitgliederliste bei ESP-Fehlern
klären, (4) Netzbetreiber-Mail zusammenfassen und dokumentieren.
**Ergebnis:** (1) Grundlegender Fund durch direkte Analyse der von Patrick geteilten echten
XLSX-Dateien (openpyxl, alle 6 Zählpunkte, 2.688 Zeilen je Zählpunkt geprüft): die als
"gemeinschaftlich genutzt" verwendete Spalte war entgegen ihrem Namen bereits die GESAMTE
Erzeugung, die für "Gesamt" angenommene Spalte ist in jedem Export leer, eine vierte,
ungenutzte Spalte ("Restüberschuss bei EG und je ZP") enthält den tatsächlich nicht genutzten
Rest -- `eda-parser/parser_interval.py` entsprechend umgebaut
(`kwh_gemeinschaft = Teilnahmefaktor − Restüberschuss`), `migrate_20260911.sql` korrigiert den
Spaltenkommentar. (2) `/portal/eda/interval-imports/:id/delete` löscht jetzt auch
`eda_interval_data` im exakten Zeitraum. (3) Sidebar-Zähler-Abfrage in `portal.php` hing noch an
der alten, `esp_online`-abhängigen Logik (Flackern, siehe 19.08.2026) und hatte keinen
Demo-Zählpunkt-Ausschluss -- an die bereits korrigierte Version in `/portal/members` angeglichen.
(4) `docs/REGULATORIK.md` neu angelegt, erster Eintrag zur KNG-Mail. Fünf PRs (#148-#151, dazu
schon vorher #142-#147 aus Eintrag 93) gemergt, alle in `CLAUDE.md`/`Infrastruktur.md`
dokumentiert.

---

## 2026-09-09 (93) — Claude Code — Claude Sonnet 5
**Prompt:** Fortsetzung der offenen Daniel-Ropper-Frage aus Eintrag 92, per Live-Diagnose-Dialog
(SQL-Abfragen, `docker compose logs`, `\d audit_log`) gemeinsam mit Patrick durchgeführt, dazwischen
u.a.: "ich lade sie bei https://portal.stromfueralle.at/portal/eda/upload-interval auf, nicht auf
/portal/eda/upload" und am Ende zwei hochgeladene echte EDA-Exportdateien
(RC108175_20260701.../20260801...xlsx) mit dem Ergebnis der neuen Warnmeldung.
**Auftrag:** Klären, warum bei Daniel Roppers Einspeisungs-Diagramm nur der gelbe (gemeinschaftlich
genutzte), aber kein grauer (gesamter) Bereich erscheint -- ursprünglich aus Eintrag 92 offen
geblieben.
**Ergebnis:** Vierstufige Fehlerkette gefunden und behoben, alle per PR gemergt: (1)
`migrate_20260909.sql` -- `audit_log` fehlten nach `migrate_20260908.sql` noch `entity_typ`/
`beschreibung`/`ist_fehler` (Patricks Server hatte eine komplett andere Alt-Struktur); (2)
`migrate_20260910.sql` -- zwei NOT-NULL-Alt-Spalten (`action`, `entity_type`) ohne Default
blockierten jede Einfügung trotzdem weiter; (3) `eda-parser/parser_interval.py` +
`EdaParserRunner.php` -- `log.warning()`-Meldungen des Parsers landeten bei einem ERFOLGREICHEN
Import bisher nirgends sichtbar (stderr wurde verworfen), jetzt über `LoadResult.warnings` im
JSON-Ergebnis und direkt auf der Upload-Seite sichtbar; (4) dadurch von Patricks eigenem Testupload
sofort aufgedeckt und korrigiert: die Spaltenbezeichnung für `kwh_erzeugung_gesamt` war falsch
geraten (`"Gesamt/Überschusserzeugung, Gemeinschaftsüberschuss [kWh]"`, ohne Bindestrich, statt der
ursprünglichen Annahme). Fünf PRs (#142-#146) gemergt. Deploy-Doku in `CLAUDE.md`/
`Infrastruktur.md` ergänzt. Patrick muss betroffene Zeiträume einmal erneut über
`/portal/eda/upload-interval` hochladen, damit `kwh_erzeugung_gesamt` nachträglich befüllt wird.

---

## 2026-09-08 (92) — Claude Code — Claude Sonnet 5
**Prompt:** "Jetzt funktioniert so, halbert. Nur was da nicht so ganz passt: Bei den
Gitterstreifen habe ich gesagt: Bitte mach das. Schreib auch bei jedem Gitterstreifen die
Uhrzeit unten der x-Achse und bei der y-Achse auch bei jedem Streifen die Leistung in Watt,
damit man das besser ablesen kann und nicht ausrechnen müsste, welche Linie jetzt welche
Leistung ist. Was auch noch fehlt, ist bei der Erzeugung die gesamte Einspeisung von dieser
Person, weil zum Beispiel bei meinem Vater Daniel Ropper nur der gelbe Bereich angezeigt wird
und kein größerer grauer Bereich, der zum Beispiel eingespeist wird, aber nicht gemeinschaftlich
genutzt wird." -- dazu ein `docker compose logs webapp`-Auszug (ohne dass ich danach gefragt
hatte -- ich hatte um `git log -1 --oneline` gebeten).
**Auftrag:** Gitter-Beschriftung nachschärfen (jede Linie statt nur weniger fester Marken) sowie
klären, warum bei einem konkreten Einspeiser (Daniel Ropper) weiterhin nur der gemeinschaftlich
genutzte Anteil, keine Gesamtfläche erscheint.
**Ergebnis:** Gitter-Beschriftung sofort umgesetzt -- `interval_chart_grid.php` zeichnet jetzt zu
jeder der 12 Zeit- und 11 Leistungslinien eine passende Zahl, die alte gröbere Beschriftung
direkt in `my_verbrauch.php`/`my_einspeisung.php` wurde entfernt (einzige Quelle jetzt der
gemeinsame Partial). Mit Playwright verifiziert. Der gepostete Log-Auszug beantwortete die
eigentliche Frage (Daniel Ropper) nicht direkt, enthielt aber zwei unabhängige, echte Bugs:
(1) `column "aktion" of relation "audit_log" does not exist` -- JEDER `logAudit()`-Aufruf
schlägt auf diesem Server seit jeher fehl (fehlertolerant, kein Funktionsausfall, aber der
komplette Aktivitätslog fehlt); vermutlich wurde die Tabelle vor der committeten
`migrate_20260716.sql` schon einmal anders angelegt, wodurch deren `CREATE TABLE IF NOT EXISTS`
nie griff. Neue `migrate_20260908.sql` ergänzt die fehlende Spalte nachträglich (idempotent).
(2) `PHP Warning: Undefined variable $membersWithEspError in portal.php` -- der
Zähler-Berechnungsblock für die Sidebar-Badges stand nach dem "Mitglieder"-Link, der genau diese
Variable schon vorher braucht; das ESP-Fehler-Badge zeigte dadurch seit jeher nie etwas an,
unabhängig vom tatsächlichen Zustand. Block an den Anfang des Verwaltungs-Menüs verschoben. Die
eigentliche Daniel-Ropper-Frage bleibt offen -- keine Warnung zu `kwh_erzeugung_gesamt` im
geposteten Log-Ausschnitt sichtbar, aber der Ausschnitt zeigt auch keinen erkennbaren
Upload-Vorgang; Patrick um einen gezielten Test (Upload + sofortiger Log-Check) gebeten. Alle
134 Tests weiterhin grün, `php -l` sauber. Commit/Push/PR/Merge nach dem üblichen Workflow.

---

## 2026-09-07 (91) — Claude Code — Claude Sonnet 5
**Prompt:** "Was jetzt noch ist, ist, dass ich in der App sage, dass für diesen Tag noch keine
Gesamtespeisung bei den Einspeisern vorhanden ist, obwohl ich alle EDA-Dateien für die
Viertelstundenwerte für die Anzeige gelöscht habe. Trotzdem werden diese angezeigt. [...] Wie
kann ich die Datenbank für diese noch mal löschen, damit ich sie neu hochladen kann und diese
dann übernommen werden? Denn nur ein Hochladen der gleichen Monate, aber mit den neuen Daten,
funktioniert nämlich nicht. Außerdem wäre beim Diagramm bei den Einspeisern und Beziehern noch
interessant, wenn man im Hintergrund so ein graues Gitter einzeichnen würde, mit ein bisschen
genauerer Zeitunterteilung, vielleicht im Stunden- oder im 2-Stunden-Takt. Das Gleiche auf der
Leistungshöhe [...] in 10 Teilungen."
**Auftrag:** Zwei Anliegen: (1) Diagnose, warum ein erneuter EDA-Upload die neue
Gesamteinspeisung-Spalte nicht übernimmt, obwohl vermeintlich alle Dateien gelöscht wurden, plus
ein Weg, die zugrundeliegenden Datenbank-Werte gezielt zu löschen; (2) ein dezentes
Hintergrund-Gitter für beide Viertelstunden-Diagramme (Zeitachse alle 1-2h, Leistungsachse in
10 Teilen).
**Ergebnis:** (2) sofort umgesetzt -- neuer gemeinsamer Partial
`webapp/src/views/partials/interval_chart_grid.php` zeichnet senkrechte Linien alle 2 Stunden
und waagrechte Linien in 10 Abschnitten der Leistungsachse, dezent grau, hinter den Flächen
(daher innerhalb der gefüllten Bereiche nicht sichtbar, wie bei den meisten Charts üblich) --
in beide Diagramme eingebunden, mit Playwright verifiziert (Raster sichtbar in den nicht von
den Flächen bedeckten Bereichen, keine JS-Fehler). (1) Codeanalyse ergab: der
Papierkorb-Button in der Import-Historie unter `/portal/eda/upload` löscht laut eigenem
Bestätigungsdialog ausdrücklich NUR den Protokolleintrag, NICHT die Messwerte selbst ("Nur
Protokolleintrag löschen" bzw. "Messwerte bleiben erhalten") -- das erklärt vermutlich, warum
die alten Werte trotz vermeintlichem Löschen weiter angezeigt wurden. Zusätzlich: die
Import-Logik selbst (`import_to_db()` in `parser_interval.py`) löscht beim erneuten Hochladen
bereits automatisch alle vorhandenen Werte im exakten Zeitraum der neuen Datei, bevor die neuen
Zeilen eingefügt werden -- ein normaler erneuter Upload sollte alte Werte also eigentlich schon
ersetzen, ohne dass manuell in der Datenbank aufgeräumt werden muss. Wahrscheinlichste Erklärung
für "funktioniert nicht": entweder lief beim Test noch der alte Server-Stand (PR #140 von diesem
Tag noch nicht deployt) oder die tatsächliche Spaltenbeschriftung im echten EDA-Export weicht
von der angenommenen ab (siehe Warnung im Parser-Log). Patrick wurden beide Diagnoseschritte
sowie ein manueller SQL-Befehl zum gezielten Löschen der `eda_interval_data`-Zeilen für einen
bestimmten Zeitraum als Absicherung mitgegeben. Alle 134 Tests weiterhin grün, `php -l` sauber.
Commit/Push/PR/Merge nach dem üblichen Workflow (nur für Punkt 2 -- Punkt 1 war reine Diagnose/
Beratung ohne Code-Änderung).

---

## 2026-09-07 (90) — Claude Code — Claude Sonnet 5
**Prompt:** "Hey du, ich habe eine weitere Idee, die du bitte umsetzen solltest: [...] Bei den
Einspeisern gefällt mir das so nicht ganz. Bei den Einspeisern haben wir jetzt ja nur, wie viel
sie wirklich in die Energiegemeinschaft einspeisen. Was aber noch interessant ist: Gleich wie
bei den Verbrauchern zu den Einspeisern darstellen, wie viel sie einspeisen und wie viel davon
in der Energiegemeinschaft verwendet wurde. [...] Von mir ist auch in Grau. Soll der gesamte
Einspeisung und was Energiegemeinschaftlich genutzt wurde bitte in Gelb markieren. Bei den
Daten oder beim langsam hin- und herscrollen gefällt mir das nicht so ganz. [...] Interessant
wäre, wenn man so über eine Eingabe oder über Pfeiltasten zu den Monaten springen kann und dann
aber mit Zahlen von 1 bis so viel wie der Monat hat [...] markieren. Wenn Daten vorhanden sind,
bei den Verbrauchern grün, bei den Einspeisern gelb. Wenn noch keine Daten vorhanden sind [...]
in Grau."
**Auftrag:** Zwei Erweiterungen für die Viertelstunden-Diagramme der Mitglieder: (1) das
Einspeisung-Diagramm sollte wie das bereits bestehende Verbrauchs-Diagramm eine
Gesamt-vs.-Anteil-Darstellung zeigen (graue Gesamtfläche, gelber gemeinschaftlich genutzter
Anteil), bisher gab es dort nur den genutzten Anteil allein; (2) die bisherige
Vortag/Folgetag-Navigation war zu langsam für größere Zeitsprünge -- gewünscht war eine
Monats-Navigation (Eingabe oder Pfeiltasten) mit einem Tage-Raster, farblich nach
Datenverfügbarkeit markiert.
**Ergebnis:** (1) Neue DB-Spalte `kwh_erzeugung_gesamt` (`database/migrate_20260907.sql`) --
der EDA-Export enthält eine dritte, bisher ungenutzte Kennzahl-Spalte ("Gesamt-/
Überschusserzeugung") für die eigene Gesamterzeugung eines Einspeise-Zählpunkts, `parser_interval.py`
liest sie jetzt mit ein (samt Warnung, falls die Spaltenbeschriftung in der echten Datei
abweicht -- noch nicht gegen eine reale Exportdatei verifiziert). `/portal/my/einspeisung`
zeigt jetzt ein gestapeltes Flächendiagramm (grau = Gesamterzeugung, gelb = gemeinschaftlich
genutzter Anteil) analog zum Verbrauchs-Diagramm, mit sauberem Fallback auf die alte
Einzel-Linien-Ansicht für Tage, die vor der Migration importiert wurden (sonst hätte die gelbe
Fläche optisch über eine leere graue Fläche hinausgeragt). `/api/v1/production/interval` liefert
die neuen Felder zusätzlich für die App. (2) Neuer gemeinsamer Partial
`webapp/src/views/partials/interval_day_picker.php` (von beiden Diagrammen genutzt): Monats-Nav
per Pfeil-Buttons, `<input type="month">` und Pfeiltasten (deaktiviert bei Fokus in einem
Eingabefeld), darunter ein Tage-Raster mit grün/gelb (Daten vorhanden) bzw. grau (noch nicht)
je nach Diagrammtyp. Mit Playwright gegen vier Szenarien (Verbraucher, Einspeiser mit/ohne neue
Spalte, keine Daten) sowie Monats-Navigation per Klick UND Pfeiltaste verifiziert (Screenshots,
keine JS-Fehler). Nebenbei zwei veraltete "noch nicht abgedeckt"-Hinweise zur
Demo-Account-Maskierung in CLAUDE.md/Infrastruktur.md korrigiert (waren in der Zwischenzeit
längst geschlossen worden). Alle 134 PHP-Tests weiterhin grün, `php -l`/`python3 -m py_compile`
sauber. Commit/Push/PR/Merge nach dem üblichen Workflow.

---

## 2026-08-25 (89) — Claude Code — Claude Sonnet 5
**Prompt:** Drei Screenshots (Login-Seite und Backoffice-Dashboard auf portal.stromfueralle.at
ohne sichtbares Logo, Startseite auf stromfueralle.at mit korrektem Logo): "Auf der Seite
stromfueralle.at funktioniert das aber. Unter portal.stromfueralle.at wird gar kein Logo
angezeigt. Oder es gibt eine Charakteristik, dass hier ein Logo wäre, aber gerade gar keins.
Bitte da noch mal schauen, dass portal.stromfueralle.at auch die Routen geändert werden."
**Auftrag:** Direkte Fortsetzung/Nebenwirkung des vorigen Fixes (PR #137, Entfernung der beiden
Schattendateien) -- auf der Hauptdomain funktionierte das Logo jetzt, auf der Portal-Subdomain
war es komplett verschwunden statt nur falsch.
**Ergebnis:** Ein vom Nutzer bereitgestelltes HAR-File (DevTools-Network-Export) zeigte die
Ursache eindeutig: der Bild-Request `/logo-light.png` bekam von `portal.stromfueralle.at` aus
einen 302-Redirect auf die Hauptdomain zurück -- eine bereits bestehende, aber bisher folgenlose
Domain-Trennungs-Logik in `index.php` (alles außer `/portal/*`/`/admin/*` wird von der
Portal-Subdomain auf die Hauptdomain umgeleitet). Die eigene CSP (`img-src 'self'`) verbietet dem
Browser, dieser domainübergreifenden Bild-Weiterleitung zu folgen -- das Logo blieb dadurch leer,
ganz ohne sichtbaren Fehler. Diese Logik existierte schon lange, wurde aber nie erreicht: die im
vorigen Fix entfernten statischen Schattendateien hatten den Request bisher immer schon auf
nginx-Ebene abgefangen, bevor er überhaupt bei `index.php` ankam -- das Entfernen dieser Dateien
hat also einen zweiten, unabhängigen, bis dahin unsichtbaren Bug erst freigelegt. Fix: neue
`$isSharedAsset`-Ausnahme in der Domain-Trennungs-Logik nimmt `/logo-light.png` und
`/logo-dark.png` explizit von der Portal→Hauptdomain-Weiterleitung aus -- beide Pfade werden
jetzt auf jeder Domain lokal beantwortet. `/infoblatt.pdf`/`/hero-banner-image` bewusst nicht
mit ausgenommen (nur auf reinen Marketing-Seiten verlinkt, nie von der Portal-Subdomain aus
angefragt). Alle 134 Tests weiterhin grün, `php -l` sauber. CLAUDE.md/Infrastruktur.md ergänzt.
Commit/Push/PR/Merge nach dem üblichen Workflow.

---

## 2026-08-24 (88) — Claude Code — Claude Sonnet 5
**Prompt:** Fortsetzung der Logo-Diagnose über mehrere Nachrichten: "noch immer der fehler"
(Safari-Fehlerscreenshot nach dem deployten Fix) → nach bestätigtem Deploy und Browser-Test:
"Ja stimmt. Synchronisation war ausgeschaltet. aber trozdem ist das alte bild noch drinnen. auch
mit https://stromfueralle.at/logo-dark.png kommt noch das light logo. es liegt trozdem am
server" → abschließend ein vom Nutzer bereitgestelltes HAR-Datei-Export sowie mehrere
Terminal-Ausgaben (Datei-Vergleich im Container, curl-Test) auf meine gezielten Rückfragen hin.
**Auftrag:** Trotz zweier vorheriger Fixes (Cache-Busting, Safari-Redirect-Workaround) zeigte das
Dark-Mode-Logo weiterhin das falsche Bild -- Patrick wollte das endgültig geklärt haben, auch
nachdem er selbst schon einen Teil der Ursache (deaktivierte iCloud-Synchronisation) gefunden
hatte.
**Ergebnis:** Drei tatsächlich unabhängige Ursachen nacheinander aufgedeckt, jede mit einem
eigenen, hartnäckig zwingenden Beweis statt bloßer Vermutung: (1) Analyse einer vom Nutzer
bereitgestellten HAR-Datei zeigte einen Content-Length/bodySize-Widerspruch (31830 angekündigt,
nur 371 tatsächlich gesendet) -- die vom Nutzer bereits vermutete iCloud-Drive-Platzhalterdatei
war die Ursache für die gescheiterten Upload-Versuche, kein Bug in diesem Repo. (2) Nach
Einschalten der Synchronisation bestätigte ein `md5sum`-Vergleich im Container, dass der Upload
jetzt korrekt ankam (zwei tatsächlich unterschiedliche Dateien) -- aber `curl` direkt auf dem
Server (also ganz ohne Browser-Cache) lieferte trotzdem den falschen Hash. (3) Das führte zur
eigentlichen, seit 17.08.2026 bestehenden Ursache: `webapp/public/logo-dark.png` und
`logo-light.png` waren als echte, byte-identische Dateien fest ins Repo eingecheckt (damalige
Notlösung für ein anderes Problem) -- `nginx.conf`s `try_files`-Regel lieferte diese SOFORT aus
und erreichte nie die dynamische PHP-Route, wodurch JEDER Logo-Upload seit diesem Datum
wirkungslos blieb, nicht nur Patricks aktueller Versuch. Fix: `git rm` beider Platzhalterdateien,
sodass `try_files` korrekt zur PHP-Route (`adminFilePath()`, Live-Upload-Volume zuerst) durchreicht.
CLAUDE.md/Infrastruktur.md entsprechend korrigiert (vorherige Diagnose als "real, aber nicht die
Ursache" markiert statt einfach überschrieben). Alle 134 Tests weiterhin grün. Commit/Push/PR/
Merge nach dem üblichen Workflow, mit ausdrücklichem Hinweis, dass ein echter Image-Rebuild
(nicht nur `git pull`) nötig ist, da die Platzhalterdateien bereits im laufenden Docker-Image
liegen.

---

## 2026-08-24 (87) — Claude Code — Claude Sonnet 5
**Prompt:** "Hey, das mit der Grafik und der Live-Anzeige sieht jetzt gut aus. Nur, was nicht
passt, ist das mit dem Logo und dem Dark-Mode. Ich habe noch mal das Bild hochgeladen. Es ist
transparent und sollte im Dark-Mode keinen weißen Hintergrund haben. Es wird aber das Bild, was
im Light-Mode ist, verwendet. Bitte schau das noch mal an." -- gefolgt (nach Rückfrage) von einem
Screenshot: "Safari kann die Seite nicht öffnen [...] Fehler: 'request body stream exhausted'
(NSURLErrorDomain:-1021)" beim Öffnen von `/admin/templates/logo-dark.png/upload`, und: "Also,
wenn ich diese URL im Browser ausführe, komme ich auf das helle Logo. Jetzt habe ich noch mal ein
dunkles hochgeladen, aber ich glaube, da muss mit der Route etwas falsch sein. Auf jeden Fall:
Auch wenn ich jetzt ein neues transparentes für den Dunkelmod hochlade, kommt dieser Fehler."
**Auftrag:** Der im vorigen Durchgang implementierte Cache-Busting-Fix für das Dark-Mode-Logo
hatte das eigentliche Problem nicht gelöst -- Patrick sollte das noch mal überprüfen, weil das
Dark-Mode-Logo weiterhin wie das Light-Mode-Logo aussah.
**Ergebnis:** Der Screenshot zeigte die tatsächliche Ursache: ein bekannter WebKit-Bug lässt
größere Datei-Uploads in Safari mit "request body stream exhausted" fehlschlagen, sobald der
Server mit einem klassischen 3xx-Redirect antwortet (`header('Location: ...')`, das
"POST/Redirect/GET"-Muster, das praktisch jeder Upload-Handler im Repo bisher verwendet hat) --
die neue Logo-Datei kam dadurch nie beim Server an, weshalb jeder erneute Upload-Versuch
wirkungslos blieb (kein Cache-/Serving-Bug, wie zunächst vermutet). Fix: neue Hilfsfunktion
`uploadRedirect()` (`webapp/public/index.php`) ersetzt den 3xx-Redirect nach einem Datei-Upload
durch eine 200-OK-Antwort mit einer kleinen HTML-Seite (Meta-Refresh + JS-Fallback) -- kein
Stream-Nachsenden mehr nötig, funktioniert in jedem Browser identisch. Angewendet auf
`/admin/templates/:name/upload` (Logos, LaTeX-Vorlagen, Infoblatt, Hero-Banner) und
`/portal/settings/logo` (EEG-eigenes Logo), bewusst nicht auf die übrigen kleineren
Upload-Routen ohne bestätigten Fehlerbericht. Vorherige `logoAssetUrl()`-Cache-Bust-Änderung
blieb als sinnvolle Absicherung bestehen, war aber nicht die eigentliche Lösung -- CLAUDE.md
entsprechend korrigiert/ergänzt statt nur angehängt. Alle 134 Tests weiterhin grün, `php -l`
sauber. Commit/Push/PR/Merge nach dem üblichen Workflow.

---

## 2026-08-24 (86) — Claude Code — Claude Sonnet 5
**Prompt:** [Zwei Screenshots: leere Live-Anzeige mit Fehlermeldung, Demo-Portal-Dashboard]
"es kommt noch keine Anzeige. Hab es auf einem anderen Gerät auch schon probiert. Das Problem
noch zur Live-Anzeige, die Linie von Netzt und Verbraucher soll schon auf Höhe des Mittelpunktes
sein. so sieht es ja gar nicht gleich aus. Bitte die Kreise so weit runter, dass sie mit dem
Mittelpunkt auf Höhe der Linie sind. Und was noch cool wäre ist, dass zuerst alle Energieflüsse
rein in die EEG gehen, und dann nach den 0,5 sec. alle Flüsse raus gehen. Als bei einsoieung ins
Netz: PV zur EEG und dann von EEG ins Netz und Verbraucher. zu wenig Einspeisung für voll
abdeckung vom Verbrauch: PV und Netz rein und Verbraucher raus. außerdem passt das Logo noch
nicht. Kann es sein, dass im Darkmode auch das Logo vom light mode genommen wird. Wir haben
aber ein eigens."
**Auftrag:** Drei Anliegen in einer Nachricht: (1) die öffentliche Live-Anzeige zeigte trotz
bereits laut `curl` korrekt liefernder API weiterhin nichts im Browser; (2) die Netz-/
Verbrauch-Kreise sollten optisch exakt auf der horizontalen Verbindungslinie zentriert sein statt
sichtbar darüber, zusätzlich sollten Impulse phasenweise synchron laufen (erst alle "rein",
dann alle "raus"); (3) im Dark-Mode erschien weiterhin das Light-Mode-Logo statt des eigens
hochgeladenen Dark-Mode-Logos.
**Ergebnis:** (1) Ursache gefunden und behoben: die eigene Content-Security-Policy blockierte
Chart.js, das `live.php` bisher von `cdn.jsdelivr.net` nachlud -- `Chart` blieb undefiniert, die
eigene Fehlerbehandlung zeigte fälschlich "Verbindung fehlgeschlagen". Fix: Chart.js per
`npm pack chart.js@4` lokal besorgt und nach `webapp/public/assets/js/vendor/chart.umd.min.js`
vendored (gleiches Muster wie `gsap.min.js`), `live.php` lädt jetzt lokal statt vom CDN.
(2) `energy_flow.php`: Wert/Label per `position:absolute` aus dem Höhen-Fluss der Kreis-Knoten
genommen, wodurch `align-items:center` jetzt wirklich die Kreis-Mittelpunkte statt der
unterschiedlich hohen Gesamt-Säulen zentriert -- per Playwright geometrisch verifiziert (0,00px
Differenz). Zwei-Phasen-Impuls-Synchronisation umgesetzt (Start-Offset 0s bzw. 1,5s je
Verbindung, gegen dieselbe Dokument-Zeitachse) -- ein erster Versuch mit einem gemeinsamen
Zeitgeber-Element scheiterte an einer Chromium-SMIL-Einschränkung (per Playwright-Zeitstempel-
Polling entdeckt und dokumentiert), die finale Fassung mit parametrisiertem Selbstreferenz-Start
funktioniert nachweislich synchron in beiden Szenarien (Einspeisung/Defizit). (3) Ursache war
fehlendes Cache-Busting bei `/logo-light.png`/`/logo-dark.png` (`Cache-Control: max-age=3600`,
aber statische URL nach jedem Upload) -- neue Funktion `logoAssetUrl()` hängt `?v=<filemtime>`
an, gleiches Muster wie bei `app.css`. Alle 134 Tests weiterhin grün, `php -l` sauber,
`CLAUDE.md`/`Infrastruktur.md` aktualisiert. Commit/Push/PR/Merge nach dem üblichen Workflow.

---

## 2026-09-07 (85) — Claude Code — Claude Sonnet 5
**Prompt:** [Terminal-Ausgabe] "docker compose logs webapp --tail 200 | grep -i \"unhandled|fatal\"
webapp | NOTICE: PHP message: [unhandled] PDOException: SQLSTATE[XX000]: Internal error: 7 ERROR:
unsupported subplan type for SkipScan: Result in /var/www/html/src/DB.php:66 [...] Dateien hast
du eh alle auf GitHub. Bitte mach die Linien bei Netz und Verbrauch waagrecht. das schiefe gefällt
mir nicht. Also Links rechts oben unten."
**Auftrag:** Die korrekte Log-Ausgabe (diesmal aus dem richtigen Verzeichnis geholt) liefert den
eigentlichen Fehler: ein TimescaleDB-interner PDOException-Fehler, der bei jedem Aufruf von
`/api/live/:slug` auftritt -- die vorherige DB-Rollen-Diagnose war zwar ein echtes, aber nicht das
einzige Problem. Zusätzlich: die Netz-/Verbrauch-Verbindungslinien in der Energiefluss-Grafik
sehen leicht schräg statt exakt waagrecht aus.
**Ergebnis:** Fehler identifiziert als TimescaleDB-SkipScan-Inkompatibilität mit
`metering_point_id NOT IN (SELECT ...)` auf dem Hypertable `esp_measurements` -- exakt die
Demo-Zählpunkt-Ausschlussregel, die in einer früheren Runde (06.09.2026) eingebaut wurde, aber
nur in `/api/live/:slug` als NOT-IN-Subquery statt als JOIN. `communityLivePower()` (dieselbe
Regel, für die eingeloggte Dashboard-Ansicht) hatte von Anfang an das richtige JOIN-Muster
verwendet und war deshalb nie betroffen -- `/api/live/:slug` jetzt auf dasselbe, bereits bewährte
Muster umgestellt (alle drei betroffenen Queries: aktuelle Leistung, Energie heute, Zeitreihe).
Mit einer lokalen Scratch-Datenbank (echte Mitglieder + gespiegelter Demo-Zählpunkt mit
identischen Messwerten) verifiziert: korrekte Summe ohne Doppelzählung, keine SQL-Fehler. Die
tatsächliche TimescaleDB-SkipScan-Inkompatibilität selbst ließ sich lokal nicht reproduzieren
(keine TimescaleDB-Erweiterung in der Scratch-Umgebung), aber die Umstellung auf das identische,
bereits produktiv bewährte JOIN-Muster ist die naheliegende und in der Sache korrekte Lösung.
`energy_flow.php`: neue Funktion `trimHorizontal()` erzwingt für Netz/Verbrauch dieselbe
Y-Koordinate (die des EEG-Knotens) statt der individuell gemessenen Kreis-Mitte -- per
`getAttribute('d')`-Vergleich verifiziert (y1 === y2 exakt). CLAUDE.md + Infrastruktur.md
aktualisiert (bestehenden "Bekannte Probleme"-Eintrag um die eigentliche Ursache ergänzt statt
nur den Teil-Fix stehen zu lassen), Commit/Push/PR/Merge nach main.

## 2026-09-07 (84) — Claude Code — Claude Sonnet 5
**Prompt:** "Energiefluss-Animation – finale Anpassung. Die aktuelle Umsetzung ist grundsätzlich
besser, aber die Energiefluss-Linien sind noch zu stark geschwungen. Bitte die Animation jetzt
konsequent geradlinig umsetzen. 1. ABSOLUT KEINE KURVEN [...] Die Verbindung soll immer die
direkte kürzeste gerade Strecke zwischen Ausgangs- und Zielkreis sein. [...] 2. PV-ERZEUGUNG [...]
Die Linie darf dabei direkt durch den Bereich des Textes „PV-Erzeugung" laufen. [...] Der Text
soll NICHT verschoben werden. [...] 4. WICHTIG: EIN EINZELNER ENERGIE-IMPULS [...] Ein einzelner
animierter Energieimpuls startet am Ausgangskreis [...] Er erreicht den Zielkreis. Der Impuls
verschwindet vollständig. Danach 0,5 Sekunden Pause. Erst danach startet der nächste Impuls. [...]
7. Timing [...] Bewegung: ca. 0,8-1,2 Sekunden, Pause nach Ankunft: exakt ca. 0,5 Sekunden [...]
9. Technische Umsetzung [...] Keine festen Pixelpositionen für die Animation verwenden. [...]
wenn findet die Live Anzeige auf der Website ohne Anmeldung die EEG nicht, oder die Daten werden
einfach nicht angezeigt." (mit zwei Screenshots der Live-Anzeige, weiterhin ohne Daten trotz
bereits ausgeführtem `db_runtime_role_setup.sh`).
**Auftrag:** Die Bezier-Kurven-Lösung der letzten Runde war ein Missverständnis -- Patrick wollte
ausschließlich gerade Linien (auch für PV, bewusst durch den Text hindurch) und genau EINEN
Energie-Impuls pro Verbindung mit klarem Rhythmus (Bewegung -> Ziel -> verschwinden -> 0,5s Pause
-> nächster Impuls) statt mehrerer gleichzeitiger Punkte. Außerdem weiterhin keine Live-Daten auf
der öffentlichen Anzeige, obwohl die vermutete DB-Rollen-Ursache aus Runde 83 bereits behoben
wurde -- also entweder ein zweites, unabhängiges Problem oder ein reiner UX-Fall (Name eingetippt,
aber kein Dropdown-Eintrag angeklickt).
**Ergebnis:** `energy_flow.php` überarbeitet: Bezier-Kurve entfernt, alle drei Verbindungen jetzt
gerade Linien (weiterhin geometrisch aus den echten Kreis-Positionen berechnet, keine festen
Pixelwerte). Ein einzelner Impuls je aktiver Verbindung über SVG `<animateMotion>` mit
`begin="0s;<eigene-id>.end+0.5s"` (Standard-SMIL-Idiom für Selbstwiederholung mit Pause,
`repeatCount` allein kann das nicht) plus eine synchron gekoppelte `<animate>` auf `opacity` für
sauberes Ein-/Ausblenden. Vor dem Einbau in einer isolierten Testdatei gegen echtes Chromium per
präzisem `page.evaluate()`-Zeitstempel-Polling verifiziert (nicht nur Screenshots, da
Screenshot-Timing durch Seiten-Ladezeit zu ungenau für 0,5s-Fenster ist) -- Ergebnis exakt wie
gefordert: 1s Bewegung, dann 0,5s bei Opacity 0 eingefroren, dann Neustart. Für die Live-Anzeige:
`live.php` zeigt jetzt eine sichtbare Fehlermeldung statt stillschweigend nichts zu tun, plus
Enter im Suchfeld lädt direkt bei genau einem/exaktem Treffer (auch ohne Dropdown-Klick) -- gegen
eine eigens gebaute Mock-Umgebung (echtes `live.php` + gefälschte API-Antworten) mit Playwright
verifiziert. Ehrlich eingeordnet: das behebt nicht zwingend die eigentliche Ursache -- bei einer
unbehandelten PHP-Exception zeigt die Route nur "Fehler 500", der tatsächliche Exception-Text
steht weiterhin ausschließlich in `docker compose logs webapp` (aus `/opt/eeg-platform` heraus,
Patricks letzter Versuch lief aus dem Home-Verzeichnis und scheiterte deshalb an "no configuration
file provided"). CLAUDE.md + Infrastruktur.md aktualisiert (bestehenden Eintrag aus Runde 83
korrigiert statt widersprüchlich stehen zu lassen), Commit/Push/PR/Merge nach main.

## 2026-09-07 (83) — Claude Code — Claude Sonnet 5
**Prompt:** [Terminal-Screenshot: `db_runtime_role_setup.sh` auf dem Server ausgeführt, GRANTs
aktualisiert, webapp neu gestartet -- Diagnose aus Runde 82 bestätigte sich.] "Energiefluss-Grafik
– Animation und Verbindungen überarbeiten. Bitte überarbeite die Energiefluss-Grafik grundlegend
nach dem Vorbild der Fronius-Energiefluss-Darstellung. 1. Hauptproblem: Animation muss direkt aus
den Kreisen kommen [...] Die Animation darf nicht erst mehrere Pixel/Abstände außerhalb des Kreises
beginnen. Keine sichtbare Lücke zwischen Kreis und animiertem Energiefluss. 2. PV-Erzeugung konkret
korrigieren [...] Die Verbindung muss stattdessen direkt am unteren Rand des PV-Kreises beginnen
[...] Der Text „676 W" und „PV-Erzeugung" bleibt an seiner bisherigen Position. [...] Die
Verbindung darf sich optisch nicht mit dem Text überschneiden. 3. Bessere Animationslogik [...]
ein kleiner animierter Energie-Impuls [...] 6. Noch bessere Lösung: dynamische Geometrie [...]
sollen die Start- und Endpunkte dynamisch aus den Positionen und Radien der Kreise berechnet
werden. 7. Animation abhängig von der tatsächlichen Leistung [...] Bitte die bestehende Grafik
ansonsten möglichst unverändert lassen: Farben beibehalten, Typografie beibehalten, Größen der
Kreise beibehalten, Positionierung grundsätzlich beibehalten."
**Auftrag:** Zwei Themen: (1) Bestätigung, dass die vermutete DB-Rollen-Ursache für die leere
öffentliche Live-Anzeige stimmte (Patrick hat `db_runtime_role_setup.sh` selbst erneut ausgeführt);
(2) die Energiefluss-Grafik (Obmann-/Mitglied-Dashboard) grundlegend überarbeiten -- Verbindungen
sollen geometrisch exakt am Kreisrand beginnen/enden statt mit sichtbarer Lücke, die PV-Verbindung
darf den Text darunter nicht überlagern, ein einzelner animierter Impuls statt ein-/ausblendender
Striche, Anzahl/Tempo abhängig von der tatsächlichen Leistung, Geometrie dynamisch aus den
Kreis-Positionen berechnet statt fixer Pixelwerte -- Farben/Typografie/Größen/Layout unverändert.
**Ergebnis:** `docs/CLAUDE.md`/`Infrastruktur.md` um einen "Bekannte Probleme"-Eintrag zur
Live-Anzeige-Ursache (`eeg_app`-Rolle hatte veraltete GRANTs, Fix: Skript erneut ausführen)
ergänzt. `partials/energy_flow.php` komplett neu gebaut: die starren CSS-Connector-Divs sind
durch eine per JS aus `getBoundingClientRect()` berechnete SVG-Geometrie ersetzt -- Basislinien
beginnen/enden exakt am Kreisrand, die PV-Verbindung ist eine Bezier-Kurve, die um die volle
Breite des PV-Knotens (Kreis + Text) seitlich ausweicht, animierte Punkte laufen per SVG
`<animateMotion>`/`<mpath>` entlang jeder Verbindung, Anzahl (1-3) und Tempo skalieren mit der
Leistung, bei 0 W keine Punkte. Vor dem Commit mit Playwright gegen das echte `app.css` (Light +
Dark, drei Leistungsszenarien: Bezug, hohe Einspeisung, 0 W) gerendert und per Screenshot
verifiziert -- dabei einen reinen Test-Artefakt-Bug (doppelte IDs bei mehreren Instanzen auf
einer Vorschauseite) erkannt und die Testseite entsprechend korrigiert, kein Produktionsbug.
134/134 Tests weiterhin grün (keine PHP-Logik geändert, nur Markup/CSS/JS des Partials).
CLAUDE.md + Infrastruktur.md aktualisiert, Commit/Push/PR/Merge nach main.

## 2026-09-07 (82) — Claude Code — Claude Sonnet 5
**Prompt:** "Eine Sache, die auch in jedem Fall nicht funktionieren darf, ist, ein Mitglied zu
bearbeiten, weil man, wenn man auf „Mitglied bearbeiten" klickt, wieder alle Daten in Klartext
sieht und alles wirklich. also /members/<id>/edit darf nicht verfügbar sein. Zwei kleine Sachen
noch: Bitte kannst du mir mal sagen, warum diese Echtzeitdaten auf den normalen Webseiten nicht
angezeigt werden? Vielleicht liegt es ja noch mal an einer DB-Rolle, Datenbankrolle oder User, aber
auf jeden Fall werden keine Daten mehr auf der normalen Plattform angezeigt, wenn man sich nicht
angemeldet hat. So wie, wenn man als Admin ein Ticket bekommt und es aufmacht, steht drinnen
trotzdem immer der volle Name. Bitte im Demo-Account das verschleiern." (mit Screenshot der
öffentlichen /live-Seite).
**Auftrag:** Drei Punkte: (1) das Mitglied-Bearbeiten-Formular (zeigt unmaskierte Werte in
Eingabefeldern) für den Demo-Zugang komplett sperren statt maskieren; (2) erklären, warum die
öffentliche Live-Anzeige für abgemeldete Besucher keine Daten zeigt -- vermutet selbst einen
DB-Rollen-Zusammenhang; (3) Namen in Support-Ticket-Nachrichten (nicht nur im Ticket-Header)
für den Demo-Zugang maskieren.
**Ergebnis:** Neuer genereller Helper `denyDemoPage()` (Refactor von `denyDemoFileDownload()`)
blockt `/portal/members/:id/edit` komplett für den Demo-Zugang, der "Bearbeiten"-Button selbst
bleibt sichtbar. Neue Funktion `demoMaskSupportMessages()` maskiert `author_label` in
`support_ticket_messages` (freier Text, bisher trotz maskiertem Ticket-Header unmaskiert) für
Mitglied- UND Verwaltungs-Nachrichten, eigene Nachrichten fiktiver Demo-Mitglieder bleiben
unmaskiert. 134/134 Tests grün, beide Fixes zusätzlich gegen eine Scratch-DB verifiziert (inkl.
Bestätigung, dass PDO_PGSQL Booleans hier korrekt nativ liefert, kein 't'/'f'-String-Fallstrick).
Für die Live-Daten-Frage: Recherche im Code ergab, dass `/api/live/:slug` und
`/api/communities/search` unverändert seit den letzten Runden sind (kein eigener Regressions-Fund),
`esp_measurements` hat FORCE ROW LEVEL SECURITY seit dem OWASP-Audit und die Route setzt
`DB::setCommunity()` korrekt vor der Abfrage -- ohne Zugriff auf den Produktivserver keine
abschließende Diagnose möglich, stattdessen konkrete Prüfschritte (APP_DB_USER-Grants,
webapp-Logs auf PDO-Fehler) an Patrick zurückgegeben statt spekulativ Code zu ändern.
CLAUDE.md + Infrastruktur.md aktualisiert, Commit/Push/PR/Merge nach main.

## 2026-09-06 (81) — Claude Code — Claude Sonnet 5
**Prompt:** "SSID: •••• · IP: •••••••••• · WLAN-Passwort: •••••••• Da hat etwas falsch verstanden.
Die WLAN-Info-Anzeigen sollen schon bleiben für einen Admin oder für den Obermann-Bereich, aber der
Demo-Account soll gar nicht sehen, dass es die Möglichkeit gibt, WLAN-Infos anzuzeigen, weil das
dann schon rechtlich jetzt nicht okay ist, dass ein Demo-Account das sieht. Bitte baue das wieder
zurück: Baue die WLAN-Info-Anzeigen wieder her, sodass das Pop-up-Fenster wieder herkommt. In dem
Account soll einfach das ganze ausgeblendet werden, sodass man es gar nicht sieht, dass man es
nachsehen könnte. Bitte aber wieder hinbauen für den Admin. Weil ich nämlich Platz sparen muss:
Wenn jetzt die SSID, die IP-Adresse und das WLAN-Passwort alles da in dieser Zeile oder in diesem
Feld stehen, ist das viel zu viel. Bitte wieder das Pop-up, in dem man hineinkommt, wenn man auf
„WLAN Info anzeigen" klickt, aber nur für: Obmann-Anzeigen, Admin. Demo-Admin soll es ausgeblendet
sein. Sowie bitte für zukünftige Rechnungen. Noch gibt es keine Rechnungen in der Rechnungsliste,
aber bitte diese auch wieder maskieren."
**Auftrag:** Die WLAN-Info-Änderung der letzten Runde war ein Missverständnis -- Patrick wollte
keine automatische Inline-Anzeige (zu viel Text, "Platz sparen"), sondern das ursprüngliche
Popup zurück, dafür aber für den Demo-Zugang komplett unsichtbar (nicht nur maskiert, sondern gar
kein Button mehr -- schon das Vorhandensein der Möglichkeit ist ihm rechtlich zu heikel). Zusätzlich
soll die Rechnungsliste (aktuell noch leer, aber vorsorglich) maskiert werden.
**Ergebnis:** `member_detail.php` zurückgebaut auf den Button+`alert()`-Popup wie ursprünglich,
aber `<?php if (!Auth::isDemo()): ?>` blendet den kompletten Button für den Demo-Zugang aus
(Obmann/Platform-Admin sehen ihn weiterhin normal). Der zugrundeliegende
`/portal/members/:id/metering-points/:mpid/wifi-info`-Endpunkt bleibt zusätzlich wie gehabt
maskiert. `/portal/billing/invoices` + `/portal/billing/invoices/:id/edit` maskieren jetzt über
`demoMaskMembers()`/`demoMaskMember()` Mitgliedernamen/E-Mail/IBAN/Mandatsreferenz (beide Queries
um `is_demo` ergänzt). 130/130 Tests weiterhin grün, Queries gegen eine Scratch-DB verifiziert.
CLAUDE.md + Infrastruktur.md aktualisiert, Commit/Push/PR/Merge nach main.

## 2026-09-06 (80) — Claude Code — Claude Sonnet 5
**Prompt:** "Aktivitätslog und Beitragsanträge auch noch maskieren. Und eine Sache hätte ich noch
bitte gern: Kannst du mir bitte bei jedem Mitglied das WLAN-Info-Feld verlinken, sodass dieses
WLAN-Info anzeigen-Feld erscheint und man es nicht sieht? Dann sieht man die Zielpunktnummer, die
Zählernummer, die Pixelanzahl, die Firmwareversion und Online. Das kann auch bleiben, aber nicht
darunter den kleinen Schriftzug „WLAN-Info anzeigen". Man sieht dann die SSID, das Passwort und
die IP-Adresse, weil das ja auch noch nichts anbauen geht." (mit Screenshot der
Mitglied-Detailseite im Demo-Account).
**Auftrag:** Die beiden letzten offenen Masking-Lücken aus der bekannten Liste schließen
(Aktivitätslog, Beitrittsanträge) sowie eine UX-Vereinfachung: die WLAN-Diagnoseinfos
(SSID/IP/Passwort) sollen beim Öffnen der Mitglied-Detailseite automatisch inline erscheinen statt
erst nach Klick auf einen kleinen Link in einem alert()-Popup.
**Ergebnis:** Neue Funktionen `demoMaskAuditLog()` (Handelnde(r) maskiert, freier
`beschreibung`-Fließtext komplett durch Platzhalter ersetzt, da über 50 verschiedene
`logAudit()`-Vorlagen im Code nicht einzeln robust parsbar sind) und `demoMaskApplication(s)()`
(eigene Spaltennamen von `membership_applications`, Unterschriftsbilder komplett ausgeblendet) in
`functions.php`, angewendet auf `/admin/log`, `/admin/log/export` (zusätzlich per
`denyDemoFileDownload()` gesperrt), `/api/v1/admin/log`, `/portal/applications` und
`/portal/applications/:id`. `member_detail.php`: der "WLAN-Info anzeigen"-Button samt
`alert()`-Popup wurde durch ein automatisch beim Laden per AJAX befülltes Inline-Feld ersetzt --
die bestehende Sicherheitsvorkehrung (Passwort landet nicht im initialen Server-HTML, sondern kommt
weiterhin über den separaten authentifizierten Endpunkt) bleibt dabei erhalten, ebenso die
bestehende Demo-Maskierung an diesem Endpunkt. Neue Unit-Tests (130/130 grün) sowie Verifikation
gegen eine Scratch-DB. CLAUDE.md + Infrastruktur.md aktualisiert, Commit/Push/PR/Merge nach main.

## 2026-09-06 (79) — Claude Code — Claude Sonnet 5
**Prompt:** "Ich verstehe aber nicht, warum jetzt ein Admin einer EG zugewiesen werden muss [...]
aber ja, okay. Ein Fall, der auf jeden Fall noch gibt, ist: Mit dem Demo-Account darf man keine
Dateien von der Plattform runterladen. Das Problem ist zum Beispiel, was ich gerade gemerkt habe:
Was sowieso nicht passieren darf, ist, dass man eine Beitrittserklärung runterladen kann, weil in
der Beitrittserklärung die Unterschrift drinnen ist und auch wieder alle personenbezogenen Daten.
Deswegen das auf jeden Fall ausschalten und bitte auch generell den Download verbieten:
Bezugsvereinbarungen, die Vorlage oder Logos, Rechnungsvorlagen, die Rechnung, LaTeX-Dateien. Das
darf bitte alles nicht runtergeladen werden können. [...] Die Dateien dürfen nie, in gar keinem
Fall, irgendwie installiert oder heruntergeladen werden können. Ich würde da voll gegen das
Datenschutzrecht verstoßen. Postfach: Bitte auch bei Online-Beitragserklärungen die Namen
unkenntlich machen. [...] Die Zählernummer bitte unkenntlich machen, wenn eine Nachricht
reinkommt, dass ein neues ESP ihre Daten schickt und das ESP noch keinem Mitglied zugeordnet
worden ist [...] Nächstes Thema ist der Supportbereich. Bitte den Namen unbedingt löschen. Die
Nachrichten, das Ticketsystem selber mal durchzusehen, das kann man machen, aber Namen bitte
löschen. Auch als Testzwecken darf man den Demo-Account und die Einspeisung und als Verbraucher
Tickets erstellen, aber der Admin oder der Obmann darf den Namen nicht sehen, wenn nämlich andere
auch Support-Tickets schicken. Zum Schluss noch bitte die Einstellungen von einem Obmann zur
Energiegemeinschaft unkenntlich machen [...] die ZVR-Nummer darf bestehen bleiben, so wie auch der
Name, aber bitte personenbezogene Daten wie mein Name: nur die ersten drei Buchstaben, und die
E-Mail machen wir ganz unkenntlich, sowie die Gläubiger-ID und dem PIC [...] auch nur die ersten
paar Zeichen, Kontoinhaber, E-Mail-Adressen sowie Steuerkonfigurationen bitte ganz unkenntlich
machen. Auch nur die ersten 2, 3 Zeichen."
**Auftrag:** Nach einem weiteren Rundgang durch den Demo-Account (u.a. per Screenshot von
`/portal/files`) fünf neue Lücken schließen: Datei-Downloads jeder Art ausnahmslos sperren
(Beitrittserklärung/Verträge/Rechnungen/LaTeX-Vorlagen/Logos/SEPA-Export), Namen auf der
Dateien-Seite maskieren, Postfach-Benachrichtigungen (Online-Beitrittserklärung, unbekannte
Zählernummer) maskieren, Namen im Support-Ticketsystem maskieren, und die Obmann-EEG-
Einstellungen feldweise maskieren (ZVR/Name sichtbar, Rest je nach Feld voll- oder
teilmaskiert).
**Ergebnis:** Neue zentrale Helper `denyDemoFileDownload()`/`denyDemoApiFileDownload()` in
`index.php`, angewendet auf alle 20 Datei-/PDF-Download-Routen (Web + App-API) inkl.
SEPA-Sammellastschrift-Export -- Browsen in Datei-LISTEN bleibt erlaubt, nur der Transfer wird
geblockt. `AppApiAuth::requireAppAuth()` liefert jetzt immer `is_demo` im Kontext. Neue Funktionen
in `functions.php`: `demoMaskNotification(s)()` (Postfach-Freitext), `demoMaskCommunitySettings()`,
`demoMaskSettingsUser()`, `demoMaskTaxConfig()` (EEG-Einstellungen) -- alle unit-getestet
(`tests/functions_test.php`, 125/125 grün) und zusätzlich gegen eine Scratch-DB mit echten und
fiktiven Mitgliedern/Tickets/Postfach-Meldungen live verifiziert. `/portal/files`,
`/portal/files/:id`, `/portal/support`, `/portal/support/:id` maskieren jetzt Namen echter
Mitglieder; `/portal/settings` + `/admin/communities/:id` maskieren Kontakt-E-Mail/Kontoinhaber
(voll) sowie Gläubiger-ID/Marktpartner-ID/UID-Nummer (nur Anfang) -- "PIC" mangels eindeutigem
Feldnamen auf `marktpartner_id` gemappt, ggf. Rückmeldung nötig falls etwas anderes gemeint war.
CLAUDE.md + Infrastruktur.md aktualisiert, Commit/Push/PR/Merge nach main.

## 2026-09-06 (78) — Claude Code — Claude Sonnet 5
**Prompt:** "Es ist ein unerwarteter Fehler aufgetreten. Technische Details: DB::setCommunity():
Argument #1 ($communityId) must be of type string, null given, called in
/var/www/html/src/views/pages/manager_dashboard.php on line 4 [...] Ich hoffe aber, dass dieser
Admin und dieser Obmann dann für diesen Demo-Account wirklich keine Rechte haben. Sie sollen
alles sehen, aber die ganzen personenbezogenen Daten mit Sternchen und auch die ganzen
E-Mail-Einstellungen, Sachen wie die Graph API von Microsoft und weitere Sachen, all das sehen,
aber halt verpixelt oder mit Sternchen." Danach, mit drei Screenshots: "Was auch noch ist: Bei
den ESP steht noch immer 3 von 4, obwohl nur maximal 2 sind. [...] Ich sehe ganz genau: Beide
sind online."
**Auftrag:** Den Absturz beim ersten echten Login-Versuch als Demo-Admin beheben, eine zweite
übersehene Doppelzählungsstelle für ESP-Online-Status finden und fixen, und eine gründliche
Prüfung, ob im Admin-/Obmann-Bereich irgendwo ECHTE Zugangsdaten im Klartext sichtbar sind statt
maskiert.
**Ergebnis:** (1) Ursache des Absturzes: `assign_demo_member_roles.php` hatte platform_admin mit
`community_id=NULL` angelegt (für `Auth::isPlatformAdmin()` korrekt, aber `/portal/dashboard`
leitet jeden mit `Auth::isManager()`, auch platform_admin, auf `manager_dashboard.php` weiter,
das zwingend eine aktive Community braucht). Doppelt behoben: `/portal/dashboard` weicht bei
fehlender Community auf `/admin` aus (schützt auch echte platform_admin-Accounts vor demselben
Absturz), das Rollen-Skript repariert einen bestehenden kaputten Zustand jetzt automatisch beim
nächsten Lauf. Live an einer Scratch-DB mit exakt Patricks Fehlerzustand verifiziert. (2) Eine
zweite, von `communityLivePower()` unabhängige Zählstelle in `manager_dashboard.php`
("ESP online"-Kachel, "Registrierte Zählpunkte") hatte denselben Doppelzählungs-Bug wie beim
letzten Fix übersehen -- ergänzt um dieselbe `mirror_source_metering_point_id IS NULL`-Bedingung.
(3) Sicherheitsaudit ergab drei echte Klartext-Leaks (Read-only-Sperre verhindert nur Ändern,
nicht Ansehen): MQTT-Passwort + Geräte-Fernkonfigurationspasswort (`/admin/mail-settings`),
EDA-Portal-Passwort je EEG (`/admin/communities/:id`), Mitglied-Heim-WLAN-Passwort (GET-Endpunkt
`/portal/members/:id/metering-points/:mpid/wifi-info`, von der POST-only Sperre nicht erfasst).
Alle drei jetzt maskiert; Microsoft-Graph-Client-Secret war bereits sicher (nur Passwort-Feld mit
Platzhalter), Tenant-/Client-ID zusätzlich maskiert auf Patricks Wunsch. Alle 116 Tests weiterhin
grün.

---

## 2026-09-06 (77) — Claude Code — Claude Sonnet 5
**Prompt:** "Die live Echtzeit-Daten sollen zwar bei den Demo Acc angezeigt werden, aber es
dürfen die Daten nicht doppelt in dem Energiefluss angezeigt werden. Admin und Obmann gibt es
noch immer nicht als Demo Rolle. Das wäre noch sehr wichtig. Außerdem, warum haben die
Einspeicher nicht die Möglichkeit, ihre eingesepiste Leistungen n einem Diagramm einzusehen?
Und noch einmal, ICH BRAUCH BITTE DEN ADMIN und OBMANN DEMO ACCOUNT:"
**Auftrag:** Drei Punkte: (1) einen durch die Live-ESP-Spiegelung entstandenen Doppelzählungs-
Bug im Energiefluss beheben, (2) sicherstellen, dass platform_admin und manager im Demo-Login
zuverlässig funktionieren, (3) Einspeisern ein Diagramm für ihre eigene Einspeisung analog zum
bestehenden Verbrauchsdiagramm bereitstellen.
**Ergebnis:** (1) `communityLivePower()` sowie die öffentliche `/api/live/:slug`
(`live.stromfueralle.at`) summierten Leistung/Energie community-weit über ALLE Zählpunkte, ohne
gespiegelte Demo-Zählpunkte auszuschließen -- echte Messung und ihre Spiegelung zählten doppelt,
sichtbar für JEDEN Besucher der öffentlichen Live-Seite. Behoben durch
`mirror_source_metering_point_id IS NULL` in allen betroffenen Summen/Zählungen, live an einer
Scratch-DB verifiziert (500 W echt blieben 500 W, nicht 1000 W). (2) `scripts/
assign_demo_member_roles.php` erweitert: legt platform_admin/manager jetzt selbst an, falls sie
fehlen sollten (statt sich nur auf die manuelle Zuweisung zu verlassen), prüft vor jedem Insert
per SELECT (keine doppelten Rollenzeilen bei bereits abweichender community_id), und gibt am
Ende den tatsächlichen, aus der DB gelesenen Rollenstand aus -- dreifach an einer Scratch-DB
verifiziert (Patricks exakter Ausgangszustand, zweiter Lauf zur Idempotenz, kompletter
Neustart ohne jede Rolle). (3) Neue Seite `/portal/my/einspeisung` (App: `GET
/api/v1/production/interval`) für Mitglieder mit Einspeise-/Prosumer-Zählpunkten --
`memberIntervalDayData()` um einen `$energyDirection`-Parameter erweitert
(`'GENERATION'` statt `'CONSUMPTION'`), Card auf dem Mitglied-Dashboard ergänzt. Wichtige
Erkenntnis dabei: bei GENERATION ist `kwh_messung` die GESAMTE gemeinschaftliche Erzeugung
(community-weit, nicht mitgliedsspezifisch) -- die für das eigene Diagramm relevante Größe ist
`kwh_gemeinschaft` ("Erzeugung lt. Messung entsprechend dem Teilnahmefaktor"), das Diagramm
zeigt deshalb bewusst nur eine einzelne Fläche statt der gestapelten Verbrauch/Eigendeckung-
Darstellung. Alle 116 Tests weiterhin grün.

---

## 2026-09-06 (76) — Claude Code — Claude Sonnet 5
**Prompt:** [zwei Screenshots: Pre-Launch-Popup nach Demo-Login als "Verbraucher 1", danach die
"Nur Lesezugriff"-Seite] "Ein weiters Problem ist, dass wenn ich mich als Demo einlogge, ich mit
dem Willkommensbanner und der Info, dass laufen sich die Website ändert, begrüßt werde. Da aber
nur Read-Only ist, kann ich gar nicht auf den grünen gelesen Button klicken und komm dann auf
die Seite mit nur Lesezugriff"
**Auftrag:** Diagnose, warum ein Demo-Login beim allerersten Aufruf der Mitglied-Ansicht hinter
dem Pre-Launch-Hinweis-Popup feststeckt, und beheben.
**Ergebnis:** Ursache: das Popup zeigt einen "Gelesen, weiter zur Plattform"-Button, der einen
POST an `/portal/ack-prelaunch` auslöst -- von der globalen Read-only-Sperre für Demo-Logins
blockiert (landete auf der "Nur Lesezugriff"-Seite), während der dahinterliegende Seiteninhalt
bewusst per `pointer-events:none` gesperrt bleibt, bis das Popup geschlossen wird -- ein
klassischer Deadlock. Behoben auf zwei Ebenen: (1) `portal.php` zeigt das Popup für Demo-Logins
grundsätzlich gar nicht mehr an (der Hinweistext richtet sich an echte neue Mitglieder, für eine
Präsentation irrelevant), (2) `/portal/ack-prelaunch` zusätzlich als zweite, folgenlose Ausnahme
neben `/portal/switch-role` auf die Demo-Erlaubnisliste in `Router.php` gesetzt (reiner
Session-Flag, keine Datenänderung) als Absicherung, falls das Popup doch je erscheint. Alle 116
Tests weiterhin grün.

---

## 2026-09-06 (75) — Claude Code — Claude Sonnet 5
**Prompt:** "Ja und wie sieht es mit dem read only mit ***-verpixelten/unkennbar gemachten
Daten bei Obmann und Admin-Acc aus? Die rollen finde ich nicht. ich möchte das die verwaltung
als obmann und admi auch herzeigen können. das sind ja die 3. und 4. Rollen neben Einspeisen1
und Verbraucher 1."
**Auftrag:** Die ursprünglich schon zu Beginn geforderte, aber bisher nicht umgesetzte
PII-Maskierung für ECHTE Mitglieder-/Nutzerdaten in den Obmann-/Admin-Ansichten des Demo-Logins
nachrüsten ("Bei Plattform, Admin und Obmann auch keine personenbezogenen Daten [...] mit
Sternchen ausgegraut"), damit auch die Obmann- und Admin-Rolle (3./4. Rolle) gefahrlos
vorgeführt werden können.
**Ergebnis:** Neue, isoliert testbare Hilfsfunktionen in `functions.php`
(`demoMaskKeepStart`/`demoMaskKeepEnd`/`demoMaskFull` + `demoMaskMember(s)`/`demoMaskUser(s)`/
`demoMaskMeteringPoint(s)`) -- maskieren Vorname (4 Buchstaben + Punkte), Nachname/Firma/E-Mail/
Adresse/IBAN/Kontoinhaber/Zählpunktnummer/WLAN-Daten (komplett), Telefonnummer (nur letzte 4
Stellen sichtbar) und Geburtsdatum, setzen `photo_path` auf null (Default-Avatar statt echtem
Foto) -- wirken NIE auf die beiden fiktiven Demo-Mitglieder selbst (`is_demo=true`) und NIE
außerhalb einer aktiven Demo-Session. Eingebaut in die Kernseiten der Mitglieder-/
Nutzerverwaltung: `/portal/members`, `/portal/members/:id`, `/admin`, `/admin/users/:id`
(inkl. Mitglied-Identität-Auswahlfeld), `/admin/communities/:id`. 10 neue Unit-Tests, alle 116
Tests grün. Bewusst noch offen (Konversation + CLAUDE.md dokumentiert): Aktivitätslog,
Beitrittsanträge, Postfach, Support-Tickets, Rechnungsliste, Bearbeiten-Formulare -- diese
Seiten zeigen bei einer Vorführung des Demo-Accounts vorerst weiterhin echte Daten.

---

## 2026-09-06 (74) — Claude Code — Claude Sonnet 5
**Prompt:** [Screenshot von /admin/users/:id, "Demo Zugang"] "Was das problem ist, es gibt keine
DEMO Rollen. DIe Admin und Obamann Rolle, die ich ihm mla hinzugefügt habe, damit hat der Acc
volle rechte. bitte die demo rollen."
**Auftrag:** Diagnose, warum die 'member'-Rolle des Demo-Logins im Platform-Admin-Backoffice zu
keiner sichtbaren Mitglied-Identität führt (Screenshot zeigte "Aktuelle Rollen" mit member,
Mitglied="--"), und die beiden Mitglied-Identitäten ("Verbraucher 1"/"Einspeiser 1") korrekt mit
dem Demo-Login verknüpfen.
**Ergebnis:** Ursache: das Formularfeld "Mitglied-Identität" (`/admin/users/:id`, "Rolle
hinzufügen") erscheint erst NACH Auswahl von "member" in der Rolle-Auswahl -- leicht zu
übersehen, dadurch wurde die 'member'-Rolle ohne `member_id` gespeichert und führt für den
Demo-Login (der keinen `members`-Datensatz mit eigener `user_id` hat) ins Leere. Neues Skript
`scripts/assign_demo_member_roles.php`: räumt eine solche "nackte" member-Rolle auf und trägt
stattdessen zwei saubere, an `member_id` gebundene Rollen für "Verbraucher 1"/"Einspeiser 1"
ein -- sicher erneut ausführbar (ON CONFLICT DO NOTHING). Zusätzlich `admin_user.php` um einen
Hinweistext ergänzt, damit das Feld beim nächsten Mal nicht wieder übersehen wird. DELETE+INSERT-
Logik live an einer Scratch-DB mit exakt reproduziertem Ausgangszustand verifiziert (inkl.
zweitem Lauf zur Idempotenz-Prüfung). Alle 106 Tests weiterhin grün.

---

## 2026-09-06 (73) — Claude Code — Claude Sonnet 5
**Prompt:** "Wie sieht es mit den ESP Daten aus? werden die auch in Echtzeit bitte angezeigt"
-- danach auf Rückfrage (synthetische Simulation vs. Verzicht) explizit: "Nein, du sollst bitte
die Echtzeit-Werte zum Einspeisen von Daniel Ropper synchroniseren und die Echtzeit-Daten von
Stefanie Schwaiger für den Verbraucher verwenden. Aber bitte in Echtzeit."
**Auftrag:** Klären, ob/wie die Live-ESP-Leistungsanzeige für die beiden Demo-Mitglieder
funktioniert, und -- nach expliziter Ablehnung einer synthetischen Simulation -- eine echte
Echtzeit-Spiegelung der tatsächlichen ESP-Live-Messwerte der beiden Vorlage-Mitglieder auf die
fiktiven Identitäten bauen.
**Ergebnis:** Geprüft und bestätigt, dass fehlende ESP-Daten bei den Demo-Mitgliedern keine
echten Statistiken verfälschen (sowohl "ESP online: X von Y" als auch die Live-Leistungssummen
zählen nur Zählpunkte mit `esp_last_seen_at IS NOT NULL` -- ein Demo-Zählpunkt ohne Messwerte
bleibt dort unsichtbar statt "offline" zu zählen). `migrate_20260906.sql`: neue Spalte
`metering_points.mirror_source_metering_point_id` + Trigger `trg_mirror_esp_measurement` auf
`esp_measurements` -- spiegelt jede neue Live-Messung (mqtt-subscriber schreibt alle ~5s) sofort
1:1 auf den zugeordneten Demo-Zählpunkt, inkl. `esp_online`/`esp_last_seen_at`/
`meter_reachable`. Rekursionssicher durch Konstruktion (nichts zeigt auf den Demo-Zählpunkt als
Spiegelquelle, die zweite Trigger-Runde findet keine Treffer). `create_demo_members.php` trägt
die Zuordnung jetzt bei jedem Lauf ein. Live an einer Scratch-DB verifiziert: ein simulierter
echter Messwert erzeugt exakt eine gespiegelte Zeile (keine Endlosschleife) und setzt
`esp_online`/`esp_last_seen_at` am Demo-Zählpunkt korrekt. Alle 106 Tests weiterhin grün.

---

## 2026-09-05 (72) — Claude Code — Claude Sonnet 5
**Prompt:** "Meiner Mutter heißt Stefanie Schwaiger. Die Daten sollen immer gleich sein mit den
aktuell gülutigen Daten. Also Danie synchronisiert mit Einspoeiser 1 und Stefanie mit
Verbraucher 1 synchronisiert."
**Auftrag:** Namenskorrektur im Demo-Skript (Stefanie Schwaiger statt fälschlich "Stephanie
Schweiger", wodurch der erste Lauf sie nicht gefunden hatte) sowie Umbau von
`create_demo_members.php` von einem Einmal-Skript zu einer dauerhaften Synchronisation: die
Messdaten von "Verbraucher 1"/"Einspeiser 1" sollen jederzeit dem aktuellen Stand der echten
Vorlage-Mitglieder entsprechen, nicht nur einer einmaligen Momentaufnahme.
**Ergebnis:** `create_demo_members.php` umgebaut: der Mitglied-Datensatz (member_id,
Kundennummer) wird weiterhin nur beim allerersten Lauf angelegt und danach stabil
wiederverwendet (sonst würden Rollenzuweisungen im Admin-Backoffice ungültig), Zählpunkte werden
über ihre stabile fiktive Zählpunktnummer wiedererkannt statt dupliziert, und ALLE Messdaten
(`eda_measurements`, `eda_interval_data`) werden bei jedem Lauf komplett gelöscht und frisch aus
dem aktuellen Stand des jeweiligen Vorlage-Mitglieds neu kopiert. Sync-Verhalten (Erstlauf +
Datenänderung an der Quelle + Zweitlauf) live an einer Scratch-DB verifiziert. Als täglicher
Cron-Job dokumentiert (kurz nach dem EDA-Auto-Import-Cron), damit "immer synchron" auch ohne
manuelles Nachtriggern gilt. Alle 106 Tests weiterhin grün.

---

## 2026-09-05 (71) — Claude Code — Claude Sonnet 5
**Prompt:** "Was bitte gerne hätte ist, dass man in den 4 Rollen schon alle Funktionen und
Felder, sowie Button sieht. Es soll ein richtiger DEMO-Acc sein."
**Auftrag:** Sicherstellen, dass der Demo-Login (siehe Eintrag 70) in allen vier Rollen wie ein
vollwertiger, echter Account aussieht -- keine ausgeblendeten Funktionen/Felder/Buttons -- statt
einer sichtbar eingeschränkten Ansicht.
**Ergebnis:** Geprüft und bestätigt: `Auth::isDemo()` wird im Code nur an zwei Stellen
verwendet (zentrale POST-Sperre in `Router.php`/`AppApiAuth::requireAppAuth()` sowie ein reines
Hinweisbanner) -- es wurde nirgends ein Button, Feld oder Menüpunkt ausgeblendet, die
Read-only-Sperre greift ausschließlich beim tatsächlichen Absenden eines Formulars. Um die
Detailseiten der beiden fiktiven Mitglieder vollständiger wirken zu lassen,
`scripts/create_demo_members.php` erweitert: befüllt jetzt zusätzlich Kundennummer (dynamisch
per MAX+1, plattformweit eindeutig), IBAN/BIC/Kontoinhaber/Konto-Adresse/Mandatsreferenz (klar
erkennbare Platzhalter-IBAN, unbedenklich da `is_demo`-Mitglieder nie eine Rechnung bekommen),
Stromlieferant und alle sechs Beitritts-Zustimmungen. Bewusst weiterhin nicht vorbelegt: der
Vertragsstatus (bliebe ohne echt erzeugte PDF-Datei ein kaputter Download-Link) -- dafür
CLAUDE.md um den Hinweis ergänzt, bei Bedarf einen Beispielvertrag einmalig über den eigenen
echten Obmann-Account zu erzeugen, den der Demo-Login danach nur ansieht. Insert-Logik erneut
live an einer Scratch-DB verifiziert (Constraint-Konformität, korrekte Platzhalter-Reihenfolge),
alle 106 Tests weiterhin grün.

---

## 2026-09-05 (70) — Claude Code — Claude Sonnet 5
**Prompt:** "Ich hätte noch eine weitere Aufgabe für dich, bitte, für Testzwecke und zur
Veranschaulichung für andere Accounts. Zum Beispiel möchte ich gerne einen Account für meinen
Klassenvorstand geben, der auch mein Betreuer ist, um zu sehen, wie weit wir mit unserer
Diplomate sind [...] Außerdem wäre es auch interessant, einen Benutzer [...] für die Diplomate
selbst, damit man bei der Präsentation auch ein Mitglied simulieren kann [...] Vielleicht am
besten einfach einen Account mit allen drei Funktionen: Admin, Obmann und Mitglied, bei dem man
unter den drei Rollen wechseln kann. [...] Vielleicht machen wir auch zwei Mitglieder: einen
Einspeiser und einen Produzierer. Am besten nimmst du da einfach Stephanie Schweiger, das ist
meine Mama, und Daniel Ropper, meinen Vater. Was wichtig ist: Verwende andere Namen [...] Bei
Plattform, Admin und Obmann auch keine personenbezogenen Daten. [...] Geburtsdatum und
Telefonnummer müssen auch [...] die letzten vier Stellen von der Telefonnummer sichtbar sein.
Die anderen müssen auch mit Sternchen ausgegraut sein [...] damit wir vom Datenschutz her nicht
verstoßen." Auf Nachfrage präzisiert: "Ich möchte schon bitte gerne einen einzigen Login haben
[...] es sollen bitte schon für einen Login alle 4 Rollen sein [...] Die ganzen Accounts dürfen
nur Read-only Accounts sein." Auf die Rückfrage, ob Verbraucher 1/Einspeiser 1 eine gemeinsame
oder zwei getrennte Mitglied-Identitäten sein sollen: "Zwei getrennte Rollen: Verbraucher 1 UND
Einspeiser 1 separat."
**Auftrag:** Einen einzigen Demo-Login für Präsentation und Diplomarbeit-Review (u.a. für den
Klassenvorstand) einrichten, umschaltbar zwischen vier Rollen -- Plattform-Admin, Obmann und
zwei unabhängig wählbare, komplett fiktive Mitglied-Identitäten ("Verbraucher 1"/"Einspeiser 1")
in derselben EEG, deren Verbrauchsdaten strukturell den echten Mitgliedern Stephanie
Schweiger/Daniel Ropper nachempfunden sind. Anforderungen: plattformweit und rollenübergreifend
schreibgeschützt (reine Ansicht), keine personenbezogenen Echtdaten sichtbar.
**Ergebnis:** `user_roles` um `member_id` erweitert (neue partielle Unique-Indizes statt der
bisherigen `UNIQUE(community_id, user_id, role)`), wodurch ein Login erstmals zwei unabhängige
'member'-Rollen in derselben EEG halten kann -- live an einer Scratch-DB getestet
(`migrate_20260905.sql`). `Auth.php`/`currentMemberFull()`/`resolveAppMemberships()` und alle
weiteren Mitglied-Lookup-Stellen (Web + App-API) auf die neue `member_id`-Disambiguierung
umgestellt, RLS-sicher (members-Lookups einzeln je Community, nicht gejoint). Demo-Logins sind
über `users.is_demo` zentral in `Router.php` (Web) bzw. `AppApiAuth::requireAppAuth()` (App) für
JEDEN POST-Request gesperrt, unabhängig von der aktiven Rolle -- Ausnahme nur der Rollenwechsel
selbst. `members.is_demo` schließt die fiktiven Identitäten von `Billing.php` und der
Mitgliederstatistik aus. Platform-Admin-Oberfläche (`/admin/users/:id`) um eine
Mitglied-Identität-Auswahl beim Zuweisen einer 'member'-Rolle erweitert. Neue Skripte
`scripts/create_demo_login.sh` (fragt E-Mail/Passwort interaktiv ab, legt den Login OHNE Rollen
an) und `scripts/create_demo_members.php` (kopiert Zählpunkte + komplette EDA-Messreihen von
Stephanie Schweiger/Daniel Ropper auf zwei neue, komplett fiktive Mitglied-Datensätze mit
erfundener Adresse/Telefonnummer/Geburtsdatum und garantiert nicht mit echten EDA-Importen
kollidierender Zählpunktnummer). Alle 106 bestehenden Tests weiterhin grün, `php -l` auf allen
geänderten Dateien sauber. Offen/bewusst zurückgestellt: individuelles Ausblenden einzelner
Schaltflächen für Demo-Logins (die globale Schreibsperre verhindert bereits jede tatsächliche
Änderung; stattdessen ein Hinweisbanner + eine freundliche Fehlerseite statt eines rohen 403).
Ein separater Klassenvorstand-Account entfällt -- Patrick nutzt für beide Zwecke denselben
Demo-Login.

---

## 2026-09-04 (69) — Claude Code — Claude Sonnet 5
**Prompt:** "Ich hätte bitte gerne noch eine Zusatzfunktion auf unserer Webseite. [...] vielleicht
kannst du mir ja für die Mitglieder, die dabei sind, für diesen Zeitraum die Daten einlesen und
den Mitgliedern als Diagramm in der App oder auf der Webseite darstellen lassen, damit sie ein
bisschen sehen, wie viel sie zu sehen ist: wie viel sie viertelstündlich verbrauchen und wie viel
davon energiegemeinschaftlich genutzt wird. [...] Ich möchte gerne auch eine Funktion hinein,
dass ich [sehe], ab welchem Datum es noch keine Werte gibt, ab welchem Datum gehe ich die Daten
exportieren muss." Anschließend eine echte EDA-Exportdatei (Viertelstundenwerte,
RC108175_20260701T00_0020260731T23_45.xlsx) sowie den Vertrag mit KNG zur Prüfung übergeben,
und präzisiert: "wenn ich jetzt den juli importier und dann von 01.08. bis 21.08. ich sehe, dass
ab 22.08. die Daten fehlen [...] möchte immer alle paar Tage die Daten hochladen".
**Auftrag:** Viertelstunden-Verbrauchsdiagramm für Mitglieder (Verbrauch vs. gemeinschaftliche
Eigendeckung) aus einem zweiten, bisher nicht genutzten EDA-Export-Typ einlesen, dem Obmann eine
Lücken-Anzeige zeigen ("Daten vorhanden bis ..., X Tage fehlen"), sowie den KNG-Vertrag auf die
Zulässigkeit der Netzregion-Änderung und eine Verständigungspflicht prüfen.
**Ergebnis:** `database/migrate_20260904.sql` (eigene Tabelle `eda_interval_data`, getrennt von
`eda_measurements` wegen sonst doppelt gezählter Abrechnungssummen), `eda-parser/
parser_interval.py` (Format anhand der echten Datei verifiziert, dabei einen
Performance-Bug gefunden und behoben: `ws.max_row` in einer Schleifenbedingung wiederholt
abgefragt verlangsamte den Import von <1s auf >90s). Neue Upload-Karte samt Lücken-Anzeige unter
Obmann → "EDA-Daten importieren", Mitglied-Diagramm unter `/portal/my/verbrauch` (Web,
Inline-SVG) und `GET /api/v1/consumption/interval` (App, Bau-Anweisung in `app.md` §12). PDF-
Vertragsprüfung (8 Seiten, per Bild gelesen, keine Textebene): KNG darf die Netzkonfiguration
vertraglich ohne EEG-Zustimmung ändern, bestehende Mitglieder sind ausdrücklich davor geschützt;
eine Pflicht zur Vorab-Information der EEG existiert im Vertrag dagegen nicht (nur umgekehrt,
EEG→KNG) -- als Chat-Antwort mitgeteilt, keine Doku-Änderung ausgelöst. Live gegen PostgreSQL
und die echte Exportdatei getestet (23.040 Datensätze, Re-Import ohne Duplikate). `php -l`
sauber, 106/106 Tests grün. PR #117, sofort gemergt.

---

## 2026-09-03 (68) — Claude Code — Claude Sonnet 5
**Prompt:** "Bitte erwähne auch so, dass Kärnten, also jetzt haben wir vor ein paar Tagen
irgendwie das mit den Schaltanlagen abgeändert [...] Ich weiß das später auch mal, und in der
Diplomarbeit dann unter 'unerwarteten Problemen' oder 'Problem' ist es nicht wirklich eins. Es
war einfach eine große Umstellung, und ich wurde auch nicht von Kärnten informiert oder
irgendetwas. Deswegen war es schon ein bisschen eigenartig."
**Auftrag:** `obsidian/Problemstellungen.md` (PR #113) ergänzen: Kärnten Netz hat vor der
Schaltanlagen-Umstellung nicht vorab informiert (Patrick hat es selbst bemerkt), sowie
Einordnung für die Diplomarbeit festhalten (kein klassisches Problem/Fehler, sondern externe
organisatorische Umstellung).
**Ergebnis:** Zwei neue Abschnitte in `obsidian/Problemstellungen.md` ("Keine
Vorabinformation durch Kärnten Netz" und ein Diplomarbeit-Hinweis direkt am Anfang des
Eintrags), Überschrift des Eintrags entsprechend ergänzt. Reine Dokumentationsänderung. PR
#115, sofort gemergt.

---

## 2026-09-03 (67) — Claude Code — Claude Sonnet 5
**Prompt:** "Ich wollte nur mit dir erst gerne abklären bzw. schreib mir das bitte auch sehr
detailliert in eine Datei, die ich dann bitte auch auf GitHub pushe, damit ich sie dann mit
Git-Pool wieder runterholen kann und bei mir in Obsidian synchronisieren kann [...] Auf jeden
Fall geht es darum, dass Kärnten jetzt auf einmal die Schaltanlagen irgendwie geändert haben.
[...] 23 R1 ist jetzt auf einmal der nordöstliche Teil von Feldkirchen. [...] Was jetzt
wirklich mit 'Süd-West' gemeint ist, weiß ich nicht [...] Ich glaube, das lassen wir jetzt
einfach mal so. [...] Bitte dokumentier mir das, und dann geht's weiter mit der Webseite und
ein paar Ideen von mir!"
**Auftrag:** Die von Kärnten Netz neu gezogenen Regionalgrenzen (Regionale ID 23R1 bezeichnet
jetzt den nordöstlichen statt südwestlichen Teil von Feldkirchen) und Patricks Entscheidung,
Vereinsname/Bankkonto trotz der geografischen Verschiebung NICHT umzubenennen, als
Hintergrund-Dokumentation festhalten -- ausdrücklich ohne Code-Änderung, nur zur
Nachvollziehbarkeit für später.
**Ergebnis:** Neue Notiz `obsidian/Problemstellungen.md` (neue Kategorie für organisatorische/
rechtliche Problemstellungen ohne unmittelbaren Code-Bezug, ergänzend zu `Infrastruktur.md`
und diesem Sitzungslog), `obsidian/README.md` um Verweis ergänzt. Statuten und öffentliche
Website geprüft und für von der Änderung unberührt befunden. PR #113, sofort gemergt.

---

## 2026-09-03 (66) — Claude Code — Claude Sonnet 5
**Prompt:** "Bezüglich dem Problem mit dem Zählpunktnummer mit den Leerzeichen: Ich habe
gerade jetzt gesehen, dass in dem Formular, in dem man die ganzen Daten überträgt, jetzt die
letzten drei Stellen frei sind. Ich habe sie ja händisch jetzt schon nachgetragen, aber
kannst du da irgendetwas hinzufügen, damit ich die Beitragserklärungen mit den aktuellen
Daten nochmal neu generieren lasse? Denn so stimmt die Beitragserklärung ja leider nicht."
**Auftrag:** Möglichkeit schaffen, die bei einem älteren (vor dem Leerzeichen-Fix
eingereichten) Beitrittsantrag bereits abgeschnitten gespeicherte Zählpunktnummer nachträglich
zu korrigieren, damit das daraus erzeugte Beitrittserklärung-PDF wieder stimmt.
**Ergebnis:** Neue Route `POST /portal/applications/:id/zaehlpunkt` (mit
validateZaehlpunkt()/normalizeZaehlpunkt()) plus Korrektur-Formular auf der
Antragsdetailseite (`application_detail.php`); Link dorthin von der Mitglied-Detailseite
ergänzt (vorher nur reiner PDF-Download verlinkt). Das PDF wird ohnehin bei jedem Ausdruck
neu aus den Daten gerendert (kein Caching) -- die Korrektur allein reicht deshalb bereits.
`php -l` sauber, 106/106 Tests weiterhin grün. PR #111, sofort gemergt.

---

## 2026-09-03 (65) — Claude Code — Claude Sonnet 5
**Prompt:** "Was ich noch vergessen habe, ist: Bei dem Kontoinhaber bei der Bankverbindung
bitte dieses Feld nicht als Pflichtfeld markieren, weil der Kontoinhaber dann nur eingetragen
wird, wenn er vom Namen der Kundendaten oder Mitgliedsdaten abweicht. [...] bitte diesen nicht
wieder als Pflichtfeld machen, sondern nur zum Ausfüllen, wenn es eine Abweichung vom Kontakt
oder von Mitgliedsdaten gibt."
**Auftrag:** Nachbesserung zu PR #105 -- Kontoinhaber:in bei der Online-Beitrittserklärung
wieder optional machen (nur die IBAN selbst bleibt Pflicht), da das Feld laut Patrick nur bei
Abweichung vom Mitgliedsnamen ausgefüllt werden soll.
**Ergebnis:** `beitreten_formular.php` (required-Attribut/Stern entfernt, Label/Placeholder
klargestellt) und `webapp/public/index.php` (serverseitige Pflichtprüfung entfernt)
angepasst. `php -l` sauber, 106/106 Tests weiterhin grün. PR #109, sofort gemergt.

---

## 2026-09-03 (64) — Claude Code — Claude Sonnet 5
**Prompt:** "Die zweite Sache wäre: Ich habe gerade wieder ein Mitglied, das die 33-stellige
Zählpunktnummer aus der Kellag-Webseite rauskopiert hat. Nur sind in dieser 33-stelligen
Nummer Leerzeichen vorhanden, die meine Plattform beim Reinkopieren als Zeichen identifiziert
hat. [...] Bitte überprüfe auch auf Buchstaben und Zahlen, ob davon 33 Zeichen vorhanden sind,
und nimm bitte Leerzeichen oder zähle Leerzeichen bitte nicht. Wenn Leerzeichen vorhanden sind,
bitte fordere den Kunden bitte auf, die Leerzeichen rauszulöschen, und mach erst etwas grün,
wenn wirklich 33 Zeichen in Buchstaben und Zahlen eingetippt worden sind."
**Auftrag:** Bug beheben, bei dem eine aus einem Netzbetreiber-Portal (Kelag) mit Leerzeichen
kopierte 33-stellige Zählpunktnummer in der Online-Beitrittserklärung durch das dortige
`maxlength="33"` am Ende abgeschnitten wurde, weil Leerzeichen als Zeichen mitgezählt wurden.
**Ergebnis:** `validateZaehlpunkt()`/neue `normalizeZaehlpunkt()` (`webapp/src/functions.php`)
entfernen Leerzeichen jetzt vor der Längenprüfung, analog zu `validateIban()`.
`beitreten_formular.php`: `maxlength` entfernt, Live-Rückmeldung (grün erst bei 33 echten
Zeichen, Aufforderung zum Entfernen erkannter Leerzeichen). `webapp/public/index.php`:
serverseitige Validierung + Speicherung des normalisierten Werts. `php -l` sauber, 106/106
Tests grün (3 neu). PR #107, sofort gemergt.

---

## 2026-09-03 (63) — Claude Code — Claude Sonnet 5
**Prompt:** "ich habe eine neue Anweisung für Dich bitte. Ein Mitglied ist gerade
draufgekommen, dass bei der Online-Beitrittserklärung die Bankverbindung kein Pflichtfeld ist.
Bitte mach diese zu einem Pflichtfeld, damit ich an die Kunden die Einspeisen überweisen kann
und die Bezieher auch ihre Bankverbindungen angeben, damit ich bei diesem Per se-Bereinzug das
Geld einziehen kann."
**Auftrag:** IBAN + Kontoinhaber:in bei der öffentlichen Online-Beitrittserklärung
(`/:communityid/beitreten/formular`) von optional auf verpflichtend umstellen, damit für jedes
neue Mitglied von Anfang an sowohl Einspeisevergütungen ausgezahlt als auch Rechnungsbeträge
per SEPA-Lastschrift eingezogen werden können.
**Ergebnis:** `webapp/src/views/pages/beitreten_formular.php` (required-Attribute,
SEPA-Lastschriftmandat-Feld immer sichtbar statt nur bei getippter IBAN) und
`webapp/public/index.php` (serverseitige Pflichtprüfung mit eigener Fehlermeldung statt der
generischen) angepasst. `php -l` sauber, 103/103 Tests weiterhin grün. PR #105, sofort
gemergt.

---

## 2026-09-03 (62) — Claude Code — Claude Sonnet 5
**Prompt:** "ja leg mit den Push-Benachrichtigungen los. Zur App, Die Dateien haben keine
Endung. Ich kann sie nicht öffnen, wenn ich sie über die App heruntergeladen hab. Bitte ein
.pdf anhängen. Zur Live-Anzeige, die Striche an den Verbindungen bewegen sich hin und wieder,
oder erst ab einer bestimmten Leistung. Bitte immer Bewegen, sobald über 0 /beim Netz unter 0
ist. Wo kann man in der App jetzt zum Admin wechseln? Bitte in die Anweisung dann auch gleich
die Benachrichtigungen hinzufügen."
**Auftrag:** Push-Benachrichtigungen für die App bauen (Postfach an Obmann/Admin, neue
Rechnung an Mitglied, Einspeisung-Schwelle mit Hysterese an Mitglied); Bugfix fehlende
Dateiendung bei App-Downloads; Korrektur der Energiefluss-Animation (immer bewegen sobald
Wert > 0, bei Netz < 0) sowie Klärung, wo in der App zur Admin-Rolle gewechselt wird -- beides
als Xcode-Anweisung dokumentieren.
**Ergebnis:** `database/migrate_20260903.sql` (APNs-Konfig, Push-Token, Mitglied-Einstellungen,
Warteschlange, 3 DB-Trigger, live gegen PostgreSQL getestet inkl. vollständiger
Hysterese-Sequenz), `webapp/src/Push.php` (ES256-JWT via `openssl_sign()`, APNs über
HTTP/2-cURL, Queue-Drain), `scripts/send_pending_push.php` + Cron-Doku, neue Endpunkte
`/api/v1/push/{register,unregister}`, `/api/v1/notifications/settings`,
`/api/v1/admin/settings/apns[/test]`. Dabei nebenbei entdeckt und behoben: `invoices.sent_at`
wurde bisher NIRGENDS gesetzt (auch das "letzte Rechnung"-Dashboard-Widget war dadurch immer
leer) -- `Billing::finalize()` setzt es jetzt beim Freigeben eines Abrechnungslaufs.
`filenameWithExtension()` (nach `functions.php` verschoben, jetzt testbar) behebt die fehlende
Dateiendung bei Downloads. `webapp/Dockerfile` bekommt das PHP-`curl`-Modul (für APNs' HTTP/2
nötig, PHPs Stream-Wrapper kann das nicht). 7 neue Tests (103 statt 96), `php -l` sauber.
`docs/APP_API.md`, `app/ios-app/app.md` (§10.3 fertiggestellt + neue Runde 4 mit
Energiefluss-/Downloads-/Rollen-Klärung), `APP_PARITY_BACKLOG.md`, `CLAUDE.md` +
`obsidian/Infrastruktur.md` aktualisiert.

---

## 2026-08-19 (61) — Claude Code — Claude Sonnet 5
**Prompt:** "Bitte mach das mit dem Plattform-Admin, oder ab jetzt bitte nur Admin. Alle
Einstellungen auch in die App übertragen. Bei Fehlern, sag XCode bitte auch, wo er in dem
File genau schauen soll. Was noch intressant wäre ist, dass die App Benachrichtigugnen
sendet [...] für Obmann und Admin eine Benachrichtigung, dass im Postfach was neues gekommen
ist und für die Mitglieder, z.B. neue Rechnung verfügbar oder für jeden selbst einstellbar
[...] wenn zuviel Eingespeist wird [...] Jeder soll seine eigene Schwelle festlegen dürfen.
Bitte auch auf Hysterese Zeit achten [...] ja leg bitte los."
**Auftrag:** Die Platform-Admin-Funktionen als dritte App-Rolle ("Admin") implementieren,
mit voller Feature-Parität zu den Web-Einstellungen; Anforderungen für eine künftige
Push-Benachrichtigungs-Runde (Postfach/Rechnung/Einspeisung-Schwelle mit Hysterese) im
Backlog festhalten statt sofort mitzubauen.
**Ergebnis:** Neuer, community-unabhängiger App-Rollenwert `admin` (`migrate_20260902.sql`,
live gegen PostgreSQL getestet). Admin-Endpunkte: EEG-Verwaltung plattformweit
(Liste/Detail/Anlegen/Bearbeiten/Löschen), Nutzer & Rollen, Aktivitätslog, gesammelte
Plattform-Einstellungen (Mail/Graph, Mail-Vorlagen, MQTT, Testmodus/ESP-Schwelle),
Backup-Übersicht. Dabei einen echten RLS-Bug in `/admin/communities/:id` (Web-Portal)
gefunden und behoben (Mitgliederliste zeigte seit dem RLS-Fix leer). `app.md`/
`docs/APP_API.md`/`APP_PARITY_BACKLOG.md` aktualisiert, inkl. detaillierter
Anforderungsliste für Push-Benachrichtigungen (eigene künftige Runde) und der Anweisung an
den Xcode-Agenten, bei Fehlern künftig immer Datei+Zeile zu nennen. PR #101 gemergt, alle 96
Tests grün.

---

## 2026-08-19 (60) — Claude Code — Claude Sonnet 5
**Prompt:** "[Xcode-Fehlerausgabe:] DecodingError.keyNotFound: Key 'id' not found [...] bei
ManagerMemberDetail [...] Ich möchte bitte den Adminaccount auch alle Einstellungen von Admin
[...] Alles soll eins zu eins sein [...] Bitte baue mir alle Funktionen von der Plattform in
die App ein. Wenn ich [...] Ideen habe [...] speichere dies bitte in einem eigenen Dokument
[...] außerdem funktioniert auf Website das noch immer nicht verlässlich. Das mit dem Online-
und offline [...] es wechselt sich echt zwischen Mama und Papa ab. es muss funktionieren."
**Auftrag:** Eine von Xcode gemeldete Decoding-Fehlermeldung einordnen, die Entscheidung zur
vollständigen Feature-Parität (inkl. Platform-Admin) aufnehmen und dafür ein Backlog-Dokument
anlegen, sowie den weiterhin bestehenden ESP-Online/Offline-Flacker auf der Website endgültig
beheben.
**Ergebnis:** `DecodingError` als Swift-seitiges Model-Problem identifiziert (kein
Backend-Bug, `id` liegt korrekt unter `member.id`) und in `app.md` erklärt. ESP-Online-Bug
dieses Mal strukturell statt nur durch weitere Datenpflege behoben: `hat_esp_fehler`
(Mitgliederliste) und `$espEffectivelyOnline` (`member_detail.php`) verlassen sich nur noch
auf die Aktualität von `esp_last_seen_at`, die `esp_online`-Bedingung wurde ganz entfernt, da
sie durch stehen gebliebene LWT-Werte weiterhin sporadisch falsch lag. Zusätzlich `appDate()`
auf durchgehend UTC normalisiert (vorher je nach Kalenderdatum/Sommerzeit wechselnder Offset).
Neue Datei `app/ios-app/APP_PARITY_BACKLOG.md` als laufende Aufgabenliste für die
schrittweise volle Web-zu-App-Parität (Platform-Admin, Abrechnung, EDA-Import,
EEG-Einstellungen, Beitrittsanträge, Postfach, u. a.) -- `app.md` entsprechend um die neue
Zielsetzung ergänzt. PR #99 gemergt, alle 95 Tests grün.

---

## 2026-08-19 (59) — Claude Code — Claude Sonnet 5
**Prompt:** "Ich schicke dir die Anweisungen, die ich jetzt noch in Xcode geben werde [...]
Bitte sag es genauer [...] Gib mir dein Prompt nochmal raus für Xcode [...] Bitte die aktuelle
Leistung automatisch aktualisieren [...] Das mit dem dunkel blau als Hintergrund [...] die
Fenster mit dem dunkel Grau passen hier nicht zusammen [...] Was noch cool wäre ist den
Energiefluss als Grafik anzuzeigen [...] Bei einzelnen Mitglieder kommt noch 'Unerwartete
Antwort vom Server' [...] Bei Mitgliedern hinzufügen, fehlen paar Daten zum eingeben [...] Die
Rolle Plattform Admin [...] gibt es noch garnicht [...] Wo kann man dann Rollen wechseln? Als
Obmann hab ich nur die Menüs Mitglieder und Konto."
**Auftrag:** Die erste Xcode-Bauversion der App gegenprüfen, einen gemeldeten "Unerwartete
Antwort vom Server"-Fehler untersuchen/beheben, fehlende Formularfelder und fehlende
Rollenwechsel-Möglichkeit klären/nachrüsten, und daraus eine aktualisierte, vollständige
Anleitung (`app.md`) für die nächste Xcode-Runde erstellen.
**Ergebnis:** Root Cause gefunden: PostgreSQL/PDO liefert Zeitstempel im eigenen Format
zurück (kein striktes ISO-8601), was Swifts `JSONDecoder` bei jedem Datumsfeld zum Absturz
brachte -- neue `appDate()`-Helferfunktion konvertiert jetzt alle `/api/v1/*`-Zeitstempel
korrekt. Zwei neue Endpunkte ergänzt: `GET /api/v1/current-power` (Live-Poll ohne vollen
Reload) und `GET /api/v1/roles` + `POST /api/v1/switch-role` (Rollenwechsel ohne
Neuanmeldung -- beantwortet die "wo wechselt man die Rolle"-Frage). `app.md` um Abschnitt 9
("Runde 2") erweitert: Bugfix-Erklärung, vollständige Mitglied-Anlegen-Feldliste, Klarstellung
dass Platform-Admin bewusst keine Zusatzrechte in der App hat, Konzept für eine animierte
Energiefluss-Grafik (Netz/Einspeiser/Bezieher/EEG) rein auf Basis bereits vorhandener Daten.
PR #97 gemergt, alle 95 Tests grün. Fertige Runde-2-Prompt-Zusammenfassung im Chat an Patrick
übergeben, zum Einfügen in Xcode.

---

## 2026-08-18 (58) — Claude Code — Claude Sonnet 5
**Prompt:** "[4 Screenshots: Mitglied-Detailseiten von Daniel/Stefanie zeigen 'Online'/
'Erreichbar', Mitgliederliste zeigt für Stefanie 'Fehler'] alles nach einem reload. In der
Mitgliederliste wechselt es sich immer ab und in den einzelnen Mitgliedern ist es online."
**Auftrag:** Nachbessern -- der ESP-Online-Fix von eben (Log-Eintrag #56) reichte nicht, die
Mitgliederliste zeigte weiterhin "Fehler" für ein Mitglied, dessen eigene Detailseite im
selben Moment durchgehend "Online"/"Erreichbar" zeigte.
**Ergebnis:** Ursache: `meter_reachable` (P1-Zähler-Erreichbarkeit, separates Feld von
`esp_online`) wurde bewusst NICHT vom vorigen Fix mitgezogen und blieb weiterhin
ausschließlich vom selben flatterhaften Status-Heartbeat abhängig -- das "Fehler"-Badge der
Mitgliederliste hängt an BEIDEN Feldern. Firmware-Analyse (`p1-smart-meter.ino`) bestätigt:
die Live-Publish-Funktion sendet ausschließlich als Folge eines gerade erfolgreich
decodierten P1-Telegramms, eine Live-Nachricht beweist also zwangsläufig auch "Zähler gerade
erreichbar". `insert_measurement()` in `mqtt-subscriber/main.py` zieht jetzt zusätzlich
`meter_reachable`/`meter_last_seen_at` mit. `docs/ESP_IDEEN.md` ergänzt, PR #95 gemergt, alle
95 Tests grün.

---

## 2026-08-18 (57) — Claude Code — Claude Sonnet 5
**Prompt:** "Was mir bitte noch wichtig wäre, ist, dass auch der Obmann und der
Plattform-Admin API-Keys in den Einstellungen erstellen können. [...] Oder wird da nur ein
anderer Key erstellt?" / Rückfrage beantwortet: "Live-Daten der GANZEN Community" (nicht nur
falls der Obmann selbst auch Mitglied ist).
**Auftrag:** Klären, wie sich der neue App-Login (E-Mail/Passwort) zu den bestehenden
Smart-Home-API-Keys verhält, und anschließend Obmann/Platform-Admin die Möglichkeit geben,
eigene API-Keys für die Live-Daten der GESAMTEN Community anzulegen (nicht nur eines
einzelnen Mitglieds).
**Ergebnis:** `member_api_keys.member_id` nullable gemacht (`migrate_20260901.sql`,
live gegen PostgreSQL getestet). Neue Sektion "Live-Daten-API (ganze Community)" unter
`/portal/settings` (Erstellen/Anzeigen/Widerrufen, sichtbar für jeden Obmann/Platform-Admin
der EEG). `GET /api/v1/live`/`GET /api/v1/me` liefern bei einem solchen Key jetzt die
Gesamt-Community-Leistung statt Daten eines einzelnen Mitglieds (neues `scope`-Feld,
`community`-Objekt), bestehende Mitglied-Keys bleiben unverändert kompatibel. PR #93
gemergt, alle 95 Tests grün.

---

## 2026-08-18 (56) — Claude Code — Claude Sonnet 5
**Prompt:** "Wenn ich 'WLAN-Info anzeigen' klicke, kommt 'Zählpunkt nicht gefunden' [...] Was
auf jeden Fall noch ist: [...] meine ersten zwei Testkunden mit dem Prototypen der
ESP-Firmware. Das Problem ist, dass er die ganze Zeit zwischen Offline und Online wechselt,
obwohl beide angesteckt sind. [...] die Live-Anzeige [...] Daten [...] die sich auch alle fünf
Sekunden ändern, aber das mit dem Heartbeat, ob der ESP noch online ist und am Leben ist,
funktioniert nicht so ganz. [...] Wenn ich die Seite neu lade, dann switcht der eine auf grün
und der andere auf rot oder andersrum."
**Auftrag:** Gemeldeten Online-/Offline-Flacker-Bug bei den ersten beiden Testgeräten
untersuchen und beheben, obwohl die Live-Anzeige durchgehend aktuelle Werte zeigt.
**Ergebnis:** Ursache gefunden: `esp_online`/`esp_last_seen_at` wurden bisher ausschließlich
vom seltenen Status-Heartbeat gepflegt, dessen MQTT-Last-Will-Testament bei jedem kurzen WLAN-/
MQTT-Wackler ein retained "offline" publiziert -- unabhängig von den durchgehend ankommenden
Live-Messungen. `insert_measurement()` in `mqtt-subscriber/main.py` zieht diese beiden Felder
jetzt in derselben Transaktion mit, sodass ein Heartbeat-Ausrutscher durch die nächste
Live-Messung (alle 5s) praktisch sofort ausgeheilt wird. `docs/ESP_IDEEN.md` ergänzt, PR #91
gemergt. Noch offen: Patrick nach dem nächsten Deploy bitten, die beiden Testgeräte über
mehrere Reloads zu beobachten.

---

## 2026-08-18 (55) — Claude Code — Claude Sonnet 5
**Prompt:** "also was muss ich jetzt machen? hab noch nichts gemacht von dieser Nachricht. Wenn
ich "WLAN-Info anzeigen" klicke, kommt "Zählpunkt nicht gefunden""
**Auftrag:** Deploy-Frage zur zuvor gemergten App-API-Erweiterung beantworten, außerdem einen
gemeldeten Bug im Web-Portal untersuchen und beheben (WLAN-Diagnose-Button auf der
Mitglied-Detailseite liefert fälschlich "Zählpunkt nicht gefunden").
**Ergebnis:** Ursache gefunden: `metering_points` hat seit `migrate_20260822.sql` Row-Level
Security, aber `GET /portal/members/:id/metering-points/:mpid/wifi-info` sowie die
Geschwister-Routen `.../metering-points/:mpid/edit` und `.../delete` querten die Tabelle
weiterhin direkt ohne vorher `DB::setCommunity()` zu setzen -- klassischer Fall derselben
RLS-Regressionsklasse wie in Commit 6588243, hier aber offenbar übersehen. Alle drei Routen auf
das etablierte Muster umgestellt (`requireMemberAccess()` bzw. Platform-Admin-Community-Schleife
wie beim Avatar-Endpunkt), PR #89 committet, gepusht und gemerged. Alle 95 Tests weiterhin grün.

---

## 2026-08-18 (54) — Claude Code — Claude Sonnet 5
**Prompt:** "bitte gib mir zuvor noch den Befehl, für das lösen des git problems. einfach den
stand vom server nutzen. lokal hab ich nichts geändert. bitte erstell mir den Pfad
app/ios-app/. bitte eine genaue auflistung der App. mit allen Funktionen der Website und den
API Schnittstellen. Bitte auch Bilder und Farben verwenden. Bitte so detailiert wie möglich."
/ "bitte bau alle API nach, die ich brauch. Eigentlich alles was auf der Website ist. Um vom
Handy Mitglieder hinzuzufügen, Dateinen hochladen und alles andere für die Kunden wie
Rechnungen ... Dann bitte ein app.md file mit alle Anweisungen an den Agent in Xcode. den
Baukasten kann er nicht lesen. Bitte in Text-format."
**Auftrag:** Lokalen Git-Stand mit dem Server abgleichen, `app/ios-app/`-Pfad anlegen, eine
detaillierte visuelle App-Spezifikation (Farben/Typografie/Funktionsmatrix/API-Referenz) als
Artifact liefern, anschließend die App-API um Obmann-Rolle sowie alle für "Mitglieder von
unterwegs hinzufügen, Dateien hochladen, Rechnungen & Co." nötigen Endpunkte erweitern, und
zusätzlich eine reine Text-Anleitung (`app.md`) für den KI-Agenten in Xcode schreiben, da dieser
das zuvor gelieferte HTML-Artifact nicht lesen kann.
**Ergebnis:** App-API-Token um Rolle (`member`/`manager`) und `user_id` erweitert
(`database/migrate_20260831.sql`, `webapp/src/AppApiAuth.php`), damit sich auch reine
Obmann-Accounts anmelden können. Neue Mitglied-Endpunkte (`/api/v1/contracts/*`,
`/api/v1/documents*`, `/api/v1/dsgvo-export`, `/api/v1/support*`, `/api/v1/profile*`,
`/api/v1/password`, `/api/v1/2fa/*`) sowie neue Obmann-Endpunkte
(`/api/v1/manager/members*` -- Liste/Detail/Anlegen/Bearbeiten/Datei- und Foto-Upload) in
`webapp/public/index.php` ergänzt, jeweils 1:1 an die bestehende Web-Portal-Logik angelehnt.
Migration live gegen eine native PostgreSQL-Instanz getestet (Nullable `member_id`,
Role-Check-Constraint, `user_id`-FK), alle 95 Unit-Tests grün. `docs/APP_API.md` um alle neuen
Endpunkte erweitert, neue Datei `app/ios-app/app.md` als vollständige Text-Referenz für den
Xcode-Agenten geschrieben (Farben/Typografie aus dem Artifact übernommen, kompletter
API-Überblick, Bildschirmplan). Bewusst NICHT umgesetzt (im Vorfeld mit Patrick abgestimmt):
Abrechnung, EDA-Import, Vertrags-Versand als Obmann, EEG-Einstellungen, Beitrittsfreigabe,
Postfach, Platform-Admin-Funktionen -- bleiben Web-Portal-only.

---

## 2026-08-17 (53) — Claude Code — Claude Sonnet 5
**Prompt:** "wie sieht es aus, wenn wir noch eine App zu unserer Plattform machen wollen. Wie
macht man das am besten mit der Kommunikation? über eine eigene API-Schnittstelle oder direkt
mit der DB kommunizieren?" / "Ja, skizziere mir den Auth-Flow. Und dann auch wie die Daten
sichtbar sind in einer App sowie die Rechnungen als PDF." / "Ja, arbeite bitte das alles als
Implementierungsplan aus. Zusätzlich wenn alles umgesetzt wurde, brauch ich einmal eine
meschliche Beschreibung für mich und meinen Mitschüler und einmal eine datei.md für seine
claude KI. eigentlich soll er die App programmieren. Wird er auch, aber wir werden es mit
Xcode auch parallel programmieren beginnen."
**Auftrag:** Architektur-Beratung zur geplanten Mitglieder-App (eigene API vs. direkter
DB-Zugriff), anschließend Auth-Flow/Datenzugriff/PDF-Handling skizzieren und schließlich als
vollständige Implementierung umsetzen, inklusive einer für Patrick/Mitschüler verständlichen
Beschreibung und einer eigenständigen API-Referenz für die Claude-KI, die den iOS-Client baut.
**Ergebnis:** Neue App-Programmierschnittstelle `/api/v1/*` implementiert: Login-Flow
(E-Mail/Passwort, optionale 2FA, Mehrfach-Mitgliedschaft-Auswahl) mit selbst-signierten
15-Minuten-Zugriffstoken + rotierenden 30-Tage-Refresh-Token (`webapp/src/AppApiAuth.php`,
neue Tabelle `app_sessions` ohne RLS wie `member_api_keys`, `database/migrate_20260830.sql`),
Daten-Endpunkte (`/dashboard`, `/consumption`, `/invoices`, `/invoices/:id/pdf`,
`/metering-points`), Rechnungs-PDF-Route dabei verhaltensgleich aus der bestehenden
Web-Route herausgelöst (`loadInvoiceForPdf()`/`renderInvoicePdf()`) statt dupliziert. Dabei
einen Konflikt mit dem globalen CSRF-Schutz aus der vorherigen Sitzung gefunden und behoben
(`/api/*` ausgenommen, da Bearer-Token strukturell kein CSRF-Risiko hat) sowie die
RLS-sichere Mehrfach-Community-Auflösung über `user_roles` statt eines direkten
`members`-Cross-Community-Joins gelöst. Alles live gegen eine native PostgreSQL-16-Instanz
verifiziert (RLS-Verhalten, Refresh-Token-Rotation inkl. Diebstahlschutz, Mehrfach-/
Einzel-/keine-Mitgliedschaft). Testsuite von 86 auf 94 Tests erweitert. Technische Referenz:
`docs/APP_API.md`.

## 2026-08-17 (52) — Claude Code — Claude Sonnet 5
**Prompt:** "kannst du mir bitte alle Probleme, Fehler und Sicherheitslücken fixen. Mlchte eine
durchgehend sicher und funktionierende Plattform haben. Was mir wichtig wäre, dass keine Daten
wegkommen und sich meine Mitglieder nicht erneut registrieren müssen."
**Auftrag:** Alle Befunde eines vorangegangenen OWASP-Audits der Login-/Session-/
Berechtigungs-Architektur beheben, unter der Vorgabe, dass die Plattform durchgehend
funktionsfähig bleibt, keine Daten verloren gehen und kein Mitglied sich neu registrieren muss.
**Ergebnis:** Alle sechs Befunde umgesetzt und live/empirisch verifiziert (native
PostgreSQL-16- bzw. Redis-Instanz im Sandbox, da Docker nicht verfügbar war): (1) Row-Level
Security greift jetzt tatsächlich -- neue eingeschränkte DB-Rolle statt der bisherigen
Tabellenbesitzer-Rolle (`scripts/db_runtime_role_setup.sh`, `database/migrate_20260822.sql`,
dabei auch eine fehlende `invoice_items`-Policy und einen psql-Interpolationsfehler in
dollar-quotierten DO-Blöcken gefunden und behoben); (2) TOTP-Secrets werden verschlüsselt
gespeichert (`encryptSecret()`), mit rückwärtskompatiblem Lesepfad
(`totpSecretFromStorage()`), der Klartext- und verschlüsselte Secrets gleichermaßen erkennt --
verhindert, dass bestehende 2FA-Nutzer direkt nach dem Deploy ausgesperrt werden, bis das
optionale Nachverschlüsselungs-Skript läuft; (3) Redis-Passwort + `session.use_strict_mode`;
(4) neue `RateLimiter`-Klasse (Redis-Zähler) sperrt Login/2FA nach wiederholten Fehlversuchen,
fail-open bei Redis-Ausfall; (5) zentraler CSRF-Schutz für alle ~70 POST-Formulare in
`Router::dispatch()`, Token-Injection automatisch per Skript in `layouts/base.php`/
`layouts/portal.php`; (6) Security-Header in `nginx.conf`; zusätzlich optional ein
HaveIBeenPwned-Leak-Check bei Passwort-Änderung/-Reset (fail-open). Testsuite von 77 auf 86
Tests erweitert, alle grün. Ausführliche Deploy-Reihenfolge (kein Datenverlust, keine
Downtime, keine Neu-Registrierung) in `docs/DEPLOY_OWASP_AUDIT.md`, verlinkt aus `CLAUDE.md`;
`scripts/setup.sh` für Neuinstallationen entsprechend erweitert.

## 2026-08-13 (51) — Claude Code — Claude Sonnet 5
**Prompt:** "Ich habe jetzt noch mal einen Screenshot von dem Live-Bild gegeben. Das Problem,
das ich habe, ist: Wenn ich den Verbraucher ansehe, geht die Animation vom Verbraucher zur
Energiegemeinschaft. Das ist aber genau falsch, weil ein Verbraucher nie in der
Energiegemeinschaft einspeisen kann. Von den PV-Anlagen geht auch der Energiefluss zu den
Einspeisern, aber wie es eigentlich klar ist, ist, dass die Energie ja von den Einspeisern/
PV-Anlagen zur Energiegemeinschaft geht. Wenn eingespeist wird, geht die Animation von der
Energiegemeinschaft zum Netz. Wenn er vom Netz bezogen wird, geht eine Animation von dem Netz
zur Energiegemeinschaft und weiter zu den Verbrauchern. Bitte die Richtungen ändern. Sie gehen
alle drei Richtungen genau verkehrt herum."
**Auftrag:** Die Fließrichtung der Animation in der Energiefluss-Grafik korrigieren -- alle
drei Verbindungen liefen laut Live-Test verkehrt herum.
**Ergebnis:** Root Cause: `ef-line-pv`/`ef-line-verbrauch` hatten nie eine "reverse"-Klasse
(liefen immer in der CSS-Standardrichtung, die zufällig für beide falsch war), und beim
Netz-Connector war die "reverse"-Bedingung genau umgekehrt gesetzt. Behoben: PV- und
Verbrauch-Connector bekommen permanent "reverse" (Richtung bei diesen beiden ändert sich nie:
PV -> EEG, EEG -> Verbrauch), Netz-Connector jetzt "reverse" bei Bezug statt bei Einspeisung.
Zusätzlich fehlte eine CSS-Regel `.eflow-connector-v.reverse` komplett (gab es bisher nur für
horizontale Connectoren). Mit Playwright/Chromium empirisch verifiziert
(`getComputedStyle(...).animationDirection` für alle drei Connectoren in beiden
Netz-Szenarien), nicht nur rechnerisch hergeleitet (PR #80).

## 2026-08-13 (50) — Claude Code — Claude Sonnet 5
**Prompt:** "Ich wäre dafür, es wird geschaut, welcher Zeitraum. Ist schon eine
Abrechnungsperiode erstellt worden, prüfen, ob es schon einen Monatsexport gibt. Wenn ja
überschreiben, wenn nein importieren. Aber nur dann, wenn er noch nicht abgeschloosen ist der
Rechnungszeitraum. Weil hab noch viele L3 Werte und deswegem muss es auch überschrieben
werden." (Antwort auf einen realen EDA-Auto-Import-Fehlschlag: "Duplikat: Zählpunkt ... hat
bereits ... Datensatz/Datensätze für den Zeitraum 2026-07-31 ...")
**Auftrag:** Der EDA-Import (manuell und automatisch) soll einen erneuten Import für einen
Zeitraum mit bereits vorhandenen Daten nicht mehr immer hart ablehnen, sondern überschreiben
dürfen -- aber nur, solange für diesen Zeitraum noch keine Rechnungen verschickt wurden.
**Ergebnis:** `eda-parser/parser.py`: neue Funktion `_billing_period_finalized()` prüft, ob ein
Abrechnungslauf mit status 'released'/'done' den Import-Zeitraum abdeckt. Wenn nicht: alte
Messwerte + alter `eda_imports`-Eintrag für den Zeitraum werden gelöscht und der neue Import
läuft durch (als Warnung "X Zählpunkt(e) überschrieben" sichtbar). Wenn doch: weiterhin harter
Fehler wie bisher. Gilt einheitlich für `/portal/eda/upload` und `EdaAutoImporter.php` (beide
nutzen `import_to_db()`). Mit einem Mock-Test isoliert verifiziert, kein Zugriff auf eine echte
DB in dieser Umgebung (PR #78). Nebenbei festgestellt: die komplette Auto-Import-Kette (Absender-
Erkennung, Download-Link ohne Portal-Login, Dateibenennung, Community-Zuordnung) hat beim
echten Testlauf zum ersten Mal vollständig funktioniert -- die zuvor offene Frage, ob der
EDA-Downloadlink ohne aktive Portal-Session funktioniert, ist damit positiv beantwortet.

## 2026-08-13 (49) — Claude Code — Claude Sonnet 5
**Prompt:** "hab ein anderes Problem. Er haut mir immer die azure App daten raus. und die
signatur ist auch wieder weg."
**Auftrag:** Ursache dafür finden, warum die Azure-App-Zugangsdaten (Tenant-ID/Client-ID/
Client-Secret) und die Mail-Signatur in Platform-Admin → Einstellungen wiederholt verschwinden.
**Ergebnis:** Root Cause gefunden und behoben (PR #76): das kleine "EDA-Automatik"-Postfach-
Formular postet zur selben Route (`/admin/mail-settings`) wie das große Microsoft-Graph-
Formular, enthält aber nur ein einziges Feld (`eda_import_mailbox_address`). Die UPDATE-Query
baute bisher jedes Feld unbedingt aus `$_POST` auf -- ein im gerade abgeschickten Formular gar
nicht vorhandenes Feld wurde damit auf NULL zurückgesetzt statt unverändert zu bleiben. Jedes
Speichern der EDA-Postfachadresse löschte dadurch Tenant-ID, Client-ID, Signatur, beide
Alarm-E-Mail-Adressen und die Logo-Größe. Fix: `isset($_POST[...])` unterscheidet jetzt "Feld
war in diesem Request gar nicht dabei" von "Feld war dabei, aber bewusst leer abgeschickt"
(gleiches Prinzip, das für `client_secret` schon vorher korrekt war). Mit einem
Simulationsskript für beide Szenarien verifiziert, `php tests/run.php` weiterhin 77/77 grün.
**Wichtig für Patrick:** die bisher schon gelöschten Werte sind dadurch nicht automatisch
wieder da -- Tenant-ID/Client-ID/Client-Secret und die Signatur müssen nach dem Deploy einmal
neu eingetragen werden, bleiben danach aber stehen.

## 2026-08-13 (48) — Claude Code — Claude Sonnet 5
**Prompt:** "ja bitte im Kundenportal auch hinzufügen. Als Ergänzung bitte in der Mitte noch
einen Kreis mit EEG. Zum EDA Import, so sieht eine Email aus. [echte EDA-Exportmail: Absender
no-reply@eda.at, Betreff 'EDA Portal – Energiedatenreport RC108175', Download-Link auf
prod-api.eda-portal.at/exports/download/..., 7 Tage gültig]"
**Auftrag:** Zwei Dinge: (1) die neue Energiefluss-Grafik auch im Kundenportal zeigen, mit
einem zusätzlichen "EEG"-Kreis in der Mitte. (2) Anhand einer echten EDA-Exportmail prüfen/
korrigieren, ob der automatische EDA-Postfach-Import (`EdaAutoImporter.php`) das tatsächliche
Mailformat richtig erkennt.
**Ergebnis:** (1) Energiefluss-Widget nach `webapp/src/views/partials/energy_flow.php`
ausgelagert, in `member_dashboard.php` zusätzlich eingebunden (community-weit, unabhängig von
eigenen Zählpunkten), `/portal/api/live-power` für alle eingeloggten Portal-Nutzer geöffnet
(vorher nur Manager). Neuer beschrifteter "EEG"-Kreis als Pooling-Stelle in der Mitte, ersetzt
den bisherigen Punkt (PR #73). (2) `EdaAutoImporter.php` an das echte Format angepasst:
Absender-Filter auf no-reply@eda.at (alles andere im Postfach wird ignoriert statt
fälschlich als fehlgeschlagener Import zu gelten), gezielte Download-Link-Suche auf die
eda-portal.at-Domain statt dem ersten beliebigen href, Marktpartner-ID-Gegenprobe aus
Dateiname UND Betreff, erzwungene .xlsx-Endung beim Speichern (Link selbst enthält nur eine
UUID). CLAUDE.md/Infrastruktur.md aktualisiert: Mailformat jetzt verifiziert, nur der
eigentliche Live-Download (Portal-Session nötig?) bleibt offen (PR #74). Beides visuell bzw.
mit `php tests/run.php` (77/77 grün) geprüft.

## 2026-08-13 (47) — Claude Code — Claude Sonnet 5
**Prompt:** "[Screenshots einer Fronius/Home-Assistant-Energiefluss-Ansicht] cool wäre für
meine Plattform so eine Live-Grafik, in der man auch sieht, Wieviel wird eingespeist, Wieviel
wird bezogen und als dritte Komponente noch das Netz (wenn ausßerhalb bezogen oder ins Netz
eingespeist wird und nicht in der EEG verwendet wird)."
**Auftrag:** Die bisherige reine Zahlenanzeige (Bezug/Einspeisung) im Obmann-Dashboard durch
eine animierte Energiefluss-Grafik ersetzen, mit PV-Erzeugung, Verbrauch und dem öffentlichen
Netz als drittem, richtungsabhängigem Knoten.
**Ergebnis:** Neue Karte "Energiefluss (Live)" in `manager_dashboard.php`: drei Knoten (PV,
Netz, Verbrauch) um einen zentralen Hub, verbunden mit animierten, richtungsabhängigen
Linien (reines CSS, `repeating-linear-gradient` + `background-position`-Keyframes, kein
externes Chart-Framework). "Netz" = Differenz aus Erzeugung und Verbrauch der ganzen
Community (kein physischer Austausch zwischen Mitgliedern, siehe Erklärtext auf der Karte) --
grün/Pfeil-raus bei Überschuss-Einspeisung, rot/Pfeil-rein bei Zusatzbezug. Keine Backend-
Änderung nötig, nutzt weiterhin `communityLivePower()`/`/portal/api/live-power` mit demselben
5s-Polling. Vor dem Commit visuell verifiziert: temporäre PHP-Testseite mit der echten
`icon()`-Funktion und dem echten `app.css` über `php -S` lokal serviert, mit Playwright/
Chromium (Light- und Dark-Mode, drei Szenarien) gerendert und als Screenshot geprüft, Animation
per `getComputedStyle`-Diff bestätigt aktiv -- Testdateien danach wieder gelöscht. `php
tests/run.php` weiterhin 77/77 grün (PR #71).

## 2026-08-12 (46) — Claude Code — Claude Sonnet 5
**Prompt:** "Release bitte auf später verschieben. meine email einstellungen hat er gelöscht.
bitte bau mir folgende Signatur in HTML nach für meine Website. [...] möchte nach Mit
feundlich ... Ihr Team ... ein eZeile frei, dann eine durchgehende Linie dann wieder zeile
frei, logo und wieder zeile frei. bitte nenne den Bereich nicht mehr E-Mail-Einstellungen
sondern nur Einstellungen."
**Auftrag:** Drei Dinge: (1) das Firmware-Release vorerst nicht weiterverfolgen (keine
Aktion nötig, war ohnehin nichts angestoßen). (2) Eine gelöschte Mail-Signatur als HTML
nachbauen (Gruß, Trennlinie, Logo-Platzhalter, kleines Impressum). (3) Den kompletten
Admin-Bereich "E-Mail-Einstellungen" plattformweit in "Einstellungen" umbenennen, da die
Seite längst mehr als nur E-Mail abdeckt.
**Ergebnis:** (2) HTML-Signatur mit `{{logo}}`-Platzhalter und `<hr>`-Trennlinie nach Vorgabe
gebaut und im Chat zum Einfügen unter Platform-Admin → Einstellungen → Signatur bereitgestellt
(keine direkte DB-Änderung möglich, diese Session hat keinen Zugriff auf den Live-Server). (3)
Navigation, Seitentitel, Fehlermeldungen und Code-Kommentare von "E-Mail-Einstellungen" auf
"Einstellungen" umbenannt (Icon envelope-simple → gear), Route `/admin/mail-settings`
unverändert gelassen; lebende Doku (CLAUDE.md, Infrastruktur.md, ESP_IDEEN.md, BACKUP.md)
mit aktualisiert, Sitzungslog und Original-Spezifikationsentwurf bewusst nicht angefasst
(PR #69). `php tests/run.php` weiterhin 77/77 grün.

## 2026-08-12 (45) — Claude Code — Claude Sonnet 5
**Prompt:** "Ja machen wir. Das mit dem Remote Reconfig. Müssen dann die Ports vom Kunden auch
offen sein? Wenn ja, dann nicht"
**Auftrag:** Freigabe für das zuvor vorgeschlagene Remote-Reconfig-Feature, unter der
Bedingung, dass dafür keine offenen Ports am Router des Mitglieds nötig sind.
**Ergebnis:** Frage geklärt und bestätigt: NEIN, keine offenen Ports nötig -- das Gerät baut
die MQTT-Verbindung immer selbst ausgehend auf, ein Kommando kommt über genau diese bereits
bestehende Verbindung zurück (grundlegend anders als der MQTT-Broker selbst, der auf Patricks
eigener Fritzbox/pfSense eine Portfreigabe braucht). Feature gebaut (PR #67): neues
Kommando-Topic `eeg/{rc}/meter/{znr}/cmd` (`onMqttMessage()` im ESP32-Sketch), über das die
Plattform Host/Port/Benutzer/Passwort ALLER Geräte zentral ändern kann. Sicherheitsnetz:
vorherige Werte werden gesichert, kein erfolgreicher Verbindungsaufbau mit den neuen Werten
innerhalb 5 Minuten -> automatischer Rollback, damit ein Tippfehler kein Gerät dauerhaft
trennt. Plattform-Seite: neue Karte "MQTT-Fernkonfiguration (Geräte)" unter Platform-Admin ->
E-Mail-Einstellungen (`platform_mqtt_config.device_reconfig_*`, migrate_20260829.sql);
`mqtt-subscriber` holt die Anfrage periodisch ab und published sie an alle bekannten
Zählpunkte. `docs/ESP_IDEEN.md`/Firmware-README dokumentiert, `php tests/run.php` weiterhin
77/77 grün. Firmware-Änderung nicht kompiliert/getestet (kein Toolchain in dieser Umgebung).

## 2026-08-12 (44) — Claude Code — Claude Sonnet 5
**Prompt:** "bitte testen von einer älteren Version auf diese auf später verlegen. wichtig
wäre mir, ändere in meinen code die mqtt IP-Adresse auf die Domain stromfueralle.at, den Port
auf 8883 und den MQTT user auf eeg-device. Passwort muss noch selbst bei der einrichtung
eingetragen werden. kann ich bei einer neueren Firmware-Version auch MQTT Userdaten ändern und
die werden dann mit überschrieben? z.B. der Umzug auf eine andere Domain oder einen anderen
MQTT User oder Port?"
**Auftrag:** ESP32-Firmware-Standardwerte für neue Geräte auf den öffentlich erreichbaren
MQTT-Broker (Domain statt LAN-IP, TLS-Port, festen Benutzernamen) umstellen; offener
Auto-Update-Test verschoben statt vergessen; Frage geklärt, ob ein Firmware-Update bereits
gespeicherte MQTT-Zugangsdaten überschreibt.
**Ergebnis:** `cfgMqttHost`/`cfgMqttPort`/`cfgMqttUser`-Defaults (3 Stellen im Sketch:
In-Memory-Init, `loadConfig()`, `/config`-Speichern-Fallback) von `10.0.0.250:1883` (kein User)
auf `stromfueralle.at:8883` (TLS) / `eeg-device` geändert, Passwort bleibt leer (PR #65).
`docs/ESP_IDEEN.md` um Punkt 6 (Auto-Update-Test ältere→aktuelle Version, verschoben) ergänzt.
Frage beantwortet: NEIN, ein Firmware-Update überschreibt gespeicherte MQTT-Werte nicht (NVS/
Preferences-Speicher getrennt vom Programmcode) -- die neuen Defaults gelten nur für noch nie
eingerichtete Geräte. Für einen zentralen Umzug bereits laufender Geräte gibt es aktuell keinen
Mechanismus; als mögliches künftiges Feature (MQTT-Befehlstopic für Remote-Reconfig) genannt,
aber nicht ungefragt gebaut.

## 2026-08-12 (43) — Claude Code — Claude Sonnet 5
**Prompt:** "Der Vorschlag aus der Eingabenliste war: Bitte auch als GitHub-Release taggen,
damit die Auto-Update Firmware es findet. Was ich noch brauche, ist bitte das Arduino-File in
einen Ordner, der gleich heißt, weil Arduino immer einen eigenen Ordner erstellt. Das ist
mühsam. Bitte macht gleich einen Ordner, der gleich heißt, und darin ist die .ino-File."
**Auftrag:** Zwei Punkte zur ESP-Firmware: (1) den aktuellen Firmwarestand als echtes
GitHub-Release taggen, damit die Auto-Update-Geräte es finden; (2) die `.ino`-Datei so
ablegen, dass Arduino beim Öffnen keinen eigenen, neuen Ordner mehr anlegen will.
**Ergebnis:** (2) erledigt und gemerged: `sketch_ESP32_P1_Smart_Meter.ino` zu
`p1-smart-meter.ino` umbenannt, liegt jetzt im gleichnamigen Ordner
`esp32-firmware/p1-smart-meter/`, README entsprechend angepasst (PR #63). (1) NICHT
selbstständig umsetzbar: das angebundene GitHub-MCP-Werkzeug kann Releases nur lesen
(`list_releases`/`get_latest_release`/`get_release_by_tag`), nicht anlegen -- und ein
kompiliertes `p1-smartmeter.bin` fehlt ebenfalls (kein ESP32-Toolchain in dieser Umgebung). Ein
reiner Git-Tag ohne Release-Objekt würde von der ESP-Auto-Update-Prüfung (`/releases`-API,
nicht `/tags`) gar nicht gefunden -- deshalb bewusst KEIN Tag gepusht, um keinen falschen
Eindruck von "erledigt" zu erzeugen. Patrick wurde der bereits im README dokumentierte
manuelle Ablauf (Sketch exportieren → `p1-smartmeter.bin` benennen → GitHub-Release mit Tag
`p1-smartmeter-v1.0.0` + Anhang anlegen) als nächster Schritt genannt.

## 2026-08-12 (42) — Claude Code — Claude Sonnet 5
**Prompt:** "Zu der ESB-Firmware: Da wäre aber doch noch cool oder interessant, wenn der ESB
alle Stunden oder alle paar Stunden die aktuelle Firmwareversion hochlädt und das auch in der
App, unter „WLAN-Konfiguration" oder unter den ESB-Einstellungen auf meiner Webseite, so dass
man die dann auch sieht: Hat sich der ESB schon abgedatet? Hat er sich noch nicht abgedatet?
Um bei eventuell nicht Updaten doch mal vor Ort zu fahren und den ESB zu aktualisieren"
**Auftrag:** Die ESP32-Firmware soll ihre aktuelle Version regelmäßig an die Plattform melden,
damit pro Zählpunkt sichtbar ist, ob das Gerät bereits auf dem neuesten Stand ist oder ein
Vor-Ort-Update nötig wird.
**Ergebnis:** Bestehender Status-Heartbeat (läuft schon alle 10–300s) schickt jetzt zusätzlich
`"fw": "<FIRMWARE_VERSION>"` mit, `mqtt-subscriber` speichert es in
`metering_points.esp_firmware_version` (migrate_20260828.sql). Neue Funktion
`latestFirmwareVersion()` in `public/index.php` fragt die neueste stabile GitHub-Release-
Version ab (1h in `platform_settings` gecacht). Mitglied → Zählpunkt zeigt jetzt ein Badge
("FW 1.0.0 · aktuell" / "· Update auf 1.1.0 verfügbar" / "FW unbekannt"), ebenso das
WLAN-Info-Popup. `docs/ESP_IDEEN.md` und das Firmware-README aktualisiert. Alle 77 Tests
weiterhin grün.

## 2026-08-11 (41) — Claude Code — Claude Sonnet 5
**Prompt:** "Bitte schreib in Github in Claude.md rein, dass er immer das Model und den Prompt
mit dem aktuellen Datum irgendwo vermerkt. brauch es dann für die Dokumentation der
Diplomarbeit."
**Auftrag:** Der Sitzungslog-Eintrag jeder Claude-Sitzung soll künftig zusätzlich zur
Zusammenfassung immer den ursprünglichen Prompt möglichst wörtlich enthalten (zusammen mit
Datum und Modell, die es schon gab) -- als Beleg für die Diplomarbeit-Dokumentation.
**Ergebnis:** `CLAUDE.md`-Abschnitt „Selbstdokumentation" und `obsidian/Infrastruktur.md` um
ein verpflichtendes `**Prompt:**`-Feld (wörtliches Zitat) im Log-Eintragsformat erweitert,
zusätzlich zum bisherigen geglätteten `**Auftrag:**`. Dieser Eintrag selbst folgt bereits dem
neuen Format.

## 2026-08-10 (40) — Claude Code — Claude Sonnet 5
**Auftrag:** Das Anwenden des MQTT-Passworts (bisher ein Server-Kommando zum Copy-Pasten, siehe
(39)) soll auch per Button auf der Plattform gehen, nicht per SSH-Befehl.
**Ergebnis:** `platform_mqtt_config` um `pending_apply`/`applied_at` erweitert
(migrate_20260827.sql). "Speichern & anwenden" im Admin-Formular setzt `pending_apply=true`;
neues Skript `scripts/mqtt_apply_pending.sh` (Host-Cron, gleiches Muster wie
`health_monitor.sh`, einmalig einzurichten -- minütlich) prüft dieses Flag und ruft bei Bedarf
automatisch `mqtt_secure_setup.sh --apply` auf, markiert die Änderung danach in der DB als
erledigt (`applied_at`). Formular zeigt den Status live an ("wird in Kürze angewendet" /
"zuletzt angewendet: ..."). Manueller Befehl bleibt als Fallback sichtbar, falls der Cron
(noch) nicht eingerichtet ist. CLAUDE.md/Infrastruktur.md aktualisiert. `php tests/run.php`
weiterhin 77/77 grün.

## 2026-08-10 (39) — Claude Code — Claude Sonnet 5
**Auftrag:** Drei Dinge: (1) "Rechnung anpassen" für später auf eine Merkliste setzen (ohne
weitere Details, welche Anpassung genau gemeint ist -- Rückfrage gestellt statt geraten). (2) Ein
einfacheres, merkbares MQTT-Passwort, das auf der Plattform sichtbar und änderbar ist. (3) Frage,
ob die WLAN-Passwörter (Mitglieder-Heimnetz, vom ESP32 gemeldet) verschlüsselt sind und wie man
sie ggf. einsehen kann.
**Ergebnis:** (2) umgesetzt: `MQTT_USER`/`MQTT_PASSWORD` lagen bisher ausschließlich in `.env`
auf dem Server, für die Webapp gar nicht einsehbar (kein `.env`-Mount, keine
Environment-Variable). Neue Tabelle `platform_mqtt_config` (Klartext, wie zuvor auch in `.env` --
keine Verschlechterung) macht die DB zur Quelle der Wahrheit; neuer Bereich "MQTT-Zugangsdaten"
unter Platform-Admin → E-Mail-Einstellungen zeigt/speichert Benutzername + Passwort, inkl.
"einfaches Passwort vorschlagen"-Button (Wort-Wort-Zahl-Schema, z.B. "sonne-baum-42" statt
24-stelligem Hex). Da die Webapp Docker/Dateien auf dem Host nicht direkt anfassen kann, bleibt
das Anwenden ein Server-Kommando: `scripts/mqtt_secure_setup.sh` um `--apply` erweitert (liest
Wunschwerte aus der DB via `psql` über `docker compose exec`, schreibt `.env`, erzeugt die
Mosquitto-Passwort-Datei neu, startet mosquitto + mqtt-subscriber neu) -- im Formular selbst als
fertiger Copy-Paste-Befehl angezeigt. CLAUDE.md/Infrastruktur.md dokumentiert. (3) Beantwortet,
kein Code nötig: WLAN-Passwörter werden bereits VOR dem Speichern verschlüsselt --
`mqtt-subscriber/main.py` verschlüsselt das vom ESP32 per MQTT gemeldete Klartext-Passwort sofort
bei Empfang (AES-256-CBC, kompatibel zu `encryptSecret()`/`decryptSecret()` in `functions.php`)
und schreibt nur den Geheimtext nach `metering_points.wifi_password_enc` -- Klartext landet nie
auf der Platte/in der DB. Sichtbar für Obmann/Admin über den Button "WLAN-Info anzeigen" auf der
Mitglied-Detailseite (je Zählpunkt) -- entschlüsselt erst bei diesem Klick per eigenem
Abruf-Endpoint (`/portal/members/:id/metering-points/:mpid/wifi-info`), nicht schon beim
Seitenaufbau (damit das Klartext-Passwort nicht unnötig im initialen HTML landet, siehe
`docs/ESP_IDEEN.md`). (1) offen -- Rückfrage an Patrick, was genau an der Rechnung angepasst
werden soll. `php tests/run.php` weiterhin 77/77 grün.

## 2026-08-10 (38) — Claude Code — Claude Sonnet 5
**Auftrag:** Ob ein ESP32 stündlich selbst auf GitHub nach neuer Firmware suchen und sich
automatisch aktualisieren kann, statt bei jedem Update zu jedem Kunden fahren oder OTA im
selben WLAN hochspielen zu müssen. Wunsch danach konkretisiert: Beta-Versionen sollen nur für
eigene Testgeräte sein, Kundengeräte sollen automatisch nur die "echte", fehlerfreie Version
bekommen; das GitHub-Repo dafür soll im Code leicht änderbar sein (aktuell `eeg-platform`).
**Ergebnis:** In `esp32-firmware/p1-smart-meter/sketch_ESP32_P1_Smart_Meter.ino` umgesetzt:
`checkForFirmwareUpdate()` fragt periodisch (Standard stündlich, im `/config`-Formular ein-/
ausschaltbar) `GET /repos/<repo>/releases` ab (bewusst NICHT den bequemen `/releases/latest`-
Endpunkt, weil dasselbe Repo auch eigene `vX.Y.Z`-Tags für die Plattform selbst vergibt, siehe
CLAUDE.md -- eigenes Tag-Präfix `p1-smartmeter-v` verhindert Verwechslung), sucht darin den
neuesten Release mit passendem Präfix, überspringt dabei automatisch alles mit
`"prerelease":true` (= GitHub-Betaflag "Set as a pre-release") und lädt bei einer neueren
Version den `.bin`-Anhang über `HTTPUpdate` herunter, flasht sich selbst und startet neu. Beta-
Testing kostenlos über genau dieses GitHub-Feature gelöst -- Patrick kann beliebig viele
Pre-Releases anlegen, ohne dass ein Kundengerät sie je bekommt, erst ein "echter" Release wird
ausgerollt. `OTA_UPDATE_REPO` als eigenes `#define` leicht umtragbar. Neue Abhängigkeit:
ArduinoJson (v7, gefiltertes Parsen wegen begrenztem RAM). README.md um vollständigen
Release-Ablauf (Tag-/Asset-Namensschema, Pre-Release-Checkbox) und wichtige Einschränkungen
ergänzt: **kein ESP32-Compiler in dieser Sitzung verfügbar, Code also nicht kompiliert/auf
Hardware getestet**, kein automatisches App-Rollback bei einer kaputten Version (unbedingt zuerst
als Pre-Release auf eigener Testhardware verifizieren, bevor ein echter Release für Kundengeräte
veröffentlicht wird). `php tests/run.php` weiterhin 77/77 grün (unberührt, reine
Firmware-Änderung).

## 2026-08-09 (37) — Claude Code — Claude Sonnet 5
**Auftrag:** MQTT-Fernzugriff (siehe CLAUDE.md, Stand 30.07. "noch nicht eingerichtet") sollte
jetzt eigentlich funktionieren -- Patrick bekam aber keine Pakete über die Domain und wusste
nicht, wie er testen kann. Gemeinsame Live-Fehlersuche per Terminal-Befehlen und Screenshots.
**Ergebnis:** Reine Infrastruktur-Fehlersuche, kein Code geändert. Schrittweise eingegrenzt: (1)
`dig`/`curl ifconfig.me` zeigten scheinbar zwei verschiedene IPs -- Fehlalarm meinerseits, lag
nur daran, dass Patricks Mac beim Testen in einem anderen Netz als zu Hause war, nicht an
falscher DNS. (2) Externe Port-Checker (yougetsignal.com) bestätigten Port 8883 als geschlossen,
obwohl sowohl Fritzbox-Portfreigabe als auch pfSense-NAT-Regel korrekt eingetragen waren. (3)
Tatsächliche Ursache: die pfSense-NAT-Regel hatte keine zugehörige Freigabe unter
Firewall → Rules → WAN -- NAT übersetzt zwar, die Standard-Firewall blockte das Paket aber
trotzdem ohne eigene Allow-Regel. Neuanlegen der NAT-Regel hat die WAN-Freigabe automatisch mit
erzeugt, funktioniert seitdem. CLAUDE.md/Infrastruktur.md aktualisiert: Abschnitt "MQTT-Broker
von außerhalb" von "noch nicht eingerichtet" auf "eingerichtet und funktionsfähig" gesetzt, den
gefundenen Stolperstein (NAT ohne WAN-Firewall-Regel) als Merksatz dokumentiert, dazu Testbefehle
für externe Erreichbarkeit (Online-Port-Checker, `mosquitto_sub` vom eigenen Rechner mit
`--insecure`).

## 2026-08-09 (36) — Claude Code — Claude Sonnet 5
**Auftrag:** Vorher gefragt, wie man das MQTT-Passwort herausfindet (Antwort: steht in `.env`
auf dem Server, `grep MQTT_ .env`). Dann Screenshot eines Mitglieds, dessen Zählernummer entfernt
wurde (Zähler außer Betrieb): trotzdem zeigt die Zählpunkt-Tabelle weiterhin "Erreichbar" und
einen "WLAN-Info anzeigen"-Link. Soll korrigiert werden: ohne Zählernummer keine ESP-/Zähler-
Statusanzeige.
**Ergebnis:** Root Cause: `esp_last_seen_at`/`meter_reachable`/`esp_online` bleiben auf dem
Zählpunkt-Datensatz stehen, auch wenn `meter_code` (Zählernummer) später wieder gelöscht wird --
alle Statusanzeigen prüften bisher nur `mp.active`, nie `meter_code`. Behoben an allen drei
Stellen mit demselben Muster: `member_detail.php` (ESP-Spalte + Zähler-Spalte in der
Zählpunkt-Tabelle zeigen jetzt "—" ohne Zählernummer, "WLAN-Info anzeigen" nur noch mit
Zählernummer), `/portal/members`-Abfrage (Mitgliederliste, "Zähler"-Spalte OK/Fehler-Badge:
`hat_esp_bekannt`/`hat_esp_fehler` zusätzlich auf `mp.meter_code IS NOT NULL` geprüft) und die
beiden `total_meters`-Zählungen für den Live-Dashboard-"nicht alle Zählpunkte online"-Hinweis
(`communityLivePower()` + `/api/v1/live`-ähnlicher Block). `php tests/run.php` weiterhin 77/77
grün (kleiner PHP-Parse-Fehler beim ersten Versuch durch unescapte doppelte Anführungszeichen in
einem SQL-Kommentar selbst verursacht und sofort behoben).

## 2026-08-07 (35) — Claude Code — Claude Sonnet 5
**Auftrag:** Neue Rechnungsnummer "noch nicht übernommen". EDA-Datenqualität soll schon beim
Hochladen der Datei ausgelesen und angezeigt werden. Genereller Wunsch: Fehlermeldungen IMMER
anzeigen, bevor nichts passiert -- konkret: "Rechnungen berechnen" geklickt, es passiert nichts,
keine Rückmeldung.
**Ergebnis:** Echten, systemischen Bug gefunden: mehrere POST-Routen leiten bei einem Fehler auf
`?error=...` um, aber die zugehörige GET-Route hat den Query-Parameter nie in die von der View
geprüfte `$error`-Variable übernommen -- die Fehlermeldung (inkl. Audit-Log-Eintrag) wurde zwar
korrekt erzeugt, aber der Nutzer sah nie etwas davon. Betraf `/portal/billing` (das erklärt
direkt "Rechnung berechnen geht nicht, es kommt nichts" -- der neue L3-Block aus (34) griff
vermutlich bereits, nur eben unsichtbar, weshalb auch die neue Rechnungsnummer nie zum Einsatz
kam), `/portal/settings` und `/admin/mail-settings` (dort fehlte die Fehleranzeige komplett).
Alle drei behoben; zusätzlich zeigt `/portal/billing` bei Erfolg jetzt den echten Meldungstext
statt eines hartkodierten "Gespeichert." EDA-Datenqualität: `edaImportsForCommunity()` liest jetzt
per Subquery aus `eda_measurements` (nicht aus der Datei erneut, sondern aus den beim Import schon
gespeicherten Qualitätswerten je Zählpunkt) die Anzahl L1/L2 ("belastbar") vs. L3 ("nicht
belastbar") je Import aus und zeigt sie als neue Spalte in der "Bisherige Importe"-Tabelle auf
`/portal/eda/upload` -- bewusst als TIMESTAMPTZ-zu-TIMESTAMPTZ-Vergleich (period_from/period_to
aus eda_imports direkt gegen em.time), um dieselbe Zeitzonen-Fallgrube wie bei
`Billing::missingMonths()` erst gar nicht zu riskieren. `php tests/run.php` weiterhin 77/77 grün.

## 2026-08-06 (34) — Claude Code — Claude Sonnet 5
**Auftrag:** Neues Rechnungsnummer-Schema (`RE-26XXXX_RCYYYYYY_Nachname_Vorname`, laufende Nummer
ab 0001). Klarstellung zu (33): der Mitgliedsbeitrag ist wirklich nur EINMAL pro Mitglied fällig
(2 €/Monat für die Vereinsmitgliedschaft selbst), unabhängig von der Zählpunkt-Anzahl -- die
Multiplikation aus (33) war ein Missverständnis meinerseits. Zusätzlich: bei Qualität L3 soll
schon das Berechnen der Rechnungs-Entwürfe verweigert werden, nicht erst die Freigabe.
**Ergebnis:** `Billing::generateDrafts()` -- Mitgliedsbeitrag-Multiplikation aus (33) zurückgebaut
(wieder einmal pro Mitglied). Neues Rechnungsnummer-Format `RE-<Jahr 2-stellig><laufende Nummer,
4-stellig>_<Marktpartner-ID>_<Nachname>_<Vorname>` (Firmenmitglieder: company_name statt
Nachname, Vorname leer) -- laufende Nummer je EEG UND Jahr getrennt (nicht plattformweit
gemeinsam, weil jede EEG ein eigener Verein mit eigener, lückenloser Nummerierung nach § 11 UStG
ist), ermittelt über die Anzahl bereits bestehender Rechnungen dieser EEG in diesem Jahr (bleibt
bei mehrfachem "Neu berechnen" desselben Laufs stabil, da nur die eigenen Entwürfe vorher
gelöscht werden). Neue private `Billing::slugName()` transliteriert deutsche Umlaute und entfernt
alles außer Buchstaben/Ziffern (die Rechnungsnummer dient auch als SEPA-Verwendungszweck/
EndToEndId und PDF-Dateiname). Dabei aufgefallen: EndToEndId ist laut ISO-20022/pain.008 auf 35
Zeichen begrenzt -- die neue, deutlich längere Rechnungsnummer kann das bei längeren Namen
überschreiten (Beispielrechnung: "RE-260001_RC108175_Hoefferer_Ingrid" = bereits genau 35
Zeichen) und hätte sonst die komplette SEPA-Datei bei der Bank zum Absturz gebracht --
`sepaPain008Xml()` kürzt EndToEndId jetzt defensiv auf 35 Zeichen. `Billing::l3Count()` aus
`datenqualitaetProblem()` herausgezogen und zusätzlich in `generateDrafts()` verwendet: liegen
noch L3-Werte im Zeitraum vor, wirft die Methode jetzt schon beim Berechnen der Entwürfe eine
RuntimeException (surfaced über die bestehende try/catch-Fehlerbehandlung in
`/portal/billing/generate`), nicht erst bei der Freigabe. `php tests/run.php` weiterhin 77/77
grün; `slugName()`-Transliteration (Umlaute, Bindestrich, Apostroph) manuell gegengeprüft.

## 2026-08-06 (33) — Claude Code — Claude Sonnet 5
**Auftrag:** Nach dem ersten echten Testlauf (EDA-Import Juli + Rechnungen berechnen) gleich
mehrere Beobachtungen auf einmal: (1) Mitgliedsbeitrag wird nur einmal pro Mitglied verrechnet,
unabhängig von der Zählpunkt-Anzahl -- soll pro Zählpunkt gelten. (2) Abrechnungsübersicht zeigt
weiterhin "fehlt: 2026-07", obwohl der Import längst gemacht wurde -- Fehlermeldung soll
verständlicher werden. (3) Zu geringer Kontrast bei den Rechnungsbeträgen. (4) Bei nur einer
einzigen Juli-Abrechnung zeigt die Mitgliederliste bei manchen schon 4,00€ Offener Betrag statt
2,00€. (5) Auf der PDF-Rechnung erscheinen weiterhin Bezug- UND Einspeisungszeile, obwohl das
Mitglied nur eines von beidem hat (mit angehängter Beispiel-PDF von Stefanie Schwaiger).
**Ergebnis:** Vier echte Bugs gefunden und behoben, einer als Deployment-Lücke erklärt:
(1) `Billing::generateDrafts()` multipliziert den anteiligen Mitgliedsbeitrag jetzt mit
`count($group['metering_points'])`. (2) `Billing::missingMonths()`: `eda_imports.period_from`
ist TIMESTAMPTZ und wird vom Parser als Europe/Vienna-Mitternacht gespeichert (01.07. 00:00 Wien
= 30.06. 22:00 UTC) -- `to_char()` ohne `AT TIME ZONE` formatierte das in der UTC-Session-Zeitzone
der DB-Verbindung, ein Juli-Import landete dadurch im Monats-Bucket "2026-06" und "fehlte" für
Juli für immer. Fix: `to_char(period_from AT TIME ZONE 'Europe/Vienna', ...)`, zusätzlich die
period_from/period_to-WHERE-Eingrenzung entfernt (gleiche Fallgrube, überschaubare Datenmenge
macht den PHP-seitigen array_diff-Abgleich unkritisch). (3) `billing_invoices.php`: der
Rechnungsbetrag war hart auf `#111827` (dunkles Blaugrau) codiert -- unsichtbar im Dark Mode.
Jetzt `var(--gray-800)` (themafähiges Token) + fett. (4) `/portal/members`: klassisches
SQL-Fan-out -- `LEFT JOIN metering_points` UND `LEFT JOIN invoices` in derselben Query (beides
1:n) erzeugte bei 2 Zählpunkten + 1 offener Rechnung ein Kreuzprodukt aus 2 Zeilen, wodurch
`SUM(i.saldo_eur)` dieselbe Rechnung doppelt zählte -- exakt bei Mitgliedern mit eigenem Bezugs-
UND Einspeisungs-Zählpunkt reproduziert. Fix: `open_amount` als unabhängige Subquery statt JOIN.
(5) War in Wirklichkeit BEREITS in Sitzung (30) behoben (`rechnung.tex`-Fallback entfernt, PR
#51) -- aber `latex-service/docker/entrypoint.sh` kopiert die mitgelieferten Standard-Vorlagen
nur EINMALIG auf ein leeres `/opt/eeg/latex-templates`-Volume; ein `git pull` aktualisiert die
schon einmal befüllte Live-Datei nie automatisch. Patrick braucht dafür einen manuellen Kopier-
Befehl (kein Code-Fix nötig, siehe Chat-Antwort). Nebenbefund aus der PDF: die 0,00-kWh-Zeilen bei
Stefanie sind zusätzlich auch deshalb 0, weil der Juli-Import komplett Qualität L3 hat (nicht
belastbar, wird laut Billing::ABRECHNUNGS_QUALITY korrekt nicht abgerechnet) -- kein Bug, sondern
erwartetes Verhalten bis ein späterer Monatsbericht die Qualität auf L1/L2 hochstuft.
`php tests/run.php` weiterhin 77/77 grün.

## 2026-08-06 (32) — Claude Code — Claude Sonnet 5
**Auftrag:** Wieder eine als „Parser-Fehler" angezeigte Meldung geschickt -- diesmal endete sie
aber mit einem sauberen, vollständigen JSON-Erfolgsergebnis (10 Datensätze importiert).
**Ergebnis:** Echter, eigenständiger Bug gefunden, unabhängig vom 500→4000-Zeichen-Fix von
vorhin: Der Parser-Aufruf leitete stderr mit `2>&1` in denselben String wie stdout um. Pythons
`logging` schreibt INFO/WARNING-Zeilen auf stderr, das JSON-Ergebnis kommt von `print()` auf
stdout -- kombiniert ergibt das "<Logzeilen>{json}", und `json_decode()` verlangt, dass der
GESAMTE String gültiges JSON ist. Das schlug bei JEDEM Import fehl, sobald auch nur eine
Logzeile davor stand (immer der Fall, allein durch "Lese XLSX") -- ein Import wurde also selbst
bei vollem Erfolg als "Parser-Fehler" gemeldet, obwohl die Daten korrekt in der DB landeten.
Neue Klasse `EdaParserRunner.php` (per `proc_open()` mit getrennten Pipes für stdout/stderr statt
einer Shell-Umleitung) ersetzt die bisherigen `shell_exec(...2>&1)`-Aufrufe in beiden Importpfaden
(manueller Upload in `public/index.php` UND `EdaAutoImporter.php`) -- stdout wird jetzt korrekt
als JSON geparst, stderr dient nur noch für die Fehlerdiagnose, wenn wirklich etwas schiefging.
Mit einem kleinen Test-Python-Skript (stderr-Logzeilen + stdout-JSON) lokal verifiziert, dass die
Trennung tatsächlich funktioniert. `php tests/run.php` weiterhin 77/77 grün.

## 2026-08-06 (31) — Claude Code — Claude Sonnet 5
**Auftrag:** Nach dem "Duplikat"-Testfehler (siehe unten) gefragt, wo importierte EDA-Dateien
gelöscht werden können -- Antwort war eine manuelle SQL-Anleitung. Patrick möchte das stattdessen
als richtige Funktion: eine Liste aller importierten Dateien mit Zeitraum, und einen
Löschen-Button je Import.
**Ergebnis:** `/portal/eda/upload` zeigt jetzt unter dem Upload-Formular eine Tabelle "Bisherige
Importe" (Datei, Zeitraum, Datensätze, Status-Badge, Importiert-am/von, Löschen-Button) aus
`eda_imports`. Neue Route `POST /portal/eda/imports/:id/delete` (manager-geschützt, mit
`confirmDangerDelete()`-Sicherheitsabfrage wie beim Abrechnungslauf-Löschen) entfernt den
Import-Log-Eintrag UND die dabei importierten `eda_measurements` -- danach lässt sich dieselbe
Datei erneut hochladen, ohne dass der Parser mit "Duplikat" abbricht. Da `eda_measurements`
keinen Rückverweis auf den einzelnen Import hat, wird nach exaktem Zeitraum gelöscht (genau wie
die Duplikat-Prüfung selbst); Metering-Points bleiben unangetastet, nur die Energiedaten
verschwinden. Kleiner Hinweistext macht klar, dass bereits berechnete Rechnungs-Entwürfe dadurch
NICHT automatisch neu berechnet werden. `php tests/run.php` weiterhin 77/77 grün, auf den
bestehenden PR #51 gepusht (noch offen).

---

## 2026-08-06 (30) — Claude Code — Claude Sonnet 5
**Auftrag:** Auf der Rechnung soll nur eine Bezugs- bzw. Einspeisungszeile erscheinen, wenn das
Mitglied tatsächlich bezieht bzw. einspeist -- plus Freigabe zum Pushen/PR-Erstellen für die
zuvor besprochenen, noch offenen Änderungen.
**Ergebnis:** Ursache in `latex-service/templates/rechnung.tex` gefunden: Die
Positionstabelle hatte einen Fallback, der bei leerer RAW-Positionsliste (= Mitglied hat für den
Zeitraum GAR KEINEN Bezug/GAR KEINE Einspeisung, z.B. reiner Einspeiser ohne Bezug) trotzdem
eine Zeile mit 0,00 kWh gerendert hat, statt die Zeile einfach wegzulassen -- der Fallback war
ursprünglich für den Einzeiler-Fall gedacht, wird aber seit Einführung der RAW-Zeile-pro-Zählpunkt
nie mehr für einen ECHTEN Einzelposten erreicht, nur noch für den Nullfall. Fallback entfernt:
Bezugs-/Einspeisungsblock (inkl. Trennlinie) wird jetzt komplett ausgeblendet, wenn die jeweilige
RAW-Liste leer ist. `php tests/run.php` weiterhin 77/77 grün (LaTeX-Rendering selbst ohne
LaTeX-Compiler in dieser Sandbox nicht kompilierbar/testbar -- \ifx/\fi-Zählung geprüft,
balanciert). Alle drei ausstehenden Commits (Fehlermeldungs-Limit, Mitglieder-Beitrittsdatum,
diese Bezug/Einspeisung-Zeile) jetzt gepusht + PR erstellt wie von Patrick freigegeben.
Hinweis für Patrick: falls die Rechnungsvorlage über `/admin/templates` bereits individuell
angepasst/hochgeladen wurde, gilt dieser Fix nur für die mitgelieferte Standardvorlage im Repo --
eine schon angepasste eigene Vorlage auf dem Server müsste manuell nachgezogen werden.

---

## 2026-08-06 (29) — Claude Code — Claude Sonnet 5
**Auftrag:** (1) Fehlermeldung eines EDA-Imports lief nach den ersten „Fehlender
Zählpunkt"-Warnungen einfach ab, ohne die eigentliche Ursache zu zeigen. (2) Bei einer
Monatsrechnung (z.B. 2026-07) dürfen nur Mitglieder verrechnet werden, die in diesem Monat auch
schon Mitglied waren -- selbst wenn der Lauf erst später (z.B. im August) erstellt wird, darf ein
erst im August beigetretenes Mitglied nicht in der Juli-Abrechnung auftauchen; das Gleiche soll
für den Mitgliedsbeitrag gelten (nur die Zeit verrechnen, in der man dabei war).
**Ergebnis:** (1) `substr($output, 0, 500)` in beiden Fehlerpfaden (manueller Upload +
EdaAutoImporter) auf 4000 Zeichen angehoben -- die vier Warnungen allein füllten die alten 500
Zeichen fast komplett, wodurch der eigentliche Traceback nie sichtbar wurde. (2) Zwei
Lücken in `Billing::generateDrafts()` gefunden: Die Mitglieder-Auswahlabfrage filterte nur nach
`status='active'`, nicht nach Beitrittsdatum -- ein Mitglied, das erst nach dem
Abrechnungszeitraum beigetreten ist, bekam trotzdem eine (wenn auch 0-EUR-)Rechnung in einem
zurückliegenden Lauf. Jetzt zusätzlich `m.member_since <= period_to` in der WHERE-Klausel.
Zweitens wurde Energie (Bezug/Einspeisung) für ein MITTEN im Zeitraum beigetretenes Mitglied
bisher für den KOMPLETTEN Zeitraum abgerechnet, nicht nur ab dem Beitrittsdatum (nur der
Mitgliedsbeitrag war über `mitgliedsbeitragAnteilig()` schon anteilig -- die Energie-Summe nicht)
-- die eda_measurements-Abfrage summiert jetzt nur noch ab `GREATEST(period_from, member_since)`,
tagesgenau statt wie beim Mitgliedsbeitrag nur monatsgenau (EDA-Messwerte liegen ohnehin
zeitgenau vor). `php tests/run.php` weiterhin 77/77 grün (reine DB-Änderung, keine neue
Unit-Test-Abdeckung möglich ohne DB-Fixture). Wie gehabt auf den Feature-Branch gepusht, aber
(noch) NICHT nach `main` gemergt/PR erstellt -- wartet auf Freigabe durch Patrick.

---

## 2026-08-06 (28) — Claude Code — Claude Sonnet 5
**Auftrag:** Screenshot eines fehlgeschlagenen manuellen EDA-Uploads (`/portal/eda/upload`):
Parser-Fehler direkt beim Lesen der XLSX, Python-Traceback endet in
`zoneinfo/_common.py, load_tzdata`.
**Ergebnis:** Infrastruktur-Lücke, kein Parser-Logikfehler -- Alpine liefert von sich aus keine
IANA-Zeitzonendatenbank (`/usr/share/zoneinfo`) mit; Pythons `zoneinfo`-Modul (von pandas beim
Excel-Datumsparsing transitiv verwendet) schlägt ohne diese Datenbank fehl. `webapp/Dockerfile`
installiert jetzt zusätzlich das Alpine-Paket `tzdata` (statt eines separaten pip-Pakets, damit
auch PHP-Datumsfunktionen von derselben Systemdatenbank profitieren). Wirkt erst nach
`docker compose up -d --build`; in dieser Sandbox ohne Docker-Daemon nicht baubar/testbar --
sollte nach dem nächsten Rebuild auf dem Server verifiziert werden.

---

## 2026-08-05 (27) — Claude Code — Claude Sonnet 5
**Auftrag:** Fortsetzung von (26), Punkt 3: Zugang zum EDA-Anwenderportal ist E-Mail + Passwort;
Patrick kann dort einen eigenen Export-User anlegen, an dessen E-Mail-Adresse der Exportlink
geschickt wird -- am liebsten an ein neues, dediziertes Postfach `eda@stromfueralle.at`, über
dieselbe Microsoft-Graph-Anbindung, die schon organisationsweit für den Mailversand genutzt wird.
**Ergebnis:** Automatischen Postfach-Import umgesetzt, das eigentliche Auslösen des Exports im
EDA-Portal (Login + Klick) bleibt bewusst manuell -- dafür fehlen die DOM-Details des Portals,
blind zusammengeklickte Selektoren wären nur Schein-Automatisierung. Neu: `GraphMailReader.php`
(liest ein Postfach über Microsoft Graph, Mail.Read -- Mailer.php dafür `config()`/
`getAccessToken()` public gemacht statt dupliziert), `EdaAutoImporter.php` (Kernlogik: Anhang
oder ersten Link im Mailtext herunterladen, Marktpartner-ID aus dem EDA-Dateinamensschema
`RC108175_...` lesen, passende EEG nachschlagen, `eda-parser/parser.py` aufrufen, Audit-Log-
Eintrag, Mail als gelesen markieren; bei Fehlern bleibt die Mail ungelesen + Alarm-Mail an die
Backup-Alarm-Adressen), `scripts/eda_auto_import.php` als Cron-Wrapper (analog
`health_alert.php`). Neue Spalten `communities.eda_login_email/eda_login_password_enc`
(verschlüsselt wie WLAN-Passwörter) und `platform_mail_config.eda_import_mailbox_address`
(migrate_20260825.sql) mit Admin-UI: EDA-Zugangsdaten je EEG bei „EEG konfigurieren", neuer
Abschnitt „EDA-Automatik" bei den E-Mail-Einstellungen (Postfachadresse + „Jetzt prüfen"-Button
zum Testen ohne Cron). CLAUDE.md/Infrastruktur.md um Einrichtungsschritte ergänzt (Shared
Mailbox anlegen, zusätzliche Berechtigung `Mail.Read` für die bestehende Azure-App, Cron-Eintrag).
Nicht verifizierbar ohne echte EDA-Exportmail: dass der Download-Link ohne weiteren
Portal-Login abrufbar ist -- als offene Annahme dokumentiert, Fallback bleibt der bestehende
manuelle Upload über `/portal/eda/upload`.

---

## 2026-08-05 (26) — Claude Code — Claude Sonnet 5
**Auftrag:** Drei Wünsche nach dem EDA-Parser-Umbau: (1) Das Datum soll zuverlässig aus der
Excel-Tabelle selbst kommen, damit man bei einer späteren Quartalsabrechnung wirklich alle
Monate hat und keinen vergisst; ein reiner Monatslauf soll auch funktionieren, nicht nur
Quartale. (2) Im Testmodus testweise Rechnungen für einen/alle Kunden erstellen können, inkl.
Bearbeitungsmodus, um den Mitgliedsbeitrag zu reduzieren/gutzuschreiben/Rabatt zu geben. (3)
EDA-Portal-Automatisierung: Login/Monatsexport-Auswahl automatisieren und bei Eintreffen der
Download-Link-E-Mail automatisch die Datei holen.
**Ergebnis:** (1) und teilweise (2) umgesetzt, (3) noch offen (Rückfragen gestellt, siehe unten).
`Billing::periodDates()` (vormals `quarterDates()`) akzeptiert jetzt zusätzlich zum
Quartalsformat auch einen einzelnen Monat ("2026-07"); `/portal/billing` erlaubt beides beim
Anlegen eines Laufs. Neue `Billing::missingMonths()`: prüft, ob für jeden Kalendermonat im
Zeitraum eines Laufs überhaupt ein `eda_imports`-Eintrag existiert -- als drittes,
höchstprioritäres Kriterium in `datenqualitaetProblem()` ergänzt (blockiert die Freigabe) und
zusätzlich schon in der Abrechnungsübersicht als roter Hinweis sichtbar, nicht erst beim
Freigabe-Versuch. Damit das zuverlässig funktioniert, liest `eda-parser/parser.py` jetzt den im
Datei-Kopf deklarierten "Auswertungszeitraum von/bis" (immer ein voller Kalendermonat) separat
aus (`LoadResult.period_from/period_to`) und nutzt ihn für `eda_imports.period_from/period_to`
-- vorher wurde das aus dem Minimum/Maximum der (teils unterjährig verkürzten)
Zählpunkt-Teilnahmezeiträume abgeleitet, was den Monat nicht immer zuverlässig als vollständig
markiert hätte. (2) Recherche ergab: Testweise Rechnungen erstellen (für alle aktiven
Mitglieder) UND die Positionen jeder einzelnen Rechnung anschließend bearbeiten (Betrag ändern,
Position hinzufügen -- z.B. Mitgliedsbeitrag reduzieren oder Rabatt geben) existiert bereits
vollständig (`Billing::generateDrafts()` + `/portal/billing/invoices/:id/edit`), ebenso ein
"Abrechnungslauf löschen"-Button für einen kompletten Verwurf nach dem Testen -- dem Nutzer
erklärt statt dupliziert. (3) EDA-Portal-Login-Automatisierung + E-Mail-getriggerter
Datei-Download: Rückfragen zu Zugangsdaten-Speicherung/Sicherheit, 2FA/CAPTCHA am Portal und
Postfach-Zugriffsweg gestellt, bevor Code geschrieben wird (echte externe Zugangsdaten, nicht
leichtfertig automatisierbar). `php -l` + `php tests/run.php` (77/77) grün; neuer Parser erneut
gegen die reale Datei getestet (Datei-Zeitraum jetzt korrekt 2026-07-01–2026-07-31 statt aus
Einzelwerten abgeleitet).

---

## 2026-08-05 (25) — Claude Code — Claude Sonnet 5
**Auftrag:** Echten EDA-Monatsexport (xlsx, Sheets „Gesamtübersicht"/„Detailübersicht") als
Datei-Upload geschickt mit der Bitte, darauf basierend das Abrechnungssystem zu bauen -- ein
Quartal soll aus mehreren Monatsexporten zusammengesetzt werden.
**Ergebnis:** Vor dem Programmieren zuerst per Explore-Subagent den bestehenden Stand geklärt:
Der Zählpunkt-Abgleich beim Import war schon seit 29.07.2026 fertig, aber
`eda-parser/parser.py` war auf ein komplett geratenes, nie an einer echten Datei getestetes
Dateiformat kalibriert (Sheets „Übersicht"/„Energiedaten", 15-Min-Zeitreihen) -- passte nicht
zur echten Datei. Die reale Datei selbst mit `openpyxl` analysiert: Sheet „Gesamtübersicht"
liefert pro Zählpunkt und Monat bereits die fertig zugeteilte „abrechnungsrelevante
Energiemenge" (nach Richtung VERBRAUCH/ERZEUGUNG), exakt das, was `Billing::generateDrafts()`
als `kwh_teilnahme`/`kwh_erzeugung` braucht -- keine eigene Aufteilungsschlüssel-Berechnung
nötig, EDA liefert das Ergebnis schon fertig (deckt sich mit `docs/AUFTEILUNGSSCHLUESSEL.md`).
`eda-parser/parser.py` komplett neu geschrieben: Header-Zeile per Namenssuche ("Zählpunktnummer")
statt fixer Zeilennummer gefunden, Spalten per Substring-Suche, Datenqualität bei
kommagetrennten Werten ("L1,L3") worst-case auf L3 abgebildet, Zählpunkt-Typ direkt aus
Energierichtung statt Erzeugung/Verbrauch-Summen-Vermutung. Da EDA nur eine Zeile pro Monat und
Zählpunkt liefert, schreibt der Import jetzt genau eine `eda_measurements`-Zeile je Zählpunkt
und Monat -- ein Quartal ergibt sich aus drei Monatsimporten, die `Billing::generateDrafts()`
unverändert per SUM() über den Zeitraum zusammenfasst (keine PHP-Änderung nötig). Neuen Parser
gegen die reale Datei getestet (ohne DB, reine Parsing-Verifikation): alle 10 Zählpunkte korrekt
erkannt, Werte stimmen exakt mit den EDA-Spalten überein. Nebenbei die nie angebundene,
veraltete `check_billing_readiness()`-Funktion (starre 60-Tage-Regel) entfernt und
Hinweistexte in `eda_upload.php` korrigiert (falsches Dateiname-Beispiel, veraltete
60-Tage-Erwähnung). `docs/AUFTEILUNGSSCHLUESSEL.md`, `docs/EDA_DATENQUALITAET.md` und
`docs/ESP_IDEEN.md` (Punkt 3 von "Offen" nach "Umgesetzt" verschoben) aktualisiert. `php -l` +
`php tests/run.php` (77/77) grün; `python3 -m py_compile` für den Parser grün.

---

## 2026-08-05 (24) — Claude Code — Claude Sonnet 5
**Auftrag:** Der Neuigkeiten-Punkt aus Eintrag 23 ist jetzt sichtbar, aber die Farbe stimmt
nicht -- "bitte in einem richtigen Kirschrot, so ist der Punkt nicht erkenntlich" (Screenshots
zeigten einen blassen/kaum sichtbaren Punkt bei "Support" in der eingeklappten Sidebar sowie
ein rötliches Emoji-Zeichen statt eines echten Punkts beim Rollen-Umschalter).
**Ergebnis:** Zwei Ursachen gefunden und behoben: (1) `.sidebar.collapsed a .badge` übernahm
bisher `background` von `.badge-red`/`.badge-yellow` -- diese Klassen sind für eine Pille MIT
Zahl gedacht (helles Pastell + kräftige Textfarbe), als reiner Punkt ohne Zahl blieb nur das
blasse Pastell übrig, im Dark Mode (`#450a0a`, sehr dunkel) praktisch unsichtbar. Jetzt fixes
kräftiges Rot (`#dc2626`) bzw. Orange (`#d97706`) unabhängig vom Theme. (2) Beim
Rollen-Umschalter zeigte die 🔴-Emoji-Markierung vor dem Options-Namen zusätzlich blass/falsch
gefärbt -- Emoji-Farbe ist plattformabhängig und lässt sich innerhalb einer `<option>` nicht per
CSS beeinflussen. Emoji entfernt, verlässlicher Hinweis bleibt nur der bereits vorhandene, fix
`#dc2626` gefärbte Punkt direkt am Dropdown. Nebenbei totes `$roleHasNews`-Array entfernt (wurde
nach Entfernen der Emoji-Anzeige nirgends mehr gelesen). `php -l` + `php tests/run.php` (77/77)
grün.

---

## 2026-08-05 (23) — Claude Code — Claude Sonnet 5
**Auftrag:** Der neue Support-Tickets-Badge war in der eingeklappten (Icon-only) Sidebar
komplett unsichtbar -- Screenshot zeigte: kein Punkt im Startfenster, nur wenn man selbst auf
"Support" geht (dort dann wieder eingeklappt genauso unsichtbar). Zusätzlich gewünscht: da
Patrick gleichzeitig Platform-Admin UND Obmann ist, soll beim Rollen-Umschalter in der
Kopfzeile ebenfalls ein roter Punkt erscheinen, wenn in der jeweils anderen Rolle etwas Neues
wartet (z. B. Support-Ticket beim Obmann, während man als Platform-Admin arbeitet).
**Ergebnis:** Ursache gefunden: `.sidebar { overflow-x:hidden }` + `.sidebar.collapsed` (52px
breit) schnitt den Badge ab, weil im schmalen Zustand nach Icon + Abstand kein Platz mehr blieb
-- Badge existierte im HTML, war aber immer unsichtbar, solange die Sidebar eingeklappt war
(voreingestellter Normalzustand). Fix: `.sidebar.collapsed a .badge` wird zu einem kleinen
Punkt ohne Zahl, absolut positioniert direkt auf dem Icon. Zusätzlich neuer roter Punkt beim
Rollen-Auswahl-Dropdown (`portal.php`): für jede Rolle außer der aktiven wird geprüft, ob
offene Postfach-Benachrichtigungen oder ungelesene Support-Nachrichten vorliegen (nur für
`manager`-Rollen relevant); trifft das auf mind. eine NICHT aktive Rolle zu, erscheint ein
kleiner roter Punkt am Dropdown selbst, zusätzlich 🔴 vor der betroffenen Rolle in der Liste.
`DB::setCommunity()` wird nach der Prüfschleife wieder auf die tatsächlich aktive Community
zurückgesetzt, damit nachfolgende Sidebar-Abfragen nicht in der falschen Community laufen.
`php -l` + `php tests/run.php` (77/77) grün.

---

## 2026-08-04 (22) — Claude Code — Claude Sonnet 5
**Auftrag:** Button in der Mitgliederliste, der nur erscheint, wenn sich jemand noch nie
angemeldet hat -- sendet die Willkommens-E-Mail mit 24h-gültigem Erstlogin-Link erneut. Zusätzlich
gewünscht: klickt jemand auf einen alten/abgelaufenen Link, soll eine verständliche Meldung
kommen ("Link abgelaufen, bitte neuen Link anfordern").
**Ergebnis:** Neue Route `POST /portal/members/:id/resend-invite` (sendet dieselbe
"invite"-Mailvorlage wie beim Erstanlegen, 24h-Token via `Auth::createResetToken()`),
serverseitig gegen Mehrfachnutzung durch bereits aktive Mitglieder abgesichert
(`last_login_at`-Prüfung). Button in `member_list.php` erscheint nur bei
Login-Konto-vorhanden-aber-noch-nie-eingeloggt, direkt bei "Noch nicht angemeldet" in der
zuletzt hinzugefügten Spalte. Die "abgelaufener Link"-Meldung mit Verweis auf
`/portal/forgot-password` existierte bereits vollständig (`reset_password.php`, wird von
allen Token-Links inkl. Einladung gemeinsam genutzt) -- keine Änderung nötig, nur verifiziert.
`php -l` + `php tests/run.php` (77/77) grün.

---

## 2026-08-03 (21) — Claude Code — Claude Sonnet 5
**Auftrag:** Nach einer echten Support-Anfrage zwei Wünsche: (1) beim Support-Icon in der
Obmann-Sidebar einen roten Punkt mit Zahl für ungelesene Nachrichten (E-Mail-Benachrichtigung
funktioniert schon, aber ohne Portal-Übersicht weiß man nicht, was neu ist). (2) Im Dark Mode
ist beim Support ein Feld inkl. Schrift weiß -- unlesbar. Bitte auch die gesamte Plattform auf
Kontrastprobleme im Dark Mode prüfen.
**Ergebnis:** (1) Neue Spalte `support_tickets.manager_read_at` (Migration
`database/migrate_20260824.sql`), gesetzt beim Öffnen der Ticket-Detailseite
(`GET /portal/support/:id`). Sidebar-Badge zeigt jetzt die Anzahl ungelesener
Mitglieder-Nachrichten (nicht mehr nur "offene Tickets") in Rot statt Gelb; zusätzlich ein
kleiner roter Punkt je Ticket mit ungelesener Nachricht in der Übersichtsliste
(`support_tickets.php`). (2) Ursache gefunden: die Nachrichten-Blase für Mitglieder-Nachrichten
in `my_support_detail.php`/`support_ticket_detail.php` hatte `background:#eff6ff` fix im PHP
verdrahtet, ohne eigene Textfarbe -- im Dark Mode erbte der Text die fast weiße
Standard-Body-Farbe, landete aber weiterhin auf hellem Hintergrund. Neue CSS-Klasse
`.msg-bubble-member` in `app.css` mit eigener `[data-theme="dark"]`-Variante (Blau-Schema wie
`.btn-tint-blue`) statt Inline-Hex. Danach systematisch die ganze Plattform nach demselben
Muster durchsucht (`grep` auf hartcodierte `background:#...` in allen Views) -- keine weiteren
echten Bugs gefunden, alle übrigen Treffer sind entweder dekorativ (Fortschrittsbalken ohne
Text) oder bewusst theme-unabhängig (Unterschrift-Canvas, PDF/Druck-Vorlagen wie Verträge/
Jahresübersicht, E-Mail-Vorschau in `admin_mail_settings.php`, die einen echten Mail-Client
simuliert -- dort ist ein fixes Weiß korrekt). `php -l` + `php tests/run.php` (77/77) grün.

---

## 2026-08-03 (20) — Claude Code — Claude Sonnet 5
**Auftrag:** Ob die neu bestellten ESP32-Boards von den Hardwareanforderungen her passen (Log
mit Flash-/RAM-Auslastung + Chip-Erkennung geteilt). Dann: OTA-Netzwerkport wird in der
Arduino-IDE trotz vorherigem `WiFi.setSleep(false)`-Fix weiterhin nicht angezeigt, obwohl das
Gerät im selben Subnetz erreichbar ist.
**Ergebnis:** Chip/CPU/WLAN/RAM passen (ESP32-D0WD-V3, Dual-Core, 240 MHz, RAM nur 16 %
ausgelastet). Flash-Auslastung von 88 % bezog sich nur auf das gewählte Partition Scheme, nicht
den physischen Chip -- per `esptool flash_id` echte Chipgröße geprüft: tatsächlich 4 MB (kein
Fehlkauf, aber auch kein Upgrade). Da die Firmware kein SPIFFS/LittleFS/FFat nutzt, Partition
Scheme auf "Minimal SPIFFS (1.9MB APP with OTA/190KB SPIFFS)" umgestellt -- kostenlos mehr
App-Platz (1,25→1,9 MB), Auslastung sank auf 59 %. Patrick hat sich bewusst für die 4-MB-Boards
entschieden (reicht für den geplanten Umfang inkl. möglicher RGB-Fehleranzeige), kein
Hardware-Wechsel nötig. OTA-Problem: `ping p1-smartmeter.local` + `dns-sd -B _arduino._tcp`
zeigten mDNS funktioniert einwandfrei (keine Firewall-/Client-Isolations-Ursache wie zuerst
vermutet) -- aber `dns-sd` fand GLEICHZEITIG `p1-smartmeter` UND `p1-smartmeter-2`: der
OTA-Hostname war in der Firmware für jedes Gerät identisch hart codiert, im Gegensatz zum
Setup-AP-Namen (`P1-Setup-XXXX`), der schon immer korrekt per Chip-MAC eindeutig war -- fällt
erst beim gleichzeitigen Testen mehrerer physischer Boards auf. Fix in
`esp32-firmware/p1-smart-meter/sketch_ESP32_P1_Smart_Meter.ino`: OTA-Hostname jetzt ebenfalls
`p1-smartmeter-XXXX` (Chip-MAC-Suffix). README/CHANGELOG/ESP_IDEEN.md aktualisiert. Kein
PHP-Testlauf nötig (reine Firmware-Änderung), `.ino`-Klammernbalance geprüft.

---

## 2026-07-30 (19) — Claude Code — Claude Sonnet 5
**Auftrag:** Drei Erweiterungswünsche für die Mitgliederverwaltung: (1) Da bei Patricks Verein
Verträge weggelassen werden (die Beitrittserklärung deckt AGB/Datenschutz/Preisliste/Statuten
bereits ab), soll die Mitgliederliste statt Bezugs-/Einspeisevertrag-Status die Zählpunktnummer
für Bezug bzw. Einspeisung (letzte 8 Stellen) zeigen. (2) Beim manuellen Anlegen eines Mitglieds
(z.&nbsp;B. aus einer offline unterschriebenen Beitrittserklärung) sollen gleich optional
Zählpunkte mit Details (Jahresverbrauch, PV-Leistung, geplante Einspeisung) angelegt werden
können, statt das immer erst nachträglich zu tun. (3) Anzeige, wann sich ein Mitglied zuletzt
eingeloggt hat (oder "noch nicht angemeldet"), um zu erkennen, ob ein neu angelegter Zugang
überhaupt schon benutzt wurde.
**Ergebnis:** (1) Mitgliederliste (`member_list.php`) zeigt die ZP-Spalten nur, wenn
`contracts_enabled` für die jeweilige EEG aus ist (bestehender Schalter) -- EEGs mit aktiven
Verträgen sehen unverändert den Vertragsstatus. (2) Neue optionale "Zählpunkte"-Sektion im
Anlage-Formular (`member_form.php`, zwei Checkboxen Bezug/Einspeisung mit Feldern), serverseitig
in POST /portal/members validiert (Pflichtfeld bei aktivierter Checkbox, Duplikat-Prüfung,
Bezug≠Einspeisung) und über neue Helper-Funktion `createMeteringPointForMember()` angelegt --
nutzt dieselbe `notifyMeterCodeShared()`-Logik wie das reguläre Hinzufügen, falls beide
Richtungen dieselbe Zählernummer bekommen (Prosumer). (3) `users.last_login_at` wurde bereits bei
jedem Login geschrieben (`Auth.php`), war aber nirgends sichtbar -- jetzt neue Spalte in der
Mitgliederliste und Badge auf der Detailseite. Keine Schemaänderungen nötig (alle verwendeten
Spalten existierten schon). `php -l` + `php tests/run.php` (77/77) grün. UI konnte in dieser
Umgebung nicht live getestet werden (kein Docker-Daemon verfügbar, nur Code-Review + Lint/Tests).

---

## 2026-07-30 (18) — Claude Code — Claude Sonnet 5
**Auftrag:** Nachgefragt, warum über die Microsoft-Graph-Anbindung verschickte E-Mails im
Postfach nicht als gesendet zu sehen sind -- ob dafür noch etwas konfiguriert werden muss.
**Ergebnis:** Ursache gefunden und auf Wunsch behoben: `Mailer::send()`
(`webapp/src/Mailer.php`) rief den Graph-`sendMail`-Endpunkt bisher mit
`saveToSentItems: false` auf -- rein codeseitig, keine fehlende Konfiguration in
Azure/Outlook. Auf `true` umgestellt, damit alle über die Plattform verschickten Mails im
"Gesendete Elemente"-Ordner der konfigurierten `sender_address` erscheinen. `php -l` +
`php tests/run.php` (77/77) grün.

---

## 2026-07-30 (17) — Claude Code — Claude Sonnet 5
**Auftrag:** Korrektur zum vorigen Eintrag (16): Der Zählpunkt-Typ "prosumer" (ein Zähler, beide
Richtungen kombiniert) bildet die Realität nicht ab. In Österreich haben Bezug und Einspeisung
eines Prosumers unterschiedliche, offizielle Zählpunktnummern (AT...), teilen sich aber
denselben physischen Zähler/dieselbe Zählernummer und dieselbe ESP-Ausleseeinheit. Gewünscht:
dieselbe Zählernummer darf auf zwei aktiven Zählpunkten (Bezug + Einspeisung) stehen, statt das
zu blockieren -- mit einer kurzen, nicht blockierenden Postfach-Meldung, dass die ESP-Daten
dabei intern korrekt aufgeteilt und nur einmal verarbeitet werden.
**Ergebnis:** Hard-Block (aus Sitzung 16) entfernt. `mqtt-subscriber/main.py`:
`get_metering_point_uuid()` → `get_metering_points()`, liefert jetzt alle aktiven Zählpunkte zu
einer Zählernummer als Liste (normalerweise 1, bei einem Prosumer-Zählerpaar 2);
`insert_measurement()` bekommt den jeweiligen Zählpunkt-Typ und setzt pro Zeile die nicht
zutreffende Richtung auf 0, damit beim Aufsummieren über die Zählpunkte eines Mitglieds keine
Seite doppelt gezählt wird; `on_message()` verarbeitet Status- und Live-Nachrichten jetzt für
alle passenden Zählpunkte statt nur für einen. PHP-seitig (`webapp/public/index.php`): neue
Funktion `notifyMeterCodeShared()` (Postfach-Eintrag, Dedup nach Zählernummer) ersetzt den
Hard-Block sowohl beim Anlegen als auch beim Bearbeiten eines Zählpunkts; toter
`meter_duplicate`-Fehlerblock in `member_detail.php` entfernt. `prosumer`-Typ bleibt als Option
für den seltenen echten Einzelzählpunkt-Fall verfügbar, wird aber nicht mehr als Lösung für das
Zwei-Zählpunkte-ein-Zähler-Szenario dargestellt. `CHANGELOG.md` und `docs/ESP_IDEEN.md` mit
"Nach Rückfrage korrigiert"-Vermerken aktualisiert. `php -l` + `php tests/run.php` (77/77) +
`python3 -m py_compile` grün.

---

## 2026-07-30 (16) — Claude Code — Claude Sonnet 5
**Auftrag:** Nachgefragt, ob die ESP-Zählung schon berücksichtigt, dass ein Zähler zwei
Zählpunktnummern (Bezug + Einspeisung) haben kann. Gewünscht: Hinweistext auf den Live-Anzeigen,
wenn nicht alle ESP online sind ("Daten können leicht abweichen"), Text dafür formulieren.
Neues Pre-Launch-Hinweis-Popup beim Mitglieder-Login: verschwommener Hintergrund, "Gelesen"-
Button, Link zum Support für Vorschläge, bei jedem Login erneut, erst nach Bestätigung sieht
man die Seite.
**Ergebnis:** Frage bestätigt einen echten Bug: `get_metering_point_uuid()` im
mqtt-subscriber liefert bei zwei Zählpunkten mit derselben Zählernummer nur EINEN zurück (kein
ORDER BY/Eindeutigkeit) -- der andere bekommt nie Live-Daten. Typ "prosumer" existierte im
Datenmodell bereits, war aber im normalen Zählpunkt-Formular nicht wählbar (nur über
"Zählpunkte ohne Zuordnung"). Jetzt ergänzt + serverseitige Validierung gegen doppelt vergebene
Zählernummern. Live-Dashboards (Obmann + öffentlich) zeigen jetzt einen Hinweistext, sobald
`active_meters < total_meters`. Pre-Launch-Popup umgesetzt: Session-Flag `prelaunch_ack`, wird
in `Auth::establishSession()` bei jedem Login zurückgesetzt (nicht nur bei neuer Browser-
Session), neue Route `/portal/ack-prelaunch`, Hintergrund per CSS-Blur + `pointer-events:none`
auf `.portal-layout` deaktiviert, nur für die Mitglieder-Ansicht (nicht Obmänner/Platform-
Admins). `php tests/run.php` weiterhin 77/77.

---

## 2026-07-30 (15) — Claude Code — Claude Sonnet 5
**Auftrag:** Live-Leistungs-/Energiewerte sollen sich alle 5 Sekunden selbst aktualisieren (nur
die betroffenen Werte, kein Seiten-Reload) -- auch im Mitglieder-Bereich. Beim Testen des neuen
"Live-Messdaten zurücksetzen"-Buttons kam ein Fehler: SQLSTATE[23502] Not-Null-Verletzung auf
`meter_reachable`. Frage: was genau wird beim Reset gelöscht -- auch Zählernummer/Zählpunktnummer
des Mitglieds, oder nur diese eine Tabelle?
**Ergebnis:** Crash-Fix: `meter_reachable` ist NOT NULL DEFAULT false (`migrate_20260820.sql`) --
der Reset setzte es fälschlich auf NULL statt `false`, jetzt korrigiert. Neue JSON-Endpunkte
`/portal/api/current-power` (Mitglied) und `/portal/api/live-power` (Obmann, neue gemeinsame
Funktion `communityLivePower()` statt doppelter SQL), beide Dashboards pollen jetzt per
`fetch()` alle 5s und schreiben nur die "Aktuelle Leistung"/"Live-Leistung"-Kachel neu.
Öffentliches Live-Dashboard (`/live`) hatte Polling schon, Intervall von 10s auf 5s verkürzt.
Antwort zur Lösch-Frage im Chat: der Reset löscht nur `esp_measurements`-Zeilen und setzt reine
Status-/Live-Spalten auf `metering_points` zurück (esp_online, esp_last_seen_at,
meter_reachable, meter_last_seen_at, wifi_ssid, wifi_ip, wifi_password_enc) -- Zählpunktnummer,
Zählernummer und alle übrigen Stammdaten des Zählpunkts/Mitglieds bleiben unangetastet.
`php tests/run.php` weiterhin 77/77.

---

## 2026-07-30 (14) — Claude Code — Claude Sonnet 5
**Auftrag:** Bestellung von 20 ELEGOO-ESP32-Boards angefragt (kann Claude Code nicht ausführen --
kein Checkout-/Kauf-Zugriff). Live-Anzeige: "Erzeugung heute" zeigt nicht die tatsächlich
eingespeisten 1.000 kWh. Neue Funktion gewünscht: pro Mitglied alle Live-ESP-Messdaten aus der
DB löschen können (Testphase-Reset), nicht plattformweit auf einmal. Im Mitglieder-Dashboard
den Tausenderpunkt bei den Verbrauchszahlen entfernen (verwirrend, "2.000" vs. "2,0").
**Ergebnis:** Bestellung ehrlich abgelehnt -- Claude Code hat keine Möglichkeit, echte Käufe/
Zahlungen auszulösen, nur der Produkt-Link wurde nochmal genannt. Root Cause für "Erzeugung
heute" gefunden: `/api/live/:slug` nahm die ERSTE Messung des Tages als Basiswert -- bei wenigen
Testmessungen "heute" ergab das MAX=MIN=0. Fix: Basiswert ist jetzt die letzte Messung VOR
heute. Neuer Button "Live-Messdaten zurücksetzen (Testphase)" bei den Zählpunkten eines
Mitglieds (`/portal/members/:id/reset-live-data`), nur im Testmodus sichtbar, löscht
`esp_measurements` + WLAN-/Online-Status ausschließlich für die Zählpunkte dieses einen
Mitglieds, mit Bestätigungsdialog + Audit-Log. Tausenderpunkt bei allen kWh/W-Anzeigen im
Mitglieder-Dashboard entfernt (Euro-Beträge unverändert mit Punkt). `php tests/run.php`
weiterhin 77/77.

---

## 2026-07-30 (13) — Claude Code — Claude Sonnet 5
**Auftrag:** Korrektur zur Live-Einspeisung-Berechnung: IMMER zuerst je Viertelstunden-Fenster
matchen (wie viel Gemeinschaft/wie viel Netz), erst danach über den gewählten Zeitraum
aufsummieren -- sonst würde z. B. an einem Sonnentag die tagsüber hohe Einspeisung den
nächtlichen Netzbezug rechnerisch "ausgleichen". Für "Aktuelle Leistung" (jetzt) reicht einfaches
Summieren/Differenzbilden. Für Zeiträume lieber über die ohnehin gespeicherten
Viertelstunden-Zählerstände (Energie, Differenzbildung) statt Momentanleistung rechnen.
Außerdem: 10 ESP32-Produkte mit USB-C von verschiedenen Shops (Amazon, Reichelt, RS Components
etc.), 4 MB und 8 MB Flash, heraussuchen.
**Ergebnis:** Geprüft: das Fenster-Matching (`min()` je Bucket vor der Summierung) war bereits
korrekt umgesetzt, das aber transparent anhand eines Tag/Nacht-Beispiels bestätigt/erklärt.
`ownEinspeisungInGemeinschaftKwh()` trotzdem verbessert: rechnet jetzt mit der Differenz der
kumulativen Registerstände (`energy_bezug_wh`/`energy_einspeisung_wh`, DISTINCT-ON + LAG() je
Fenster) statt gemittelter Momentanleistung -- exakter und robust gegenüber ESP-Ausfällen/
Datenlücken. `php tests/run.php` weiterhin 77/77 (kein DB-Zugriff zum Testen der neuen SQL in
dieser Umgebung). Produktrecherche zu ESP32-USB-C-Boards mit 4/8 MB Flash von mehreren Shops
als separate Antwort (kein Code).

---

## 2026-07-30 (12) — Claude Code — Claude Sonnet 5
**Auftrag:** Mitglieder-Dashboard erweitern: Mitglieder mit Bezug UND Einspeisung sollen beides
sehen, reine Einspeiser eine Kennzahl "Einspeisung in die Gemeinschaft" in kWh -- selbst aus den
ESP-Leistungsdaten berechnet (Viertelstunden-Fenster wie beim amtlichen Zählwesen), nicht aus
dem EDA-Portal. Zusätzlich ein Fenster "Aktuelle Leistung" (positiv = Bezug, negativ =
Einspeisung). Rückfrage vorab: da die Plattform laut `docs/AUFTEILUNGSSCHLUESSEL.md` bewusst
KEINEN eigenen Aufteilungsschlüssel berechnet (Netzbetreiber ist gesetzlich zuständig, sonst
"zwei Wahrheiten"), gefragt, ob die neue Zahl die EDA-Kachel ersetzen oder nur ergänzen soll
(Patrick: nur ergänzend, EDA bleibt Basis für die Rechnung) sowie über welchen Zeitraum
(Patrick: frei wählbar von letzter Stunde bis Jahr, plus bestimmter Tag/Zeitraum).
**Ergebnis:** Neue Funktionen `memberCurrentNetPowerW()` und `ownEinspeisungInGemeinschaftKwh()`
in `public/index.php` (DB-Zugriff, daher dort statt in `functions.php`). Mitglieder-Dashboard:
Kachel "Aktuelle Leistung" (neuester ESP-Wert je Zählpunkt, kein "Live-Daten fehlen"/0-W-
Verwechslung dank explizitem null-Rückgabewert) sowie für Erzeuger/Prosumer eine Kachel
"Einspeisung in die Gemeinschaft (Live-Schätzung)" mit Zeitraum-Dropdown (1/3/6/12/24h, heute,
Woche/Monat/Jahr, bestimmter Tag, bestimmter Zeitraum) -- proportionale Aufteilung von
min(Gesamt-Bezug, Gesamt-Einspeisung) je 15-Min-Fenster nach eigenem Erzeugungsanteil. Bestehende
EDA-Monatswerte unverändert als Abrechnungs-Grundlage belassen, neue Zahl klar als
"(Live-Schätzung)" gekennzeichnet. `docs/AUFTEILUNGSSCHLUESSEL.md` um Abgrenzungs-Absatz
ergänzt, damit der Grundsatz (keine eigene amtliche Berechnung) dokumentiert nachvollziehbar
bleibt. Bekannte Grenze dokumentiert: sehr lange Zeiträume (Jahr) könnten ohne Caching langsam
werden. `php tests/run.php` weiterhin 77/77.

---

## 2026-07-30 (11) — Claude Code — Claude Sonnet 5
**Auftrag:** OTA-Update auf dem ESP32 funktioniert nicht, der Netzwerk-Port wird in der
Arduino-IDE nicht angezeigt -- Code daraufhin prüfen, ob OTA (Hostname/Passwort etc.) richtig
eingerichtet ist. Außerdem aus dem mitgeschickten Arduino-Upload-Log (Sketch/RAM-Größen,
Chip-Erkennung) die Hardware-Empfehlung nochmal in 1-2 Sätzen bestätigen.
**Ergebnis:** `ArduinoOTA`-Setup selbst war korrekt (Hostname/Passwort/Callbacks/`begin()`/
`handle()` alle vorhanden) -- kein Konfigurationsfehler. Wahrscheinlichste Ursache: ESP32-
Modem-Sleep (standardmäßig aktiv) verzögert/verwirft eingehende mDNS-Multicast-Pakete, worauf
die IDE-Port-Erkennung basiert. Fix: `WiFi.setSleep(false)` nach `WiFi.mode(WIFI_STA)` in
`connectSTA()` ergänzt. README um Troubleshooting-Absatz (gleiches Subnetz/keine Client-
Isolation prüfen, `espota.py`-Fallback per IP) erweitert. Aus dem Log bestätigt: Chip ist
ESP32-D0WD-V3 (Dual-Core, WiFi+BT, kein PSRAM) wie erwartet; Flash-Nutzung 1.160.540 von
1.310.720 Bytes (88 %) im 4-MB-OTA-Partitionsschema, RAM 55.560 von 327.680 Bytes (16 %) --
RAM hat viel Luft, der Flash-Slot wird für künftige Firmware-Erweiterungen aber langsam eng.
`.ino`-Klammernbalance geprüft, `php tests/run.php` weiterhin 77/77 (unberührt).

---

## 2026-07-30 (10) — Claude Code — Claude Sonnet 5
**Auftrag:** Auf der Mitgliederseite ("beim Männchen") eine Zahl anzeigen, wie viele Mitglieder
gerade ein Problem haben (Zähler nicht erreichbar / ESP offline); beim Postfach ebenfalls ein
roter Kreis mit der Anzahl; in der Mitgliederliste danach sortieren können. Außerdem Details zu
Prozessor/Speicher des ESP32 für eine geplante Serienbestellung.
**Ergebnis:** Roter Badge auf dem "Mitglieder"-Sidebar-Link mit Live-Anzahl der Mitglieder mit
ESP-/Zähler-Fehler (gleiche Schwellen-Logik wie die Status-Kachelzeile im Obmann-Dashboard,
kein "gelesen"-Status nötig, da Live-Zustand). Neue Spalte "Zähler" in der Mitgliederliste
(rot/grün/grau) mit eigenem Filter + Sortierung. Postfach-Badge von Gelb auf Rot umgestellt.
Nebenbei einen bereits vorhandenen `icon('warning')`-Tippfehler (Symbol existiert nicht im
Sprite, sollte `warning-circle` heißen) im Obmann-Dashboard und in der neuen Spalte behoben.
`php tests/run.php` weiterhin 77/77. Hardware-Beratung zur Serienfertigung nur als Chat-Antwort
(Prozessor/RAM/Flash-Empfehlung für Bestellung), keine Code-Änderung dafür.

---

## 2026-07-30 (9) — Claude Code — Claude Sonnet 5
**Auftrag:** Live-Leistungswerte über die API (`GET /api/v1/live`, per Node-RED ausgelesen)
waren absurd hoch (70 kW statt tatsächlicher 5,8 kW Einspeisung), obwohl ESP-Webinterface und
Loxone korrekt waren -- bitte kontrollieren. Außerdem WLAN-Passwort-Sendefrequenz nochmal
überarbeiten: nur bei echtem WLAN-Wechsel und einmal pro Boot senden statt bei jedem
Heartbeat, dabei weiterhin auf SSID-Wechsel überwachen (Meldung), aber NICHT auf reine
IP-Adressänderungen (die passieren routinemäßig per DHCP).
**Ergebnis:** Root Cause gefunden: drei Stellen (`/api/v1/live`, `/api/live/:slug`,
`manager_dashboard.php`) summierten `power_*_w` über ALLE Messzeilen eines 2-Minuten-Fensters
statt nur die neueste Zeile je Zählpunkt zu nehmen -- bei 5s-Sendeintervall bis zu ~24x zu
hoch. Fix mit `DISTINCT ON (metering_point_id)`, dazu im Live-Dashboard zusätzlich die
Tageskennzahl (Max-Min pro Zähler statt zeilenweise Summe) und die Zeitreihen-Chart-Daten
(erst pro Bucket/Zähler mitteln, dann summieren) korrigiert. Firmware: `wifi_password` aus dem
periodischen Heartbeat entfernt, wird jetzt nur noch beim MQTT-(Re-)Connect gesendet (deckt
Boot + WLAN-Wechsel ab, da Wechsel immer `ESP.restart()` auslöst). `mqtt-subscriber`: neue
Benachrichtigung bei echtem SSID-Wechsel (`typ = 'ssid_geaendert'`), keine Meldung bei reiner
IP-Änderung. `php tests/run.php` weiterhin 77/77, `.ino`-Klammernbalance und Python-Syntax
geprüft. CHANGELOG.md und docs/ESP_IDEEN.md aktualisiert.

---

## 2026-07-30 (8) — Claude Code — Claude Sonnet 5
**Auftrag:** BC547-Transistor aus dem ESP32-Aufbau entfernt, Firmware entsprechend anpassen;
korrigierten `mosquitto_sub`-Befehl mit Zugangsdaten für den MQTT-Broker; welches Protokoll
bei der Fritzbox-Portfreigabe (TCP/UDP/ESP/GRE).
**Ergebnis:** `P1Serial.begin()` auf `invert=true` umgestellt (der Transistor hat das Signal
invertiert, das übernimmt jetzt die Software) -- gleichzeitig klargestellt, dass der
Transistor auch die Pegelanpassung übernommen hat und das noch unverifiziert ist, ob die
P1-Schnittstelle ohne ihn eine für den ESP32-GPIO unbedenkliche Spannung liefert (Warnhinweis
in Sketch + README + `docs/ESP_IDEEN.md`). MQTT-Zugangsdaten stehen in `.env` auf dem Server
(`grep MQTT_ .env`) -- da der vorherige Setup-Lauf wegen `set -euo pipefail` vor der
Ausgabe des generierten Passworts abgebrochen ist, wurden sie dem Nutzer nie angezeigt.
Portfreigabe: TCP (MQTT läuft immer über TCP, auch mit TLS).

---

## 2026-07-30 (6) — Claude Code — Claude Sonnet 5
**Auftrag:** Nach dem Ausführen von `scripts/mqtt_secure_setup.sh` startete der
Mosquitto-Container nicht mehr (unhealthy) -- Produktions-Ausfall des MQTT-Brokers.
**Ergebnis:** Ursache gefunden: `server.key` wurde `root:root` mit `chmod 600` angelegt, der
`eclipse-mosquitto`-Container läuft aber als eigener, nicht-root User und konnte den privaten
Schlüssel nicht lesen -- Mosquitto behandelt einen Listener-Fehler als fatal, der gesamte
Broker startete deshalb gar nicht erst. Sofort-Fix per `chmod 644` durchgegeben, Skript für
künftige Läufe korrigiert und gemerged. Broker danach wieder erreichbar.

---

## 2026-07-30 (7) — Claude Code — Claude Sonnet 5
**Auftrag:** Bestätigung, dass der geplante Weg für externen MQTT-Zugriff (Fritzbox →
pfSense → Raspberry Pi direkt, ohne über den nginx-Proxy zu laufen) richtig ist; Wunsch nach
einer konfigurierbaren ESP-Offline-Schwelle (Minuten ohne Meldung, bis ein Gerät als offline
gilt) und einer klarer strukturierten Einstellungsseite (ESP32/Ausleseeinheit getrennt von
anderen Plattform-Daten).
**Ergebnis:** Netzwerk-Plan in CLAUDE.md/obsidian/Infrastruktur.md bestätigt/dokumentiert.
Neue Einstellung `esp_offline_after_minutes` (Standard 5, `migrate_20260823.sql`) --
`espOfflineAfterMinutes()` in index.php, verwendet in `member_detail.php` und
`manager_dashboard.php` statt des bisherigen reinen `esp_online`-Flags ohne Zeitbezug.
Platform-Admin-Einstellungsseite (`/admin/mail-settings`) jetzt in „Plattform-Technik"
(Testmodus, neu: ESP32/Ausleseeinheiten) und „E-Mail (Microsoft Graph)" unterteilt. Alle 77
Tests weiterhin grün.

---

## 2026-07-30 (5) — Claude Code — Claude Sonnet 5
**Auftrag:** TLS + Benutzername/Passwort auf dem MQTT-Broker einrichten; das eigene
Test-ESP32 liefert bereits Live-Werte, aber die kommen nur alle 30s (Heartbeat-Intervall,
sollte vom Live-Daten-Intervall getrennt und auf ~5s einstellbar sein); WLAN-Passwort fehlt
in der WLAN-Info-Anzeige (SSID/IP kommen an, Passwort nicht).
**Ergebnis:** `scripts/mqtt_secure_setup.sh` (neu, automatisch von `scripts/setup.sh`
mitaufgerufen) erzeugt ein selbstsigniertes Zertifikat + Zugangsdaten, Mosquitto verlangt
jetzt beides auf beiden Ports (1883 + 8883/TLS). ESP32-Firmware wählt automatisch
`WiFiClientSecure`, sobald Port 8883 konfiguriert ist. Neues, vom Heartbeat unabhängiges
`live-daten-intervall`-Feld (Standard 5s statt fix 30s). WLAN-Passwort-Bug gefunden und
behoben: wurde nur einmalig beim allerersten MQTT-Connect mitgeschickt statt bei jedem
Heartbeat -- genau der verpasste Moment war beim Testgerät die Ursache. Alle 77 Tests
weiterhin grün, Python-/Bash-/YAML-Syntax geprüft.

---

## 2026-07-30 (4) — Claude Code — Claude Sonnet 5
**Auftrag:** Nachfrage, ob die früher gewünschte Benachrichtigung bei unbekannter
Zählernummer tatsächlich umgesetzt ist (Patrick hat testweise einen ESP32 eingerichtet und
nichts davon gesehen); außerdem wo die WLAN-Diagnoseinfos (SSID/IP) in der Plattform zu
finden sind und ob/wie sich der MQTT-Broker über die eigene Domain statt nur im lokalen
Netz erreichen lässt.
**Ergebnis:** Bestätigt und behoben -- die Meldung war nie als sichtbare Benachrichtigung
umgesetzt, nur als Log-Zeile im mqtt-subscriber-Container. Erzeugt jetzt eine offene
Meldung im Postfach (`/portal/postfach`), eine je unbekannter Zählernummer. Im Chat erklärt:
WLAN-Info-Anzeige existiert bereits (Mitglied-Detailseite), erscheint aber erst, sobald ein
Zählpunkt mit passender Zählernummer angelegt ist -- vorher werden die WLAN-Daten mangels
Zuordnung gar nicht gespeichert. Architektur-Erklärung, dass die Domain aktuell NICHT zum
MQTT-Broker durchroutet (nginx-Proxy terminiert nur HTTP/HTTPS, andere Maschine als der
EEG-Server) und dass ein externer Zugriff (nötig für Mitglieder-ESP32s außerhalb des
eigenen Netzes) einen Router-Port-Forward plus TLS/Authentifizierung auf Mosquitto
voraussetzen würde (aktuell `allow_anonymous true`, kein TLS aktiv) -- noch nicht
umgesetzt, offen für eine spätere Session.
**Auftrag:** Vier gemeldete Probleme aus dem laufenden Betrieb: fehlendes Icon beim neuen
Support-Menüpunkt, Wunsch nach einer beim Scrollen fixierten Sidebar/Kopfzeile, ein im Dark
Mode unlesbarer Code-Schnipsel in der hellblauen Info-Box unter „API-Zugänge", sowie die
Bitte um eine Anleitung, was jetzt auf den ESP32 hochgeladen werden muss (inkl. Rückfrage,
ob der Zählernummer-Abgleich vor dem Senden tatsächlich stattfindet).
**Ergebnis:** `icon()` hängt jetzt einen `filemtime()`-Cache-Buster an die Sprite-URL (Ursache
des fehlenden Icons: Browser lieferten die alte, gecachte Sprite-Datei ohne das neue Symbol
weiter aus). Sidebar ist jetzt `position:sticky` (Desktop), mobile Icon-Leiste ausgenommen.
Code-Tags in der API-Zugänge-Info-Box bekommen ein festes helles Inline-Styling, das die
globale Dark-Mode-Regel überstimmt. In der Firmware fehlte tatsächlich ein Abgleich: die
Plattform ordnete Daten bisher nur nach der manuell konfigurierten Zählernummer zu, ohne sie
gegen die im P1-Telegramm gelesene zu prüfen -- jetzt wird bei Abweichung nicht gesendet.
Ausführliche ESP32-Setup-/Test-Anleitung (Board, MQTT-Zugangsdaten, Ablauf der Pipeline,
Vorschlag für einen Test-Zählpunkt zum Simulieren von Echtzeitdaten ohne Hardware) im Chat
beantwortet. Alle 77 Tests weiterhin grün.

---

## 2026-07-30 (2) — Claude Code — Claude Sonnet 5
**Auftrag:** Korrektur "ESB" → "ESP" (die Ausleseeinheit heißt ESP32, nicht ESB) in Spalten,
Code und Doku; ein Support-Ticket-System für Mitglieder (statt E-Mail-Verkehr, mit
Benachrichtigung an eine konfigurierbare Adresse); Review der ESP32-Firmware, damit die
bereits vorbereiteten WLAN-Diagnosefelder tatsächlich mitgeschickt werden; zusätzlich
Erfassung, ob der Smart Meter für den ESP erreichbar ist (zur Unterscheidung von
Inselbetrieb/Stromausfall beim Mitglied gegenüber einem Plattform-/ESP-Problem).
**Ergebnis:** `esb_online`/`esb_last_seen_at` → `esp_online`/`esp_last_seen_at` (rename-sicher
in `migrate_20260817.sql`), `docs/ESB_IDEEN.md` → `docs/ESP_IDEEN.md`, alle Code-/UI-Referenzen
angepasst. Firmware schickt jetzt SSID/IP (jeder Heartbeat) und WLAN-Passwort (bei Boot/
Reconnect) sowie `meter_ok` mit; neue Spalten `meter_reachable`/`meter_last_seen_at`
(`migrate_20260820.sql`), Anzeige in `member_detail.php` und Manager-Dashboard. Neues
Support-Ticket-System (`support_tickets`/`support_ticket_messages`, `migrate_20260821.sql`,
RLS) unter `/portal/my/support` (Mitglied) und `/portal/support` (Manager/Platform-Admin),
konfigurierbare Benachrichtigungsadresse in den E-Mail-Einstellungen (Standard
`office@stromfueralle.at`). Alle 77 Tests weiterhin grün.

---

## 2026-07-30 — Claude Code — Claude Sonnet 5
**Auftrag:** Eigenes Hero-Banner-Foto statt der SVG-Landschafts-Illustration auf der
Startseite, hochladbar unter Admin → Dateien, mit Zuschneiden auf exakt Ziel-Breite/-Höhe
(analog zum bestehenden Profilbild-Zuschnitt). Außerdem Frage nach den einzuspielenden
Migrationen sowie ein akuter Produktions-Bug (Login bricht mit „column esb_last_seen_at
does not exist" ab, weil eine Migration noch nicht eingespielt war).
**Ergebnis:** Neuer Registry-Eintrag `hero-banner.png` unter `/admin/templates` (gleiche
Custom-Datei-mit-Fallback-Logik wie Logo), neue Zuschneiden-Oberfläche `rect-crop.js`
(Schwester-Skript zu `avatar-crop.js`, aber für beliebiges Ziel-Seitenverhältnis statt nur
quadratisch) mit Zoom/Verschieben auf 1600×640, dynamische Auslieferung über
`/hero-banner-image`. Ohne eigenes Foto bleibt die SVG-Illustration unverändert. Keine neue
Migration nötig. Dem Nutzer die drei ausstehenden Migrationen (`migrate_20260817.sql`,
`migrate_20260818.sql`, `migrate_20260819.sql`) benannt und den Login-Fehler auf die fehlende
`esb_last_seen_at`-Spalte aus `migrate_20260817.sql` zurückgeführt.

---

## 2026-07-29 (Phase 2) — Claude Code — Claude Sonnet 5
**Auftrag:** Fortsetzung des Emoji-Sweeps (Phase 1 war nur die Startseite) über die
restliche Anwendung — Mitgliederverwaltung, Abrechnung, Dateien, Beitritts-/Vertragsflow,
kompletter Admin-Bereich —, jeweils mit Zwischenstand nach jedem Batch, jederzeit
rückgängig machbar.
**Ergebnis:** Alle verbleibenden ~190 Emoji-Vorkommen in ~38 Dateien durch `icon()`
ersetzt, in 6 committeten Batches (Sidebar-Layout, Mitgliederverwaltung, Abrechnung/
Rechnungen, Dateien/Dokumente, Beitritt/Verträge, Admin-Bereich, restliche Einzelseiten).
Sprite um 17 weitere Icons ergänzt (wrench, paperclip, folder-open, note-pencil,
download-simple, globe, x, puzzle-piece, sign-out, eye, device-mobile, laptop, image,
calculator, plus, check, hourglass, flask). Zwei Sonderfälle bewusst anders gelöst:
`<option>`-Werte in `<select>` bleiben reiner Text (kein Markup-Rendering dort möglich);
die client-seitige IBAN-Live-Validierung und die Upload-Statusanzeige nutzen jetzt
dieselbe SVG-Sprite-Referenz per `innerHTML` (mit Escaping) statt Emoji. Eine bewusste
Ausnahme bleibt: das ⚠️-Symbol im Markdown-Export des Audit-Logs (reiner Textexport,
kein HTML). Alle 73 Tests durchgehend grün, nach jedem Batch committet auf
`claude/stromfueralle-footer-pages-trqb5c`. Damit ist der komplette Emoji-Sweep
(Phase 1 + 2) abgeschlossen.

## 2026-07-29 — Claude Code — Claude Sonnet 5
**Auftrag:** Vor jeder Änderung committen/sicherbar machen; passende, hochwertige
Animationsbibliotheken (z. B. Motion/GSAP) für dezente Übergänge/Hover-/Scroll-Effekte
vorschlagen; alle generischen Emojis durch ein einheitliches, professionelles Icon-Set
(z. B. Phosphor Icons) ersetzen; schrittweise mit Vorschau nach jedem größeren Schritt,
jede Änderung einzeln rückgängig machbar.
**Ergebnis:** Phase 1 (Startseite + gemeinsamer Theme-Toggle): selbst gehostetes
Phosphor-Icon-Sprite (`webapp/public/assets/icons/phosphor-sprite.svg`) + neuer Helfer
`icon()` in `functions.php` (3 neue Tests), ersetzt Emojis im Theme-Toggle, Sidebar-Menü
und auf der Startseite (7 Icons). GSAP 3.12.5 + ScrollTrigger self-hosted unter
`assets/js/vendor/` (kein CDN), neues `site-animations.js` blendet den Hero-Text auf der
Startseite sanft ein — bewusst nur dort, Portal/Admin bleiben animationsfrei
(schnelles CRUD-Arbeiten soll nicht ausgebremst werden). Progressive-Enhancement-Muster:
`js-anim`-Klasse synchron in `<head>` gesetzt, CSS blendet nur dann aus, Script entfernt
die Klasse wieder falls GSAP nicht lädt — nie dauerhaft unsichtbarer Inhalt. Per Playwright
in Light/Dark/Hover visuell geprüft (dabei einen `.from()`-vs-`.fromTo()`-Bug gefunden und
vor dem Commit behoben). Alle 73 Tests grün, gecommittet auf
`claude/stromfueralle-footer-pages-trqb5c`. Offen: Phase 2 (verbleibende Emojis in
~42 weiteren Dateien: Rechtstexte, Beitreten-Flow, Portal/Admin-Backoffice) nach Rückmeldung.

## 2026-07-24 (nachts) — Claude Code — Claude Opus 4.8
**Auftrag:** Nachweisen, dass die Sicherungen wirklich laufen (inkl. Alarm ins Admin-Postfach),
Zähler- und Mitgliederdaten sicher abdecken, Stammdaten von den (später großen) Messwerten
trennen und eine Wiederherstellungs-Möglichkeit auf der Plattform. Außerdem Bugfix: in der
Passwort-vergessen-Mail standen rohe Platzhalter statt der Anrede.
**Ergebnis:** Bugfix — Reset-Route übergibt jetzt anrede/nachname (`salutationVarsForEmail()`),
plus generelles Sicherheitsnetz `stripUnresolvedPlaceholders()` im Mailer (4 Tests); functions.php
zusätzlich in den Alarm-Skripten eingebunden. Backup: `backup.sh` erzeugt zusätzlich einen
Stammdaten-Dump (`eeg_stamm_*.dump`, ohne Messwerte, Tabellenliste dynamisch = alle public-Tabellen
minus Hypertables), beide Dumps werden validiert, `last_backup.json` als Statusdatei. `restore.sh`
erkennt den Stammdaten-Dump und ersetzt nur dessen Tabellen (`--clean --if-exists`), Messwerte
bleiben erhalten. Neue Admin-Seite `/admin/backups` (Backup-Verzeichnis `:ro` gemountet): warnt
sichtbar bei überfälliger Sicherung, listet alle Sicherungen mit Restore-Befehl. Bewusst KEIN
ausführender Restore-Button in der Web-UI (bräuchte Docker-Zugriff der Webapp = Host-Übernahme bei
einer einzigen Web-Lücke) — begründet in docs/BACKUP.md und auf der Seite selbst.
Hinweis festgehalten: Messwerte sind Hypertables und nicht einzeln dumpbar → im Volldump.
Alle 70 Tests grün.

## 2026-07-24 (spätabends) — Claude Code — Claude Opus 4.8
**Auftrag:** Punkt 4 von 4: TOTP-2FA mit Ein-/Ausschalter (Passkeys später).
**Ergebnis:** Abhängigkeitsfreie TOTP-Funktionen (base32, totpCodeAt/Verify, Provisioning-URI),
gegen RFC-6238-Testvektoren geprüft (9 Tests). `migrate_20260816` (users.totp_secret/enabled).
Auth in checkPassword()+establishSession() aufgeteilt → zweistufiger Login (Passwort → Code,
`/portal/login/2fa`). Selbst-Verwaltung im Profil: aktivieren mit Code-Bestätigung
(`/portal/profile/2fa/setup|enable`), deaktivieren jederzeit. Setup-Seite zeigt Setup-Schlüssel +
otpauth-Link (Apple Passwörter/Authenticator). Notfall-Reset per SQL dokumentiert. Alle 66 Tests
grün. Damit sind alle vier gewünschten Punkte (Rücklastschrift/Mahnwesen, Jahresübersicht,
Audit-Vorher/Nachher, 2FA) umgesetzt. Gemergt (#17 folgend).

## 2026-07-24 (abends) — Claude Code — Claude Opus 4.8
**Auftrag:** Punkt 3 von 4: Audit-Log mit Vorher→Nachher-Werten (wer/wo/was von X auf Y).
**Ergebnis:** `migrate_20260815` (audit_log.aenderungen JSONB). Reine, getestete Helfer
`auditNormalizeValue`/`auditDiff`/`auditChangesText` (5 Tests) + `logAuditDiff()`. Instrumentiert:
EEG-Stammdaten, Mitglied-Bearbeitung, E-Mail-Vorlagen, Mail-Konfiguration (Secret/Logo nur als
„geändert", nie im Klartext). Nebenbei-Fix: Vorlagen-Speicherroute-Whitelist um
`sepa_prenotification` und `mahnung` ergänzt (vorher HTTP 400). Alle 57 Tests grün. Gemergt (#16 folgend).
Offen: TOTP-2FA (mit Ein-/Ausschalter) als letzter der vier Punkte.

## 2026-07-24 (spätnachmittags) — Claude Code — Claude Opus 4.8
**Auftrag:** Punkt 2 von 4: Jahresübersicht/-abrechnung pro Mitglied.
**Ergebnis:** Helfer `memberJahresUebersicht()` (alle Rechnungen eines Jahres aus dem
Quartals-Präfix, Netto/USt/Brutto via taxBreakdown, Jahressummen, Jahresliste). Routen für
Obmann (`/portal/members/:id/jahresuebersicht[/:jahr]`) und Mitglied
(`/portal/my/jahresuebersicht[/:jahr]`); druckbare Standalone-Seite `jahresuebersicht.php`
(Browser-Druck→PDF, kein LaTeX nötig), Jahr per Klick wechselbar. Verlinkt am Mitglied und
unter „Meine Dokumente". Keine Migration. Alle 52 Tests grün. Gemergt (#15 folgend).

## 2026-07-24 (nachmittags) — Claude Code — Claude Opus 4.8
**Auftrag:** Rücklastschrift + Mahnwesen als erster von vier nächsten Punkten (danach
Jahresübersicht, Audit-Log Vorher/Nachher, TOTP-2FA mit Ein-/Ausschalter; Passkeys später).
**Ergebnis:** `migrate_20260814` (communities.mahngebuehr_eur; invoices.mahnstufe /
mahn_gebuehr_summe_eur / letzte_mahnung_at / ruecklastschrift_at; Vorlage `mahnung`). Routen
`/portal/billing/invoices/:id/ruecklastschrift` und `.../mahnung` (Stufe 1–3, Gebühr aufschlagen,
Mail mit Brutto/Gebühren/Gesamt/Frist/IBAN). UI in der Rechnungsliste (Rücklastschrift- und
Mahn-Buttons, Mahnstufen-Badge), Mahngebühr-Feld in den EEG-Einstellungen, Vorlage im Admin
editierbar + Vorschau-Testnutzer. `mahnstufeText()` mit 5 Tests. Alle 52 Tests grün. Gemergt (#14 folgend).

## 2026-07-24 (mittags) — Claude Code — Claude Opus 4.8
**Auftrag:** Jeder Container soll healthy/unhealthy anzeigen; bei Problemen den Platform-Admin
per Postfach benachrichtigen und den Dienst 1–2× automatisch neu starten. Außerdem einen
Dark/Light-Kontrast-Bug bei Buttons beheben (Text im Dark Mode dunkelgrau, schlecht lesbar).
**Ergebnis:** Healthchecks für traefik (`--ping`) und mqtt-subscriber (Heartbeat-Datei +
`threading`-Loop in main.py) ergänzt — alle Container zeigen jetzt einen Healthstatus. Wächter
`scripts/health_monitor.sh` (Host-Cron) startet unhealthy/gestoppte Dienste 1–2× neu und mailt
bei anhaltendem Problem via `scripts/health_alert.php` (6-h-Cooldown). Kontrast-Bug: getönte
Buttons waren feste Inline-Hex ohne Dark-Variante → neue `.btn-tint-*`-Klassen (hell+dunkel),
alle betroffenen Buttons repo-weit umgestellt. Alle 47 Tests grün. Gemergt (Fortsetzung #13).
Offen/als Nächstes: Rücklastschrift+Mahnwesen, Jahresübersicht, Audit-Log mit Vorher/Nachher, 2FA.

## 2026-07-24 (vormittags) — Claude Code — Claude Opus 4.8
**Auftrag:** E-Mail-Vorlagen laut Mandatsdatei umsetzen (formelle Anrede „Sehr geehrter Herr
{{nachname}}" statt „Hallo {{vorname}}", neue Platzhalter {{anrede}}/{{nachname}}). Außerdem der
Fall Franz Lorenz (Vertrag) / Burgi Lorenz (E-Mail): pro Mitglied wählbare E-Mail-Anrede
(Automatisch/Herr/Frau/Familie), einstellbar beim Bearbeiten und beim Freigeben einer
Online-Beitrittserklärung.
**Ergebnis:** `mailSalutation()` (7 Tests) + Spalte `members.email_anrede_mode`
(`migrate_20260813`, inkl. Umstellung aller 7 Vorlagen auf {{anrede}} {{nachname}}). Alle
6 Mitglieder-Mailtypen verkabelt (Einladung, Deaktivierung, 3× Vertrag, SEPA-Vorabinfo);
Passwort-Reset bewusst ohne Namen. Auswahlfeld im Mitglied-Formular und im Freigabe-Dialog,
Vorschau-Testnutzer + Platzhalterhilfe ergänzt. Hinweis: die Vertragsvorlagen wurden mit
„im Mitgliederportal … digital unterschreiben" statt dem Mandats-Wortlaut „im Anhang" gesetzt,
da der aktuelle Flow per Link signiert (kein Anhang). Alle 47 Tests grün. Gemergt (Fortsetzung #12).

## 2026-07-24 (früh) — Claude Code — Claude Opus 4.8
**Auftrag:** Signatur-Logo besser steuerbar: Größe (px) einstellbar und Position frei wählbar
(zwischen Grußformel und Impressum, nicht immer am Ende). Außerdem die E-Mail-Vorschau für
JEDE Vorlage (Rechnung, Passwort-Reset …) mit einem Test-Nutzer, dessen Variablen gefüllt sind.
Bestätigt: die SEPA-Testdatei wurde von der Sparkasse (George) akzeptiert.
**Ergebnis:** Logo-Breite/-Höhe in `platform_mail_config` (`migrate_20260812`), `{{logo}}`-
Platzhalter für die Position (Mailer::send ersetzt ihn bzw. hängt sonst ans Ende an). Vorschau
umgebaut: Vorlagen-Dropdown + Test-Nutzer (alle Platzhalter), Live-Größe/Position, Betreff live.
Alle 40 Tests grün. Gemergt (Fortsetzung #11).

## 2026-07-23 (nachts) — Claude Code — Claude Opus 4.8
**Auftrag:** Nachbesserungen: (1) das Bank-Prüftool wies die SEPA-Testdatei wegen ungültiger
Beispiel-IBAN ab – korrigieren; (2) die Rechnungsliste soll immer Brutto anzeigen; (3) in den
E-Mail-Einstellungen eine Vorschau, wie die Mail in Smartphone- und Laptop-Breite aussieht.
**Ergebnis:** (1) Mod-97-gültige AT-Test-IBANs im Test-Generator + korrigierte Datei geschickt;
echter SEPA-Export überspringt jetzt zusätzlich ungültige IBANs. (2) Rechnungsliste zeigt
Brutto (via `taxBreakdown`, LATERAL-Join auf `tax_config`). (3) Live-Vorschau (375 px / 820 px)
mit Signatur + Logo in den E-Mail-Einstellungen. Alle 40 Tests grün. Gemergt (Fortsetzung #10).

## 2026-07-23 (spätabends) — Claude Code — Claude Opus 4.8
**Auftrag:** Drei Wünsche: (1) ein Logo/Bild in der E-Mail-Signatur, auch bei No-Reply
sichtbar; (2) eine SEPA-Test-XML-Datei mit Beispieldaten, um sie schon vor den ersten
EDA-Daten beim Bank-Prüftool zu testen; (3) das Steuermodell netto/brutto (20 % USt) neben
Kleinunternehmer umsetzen.
**Ergebnis:** (1) Signatur-Logo im Platform-Admin hochladbar, als Inline-CID-Bild in jede
Mail eingebettet (`Mailer.php`, `platform_mail_config.signature_logo_*`, `migrate_20260811`).
(2) Route `/portal/billing/sepa-test-xml` + Button liefert eine `pain.008`-Beispieldatei;
zusätzlich eine fertige Datei direkt an Patrick geschickt. (3) `taxBreakdown()` (7 Tests)
zentralisiert netto/USt/brutto; Rechnung-PDF, SEPA-Einzug und Vorabinfo nutzen jetzt Brutto,
Kleinunternehmer bleibt Default. Alle 40 Tests grün. Gemergt als PR (Fortsetzung von #9).

## 2026-07-23 (abends) — Claude Code — Claude Opus 4.8
**Auftrag:** SEPA-Lastschrift-Abwicklung fertigstellen: Sammellastschrift (pain.008) je
freigegebenem Abrechnungslauf herunterladbar, Aufteilung Einzug (Saldo > 0) vs. Überweisung
durch den Obmann (Saldo < 0), Zahlungsstatus-Verfolgung unter *Rechnungen* und eine
SEPA-Vorabinformation per Mail bei der Freigabe (Abbuchung = Rechnungsdatum + 14 Tage).
**Ergebnis:** Neue Routen `/portal/billing/:id/sepa-xml` (nutzt die getestete
`sepaPain008Xml()`, Format `.08`/`.02` je EEG) und `/portal/billing/invoices/:id/mark-paid`;
Zahlungsstatus-Spalte + Fortschritt „X von Y erledigt" in `billing_invoices.php`; Vorabinfo-Mail
bei der Freigabe (`sendSepaPrenotifications()`, Vorlage `sepa_prenotification` in
`migrate_20260810.sql`, im Admin editierbar). Alle 33 Tests grün, PHP-Lint sauber. CHANGELOG
(Unreleased) und Doku ergänzt.

## 2026-07-23 (nachmittags) — Claude Code — Claude Opus 4.8
**Auftrag:** Produktions-Notfall: nach Deploy + Container-Neubau wirkte die Datenbank leer
(Login/Abrechnung defekt). Ursache finden, Daten retten, dauerhaft absichern; außerdem tägliche
Backups mit Fehler-Alarm einrichten und die Pfad-/Mount-Struktur dokumentieren.
**Ergebnis:** Ursache = `timescaledb-ha`-Image legt PGDATA unter `/home/postgres/pgdata/data` ab,
Mount stand aber auf `/var/lib/postgresql/data` → DB lief auf flüchtigem Container-Speicher, nach
Neubau „weg". Echte Daten (Cluster bis 18.06.) lagen unangetastet auf der Platte; wiederhergestellt
aus `backups/eeg_20260716_1859.dump` (TimescaleDB pre/post_restore), danach alle Migrationen
nachgezogen. Mount korrigiert + Image auf feste Digest gepinnt (`docker-compose.yml`). Backup
gehärtet (`scripts/backup.sh` mit Gültigkeitsprüfung, Rotation, E-Mail-Alarm via
`scripts/backup_alert.php`), Cron auf 02:00 dokumentiert inkl. „wirklich installiert?"-Check.
Neue Doku `docs/INFRASTRUKTUR_PFADE.md` (Pfade/Mounts + Diagramm), in CLAUDE.md + Obsidian
verlinkt. Anschließend Backup-Kette gehärtet: Prüf-Bug in `backup.sh` (pg_restore über Pipe)
und STDERR-Crash im Alarm-Mailer gefixt; `backup-storage.sh` sichert jetzt das komplette
`webapp-storage` inkl. `pdfs/` und Beitrittserklärungen/SEPA-Mandate (vorher leeres 45-Byte-
Archiv); `sync-to-nas.sh` alarmiert ebenfalls bei Fehlschlag; zwei konfigurierbare
Alarm-Empfänger-Adressen im Platform-Admin (`migrate_20260806`). Cron auf 02:00 (DB) / 02:05
(Dateien) / 02:20 (NAS) gesetzt und getestet.

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
