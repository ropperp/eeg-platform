# Claude-Sitzungslog

Fortlaufende Selbstdokumentation aller Claude-Arbeitssitzungen rund um die EEG-Plattform:
Datum, verwendetes Modell, Werkzeug und der professionell zusammengefasste Auftrag.
Neueste Einträge oben. Format und Regeln: Abschnitt „Selbstdokumentation" in `CLAUDE.md`.
Einträge aus Cowork/Claude Chat liegen zusätzlich im Obsidian-Vault unter
`eeg-platform-notes/logs/JJJJ-MM-TT.md`.

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
