# Claude-Sitzungslog

Fortlaufende Selbstdokumentation aller Claude-Arbeitssitzungen rund um die EEG-Plattform:
Datum, verwendetes Modell, Werkzeug und der professionell zusammengefasste Auftrag.
Neueste Einträge oben. Format und Regeln: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.
Einträge aus Cowork/Claude Chat liegen zusätzlich im Obsidian-Vault unter
`eeg-platform-notes/logs/JJJJ-MM-TT.md`.

---

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
