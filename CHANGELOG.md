# Changelog

Alle nennenswerten Änderungen an der EEG-Plattform, versioniert nach
[Semantic Versioning](https://semver.org/lang/de/): **MAJOR.MINOR.PATCH**.

- **PATCH** (z. B. 0.9.0 → 0.9.1): Bugfix, keine neuen Funktionen.
- **MINOR** (z. B. 0.9.0 → 0.10.0): neue, rückwärtskompatible Funktion.
- **MAJOR** (z. B. 0.x → 1.0.0): großer Umbau bzw. der erste echte Produktivstart.

`0.x`-Versionen = vor dem echten Produktivstart (noch in Entwicklung/Test). Die Version `1.0.0`
markiert den ersten produktiven Echtbetrieb.

Jeder Eintrag entspricht einem Git-**Tag** (`vX.Y.Z`). So lässt sich jederzeit ein bestimmter,
getesteter Stand deployen oder dorthin zurückrollen (siehe „Bestimmte Version deployen" in
`SETUP.md`/`README.md`).

---

## [Unreleased]
Änderungen, die noch keinem Versions-Tag zugeordnet sind, sammeln sich hier.

### Neu / Funktionen
- **Pre-Launch-Hinweis-Popup für Mitglieder.** Beim Login (Mitglieder-Ansicht, nicht für
  Obmänner/Platform-Admins) erscheint jetzt bei jedem Login ein Hinweis, dass sich die
  Plattform noch in der Entwicklungs-/Vorbereitungsphase befindet und sich Inhalte jederzeit
  ändern können -- mit Link zum Support-Bereich für Vorschläge. Verschwommener Hintergrund,
  restliche Seite erst nach Klick auf "Gelesen, weiter zur Plattform" nutzbar
  (`/portal/ack-prelaunch`, Session-Flag wird bei jedem Login zurückgesetzt).
- **Hinweis auf Live-Dashboards, wenn nicht alle Zählpunkte online sind.** Die "Live-Leistung"-
  Kachel im Obmann-Dashboard und das öffentliche Live-Dashboard (`/live`) zeigen jetzt einen
  Hinweistext, sobald mindestens ein bekannter Zählpunkt gerade nicht meldet -- die angezeigten
  Gesamtwerte könnten dann geringfügig von der tatsächlichen Situation abweichen.
- **Zählpunkt-Typ "Bezug + Einspeisung (ein Zähler)" jetzt auch im normalen Zählpunkt-Formular
  wählbar** (bisher nur über die separate "Zählpunkte ohne Zuordnung"-Seite). Dazu neue
  Validierung: eine Zählernummer darf nur einem aktiven Zählpunkt zugeordnet sein -- wird
  dieselbe Nummer für zwei getrennte Zählpunkte (Bezug + Einspeisung) desselben physischen
  Zählers eingetragen, bekäme laut MQTT-Zuordnung nur einer der beiden je Live-Daten, der
  andere bliebe für immer auf "keine Daten" stehen. Für ein einzelnes bidirektionales Gerät
  bitte den neuen Typ statt zweier Zählpunkte verwenden.
- **Live-Leistungsanzeigen aktualisieren sich jetzt per Fetch alle 5s statt per Seiten-Reload.**
  Betrifft die "Aktuelle Leistung"-Kachel im Mitglieder-Dashboard (neuer Endpunkt
  `/portal/api/current-power`), die "Live-Leistung"-Kachel im Obmann-Dashboard (neuer Endpunkt
  `/portal/api/live-power`, gemeinsame Grundlage `communityLivePower()`) und das öffentliche
  Live-Dashboard (`/live`, Intervall von 10s auf 5s verkürzt, passend zum 5s-ESP-Sendeintervall).
- **Testphase-Reset für Live-ESP-Messdaten pro Mitglied.** Neuer Button "Live-Messdaten
  zurücksetzen (Testphase)" bei den Zählpunkten eines Mitglieds (`/portal/members/:id/reset-live-data`):
  löscht alle `esp_measurements`-Zeilen und setzt Online-/WLAN-Status für alle Zählpunkte
  dieses einen Mitglieds zurück -- bewusst pro Mitglied, nicht für die ganze EEG auf einmal.
  Nur sichtbar/ausführbar im Testmodus (Platform-Admin → Plattform-Technik); verschwindet
  automatisch, sobald eine EEG auf Echtbetrieb umgestellt wird, damit niemand aus Versehen
  echte Live-Daten löscht.
- **Mitglieder-Dashboard: Tausenderpunkt bei den kWh/W-Anzeigen entfernt.** War auf Wunsch von
  Patrick verwirrend ("2.000" ließ sich auf den ersten Blick nicht sicher von "2,0" unterscheiden).
  Betrifft nur die Verbrauchs-/Erzeugungszahlen (Aktuelle Leistung, Bezug/Erzeugung, Live-
  Einspeisung, Monatsverlauf) -- Euro-Beträge (Rechnungssaldo) behalten die übliche
  Tausenderpunkt-Formatierung.
- **Mitglieder-Dashboard: "Aktuelle Leistung" + selbst berechnete Live-Einspeisung in die
  Gemeinschaft.** Neue Kachel "Aktuelle Leistung" zeigt die aktuelle Netto-Leistung des
  Mitglieds (positiv = wird bezogen, negativ = wird eingespeist, aus dem jeweils neuesten
  ESP-Messwert je Zählpunkt). Für Mitglieder mit Einspeisung (Erzeuger/Prosumer) zusätzlich
  eine neue Kachel "Einspeisung in die Gemeinschaft (Live-Schätzung)" mit wählbarem Zeitraum
  (letzte 1/3/6/12/24 Stunden, heute, diese Woche/Monat/Jahr, bestimmter Tag oder Zeitraum) --
  berechnet, wie viel der eigenen Einspeisung im gewählten Fenster tatsächlich von anderen
  Mitgliedern verbraucht wurde (proportionale Aufteilung von `min(Gesamt-Bezug,
  Gesamt-Einspeisung)` je Viertelstunden-Fenster nach eigenem Erzeugungsanteil,
  `ownEinspeisungInGemeinschaftKwh()`). **Immer zuerst je Viertelstunden-Fenster gematcht, erst
  danach über den gewählten Zeitraum aufsummiert** -- nie umgekehrt (Bezug/Einspeisung erst über
  Stunden/Tage aufsummieren und danach ein Minimum bilden), sonst würde z. B. an einem
  Sonnentag die tagsüber hohe Einspeisung rechnerisch den nächtlichen Netzbezug "ausgleichen",
  obwohl beides nie zeitgleich auftrat. Energie je Fenster wird aus der Differenz der
  kumulativen Zählerstände (`energy_bezug_wh`/`energy_einspeisung_wh`, dieselben Register wie
  der Smart Meter) gebildet statt aus gemittelter Momentanleistung -- exakter und robust
  gegenüber kurzen ESP-Ausfällen. Bewusst nur eine ergänzende, klar als Schätzung
  gekennzeichnete Live-Kennzahl aus den ESP-Messwerten -- die bestehenden EDA-Monatswerte
  ("Bezug aus der Gemeinschaft"/"Eigene Erzeugung") bleiben unverändert die für die Rechnung
  maßgebliche Quelle (siehe Abgrenzung in `docs/AUFTEILUNGSSCHLUESSEL.md`).
- **Live-Fehleranzeige für Mitglieder mit ESP-/Zähler-Problem.** Bisher gab es keine Möglichkeit,
  auf einen Blick zu sehen, bei wie vielen Mitgliedern gerade ein ESP offline ist oder der Zähler
  (P1-Signal) nicht erreichbar ist -- man musste jedes Mitglied einzeln aufrufen. Neuer roter
  Zähler-Badge auf dem "Mitglieder"-Menüpunkt in der Sidebar (Anzahl betroffener Mitglieder,
  verschwindet automatisch, sobald das Problem behoben ist -- kein "gelesen"-Status wie bei
  Postfach-Nachrichten, da es sich um einen Live-Zustand handelt). Neue Spalte "Zähler" in der
  Mitgliederliste (rot "Fehler" / grün "OK" / grau "noch keine Daten") mit eigenem Filter und
  Sortierung. Das Postfach-Badge in der Sidebar ist jetzt ebenfalls rot statt gelb, da dort u. a.
  echte Fehlermeldungen (unbekannte Zählernummer, SSID-Wechsel) landen.
- **ESP32-Firmware an neue Hardware ohne BC547-Transistor angepasst.** Der Transistor (R1/R2/
  R3/Diode) wurde aus dem Aufbau entfernt, RJ12 Pin 5 liegt jetzt direkt auf RX2. Der
  Transistor hat das P1-Signal invertiert -- das übernimmt jetzt die Software
  (`invert=true` in `P1Serial.begin()`). Pegelanpassung war seine zweite Aufgabe: noch nicht
  verifiziert, ob die P1-Schnittstelle ohne ihn eine für den ESP32-GPIO unbedenkliche Spannung
  liefert (siehe Warnhinweis im Sketch/README).
- **Konfigurierbare ESP-Offline-Schwelle + strukturiertere Plattform-Einstellungen.** Ein ESP
  galt bisher unabhängig davon als online, wie lange die letzte Meldung her war -- ein
  hängengebliebenes Gerät hätte theoretisch für immer als online angezeigt werden können.
  Neue Einstellung „Als offline anzeigen nach (Minuten ohne Meldung)" (Standard 5) unter
  Platform-Admin → E-Mail-Einstellungen → neuer Abschnitt „ESP32 / Ausleseeinheiten". Diese
  Seite ist jetzt außerdem in „Plattform-Technik" und „E-Mail (Microsoft Graph)" unterteilt.
- **MQTT-Broker mit TLS + Benutzername/Passwort statt offen/anonym.** Mosquitto verlangte
  bisher gar keine Anmeldung und lief unverschlüsselt -- neues Skript
  `scripts/mqtt_secure_setup.sh` erzeugt ein selbstsigniertes Zertifikat (Port 8883, 10 Jahre
  gültig) und zufällige Zugangsdaten, schreibt die Passwort-Datei und startet die betroffenen
  Container neu; wird bei einer Neuinstallation automatisch von `scripts/setup.sh` mit
  aufgerufen. Die ESP32-Firmware wechselt automatisch auf TLS (`WiFiClientSecure`), sobald der
  konfigurierte MQTT-Port auf 8883 steht. **Achtung beim Rollout:** danach verlieren bereits
  laufende Geräte die Verbindung, bis Benutzername/Passwort im `/config`-Formular nachgetragen
  werden.
- **ESP32: eigenes Live-Daten-Intervall.** Bezug/Einspeisung wurden über dieselbe Variable wie
  der ESP-Online-Heartbeat gedrosselt (fix 30 s) -- jetzt ein eigenes, unabhängiges Feld
  `live-daten-intervall` im `/config`-Formular, Standard 5 s.
- **WLAN-Passwort-Versand überarbeitet: nur noch bei echtem WLAN-Wechsel/Boot statt bei jedem
  Heartbeat.** Ein Zwischenstand hatte das WLAN-Passwort testweise bei jedem periodischen
  Heartbeat mitgeschickt (Fix für "kam gar nicht an"), das war Patrick auf Dauer zu häufig auf
  der Leitung, auch wenn TLS (Port 8883) es ohnehin verschlüsselt. Jetzt sendet die Firmware
  das Passwort nur noch beim MQTT-(Re-)Connect (`mqttReconnect()`) -- das deckt "einmal pro
  Boot" UND "bei einem WLAN-Wechsel" ab, weil ein Wechsel über das Config-Formular immer zu
  `ESP.restart()` führt. SSID/IP kommen weiterhin bei jedem Heartbeat mit. Plattformseitig
  (`mqtt-subscriber`) behält `metering_points` den zuletzt bekannten SSID/IP/Passwort-Wert bei,
  bis tatsächlich ein neuer ankommt (kein Überschreiben durch fehlende Felder), und meldet eine
  **neue Postfach-Benachrichtigung bei echtem SSID-Wechsel** (`typ = 'ssid_geaendert'`) --
  bewusst NICHT bei einer reinen IP-Adressänderung, die passiert routinemäßig per DHCP und wäre
  keine Meldung wert.
- **Benachrichtigung bei unbekannter Zählernummer.** Sendet ein Gerät MQTT-Daten für eine
  Zählernummer, die keinem Zählpunkt der jeweiligen EEG zugeordnet ist, erscheint jetzt eine
  offene Meldung im Postfach (`/portal/postfach`) -- bisher landete das nur unsichtbar im
  Container-Log. Nur eine offene Meldung je Zählernummer, kein Spam bei jeder Nachricht.
- **Sidebar bleibt beim Scrollen sichtbar (sticky), Icon-Sprite-Cache-Busting, Dark-Mode-Fix.**
  Das Portal-Menü links scrollt jetzt nicht mehr mit dem Seiteninhalt mit, sondern bleibt wie der
  Header stehen (Desktop; die mobile horizontale Icon-Leiste bleibt unverändert). `icon()` hängt
  jetzt einen `filemtime()`-Cache-Buster an die Sprite-URL an -- neu hinzugefügte Icons (z. B. das
  Support-Symbol) waren sonst erst nach einem Hard-Refresh sichtbar, weil Browser die alte
  Sprite-Datei weiter aus dem Cache lieferten. Außerdem: die Code-Schnipsel in der hellblauen
  Info-Box unter „API-Zugänge" (`GET /api/v1/live`) blieben im Dark Mode versehentlich dunkel
  eingefärbt und bissen sich mit dem hellen Kasten -- jetzt bewusst immer hell.
- **Support-Ticket-System für Mitglieder.** Neuer Bereich „Support" im Mitgliederportal
  (`/portal/my/support`): Mitglieder melden Probleme oder Feature-Vorschläge als Ticket statt
  per E-Mail, Obmänner/Platform-Admins sehen und beantworten alle Tickets ihrer Community unter
  `/portal/support` (Statuswechsel offen/in Bearbeitung/geschlossen, Thread mit Antworten). Bei
  jedem neuen Ticket geht eine Benachrichtigung an eine konfigurierbare Adresse (Platform-Admin →
  E-Mail-Einstellungen, Standard `office@stromfueralle.at`). Neue Tabellen `support_tickets`/
  `support_ticket_messages` mit Row-Level-Security (`migrate_20260821.sql`).
- **ESP32-Firmware: WLAN-Diagnose + Zähler-Erreichbarkeit tatsächlich mitgeschickt.** Die
  Firmware sendet jetzt beim MQTT-Status-Heartbeat zusätzlich SSID/IP (jeder Heartbeat) und das
  WLAN-Passwort (nur beim Boot/Reconnect) sowie `meter_ok` -- ob zuletzt ein gültiges
  P1-Telegramm vom Smart Meter empfangen wurde, getrennt vom WLAN/MQTT-Online-Status des ESP
  selbst. Damit lässt sich Inselbetrieb/Stromausfall beim Mitglied (Zähler nicht erreichbar, ESP
  aber online) von einem Plattform-/ESP-Problem unterscheiden -- neue Spalten
  `metering_points.meter_reachable`/`meter_last_seen_at` (`migrate_20260820.sql`), Anzeige in
  `member_detail.php` und als Warn-Badge im Manager-Dashboard. Zusätzlich prüft die Firmware vor
  jedem Publish, ob die im `/config`-Formular eingetragene Zählernummer mit der tatsächlich aus
  dem P1-Telegramm gelesenen übereinstimmt -- ein Tippfehler in der Konfiguration hätte sonst
  Daten unbemerkt dem falschen Zählpunkt zugeordnet.
- **ESB → ESP umbenannt.** Die Ausleseeinheit heißt ESP (ESP32), nicht „ESB" -- eine falsche
  Abkürzung aus einer früheren Session. `metering_points.esb_online`/`esb_last_seen_at` →
  `esp_online`/`esp_last_seen_at` (`migrate_20260817.sql` benennt bereits vorhandene Spalten
  automatisch um, falls schon eingespielt), UI-Texte, Variablen und `docs/ESB_IDEEN.md` →
  `docs/ESP_IDEEN.md` entsprechend angepasst.
- **Eigenes Hero-Banner-Foto statt SVG-Illustration, per Admin-Upload mit Zuschnitt.**
  Unter `/admin/templates` lässt sich jetzt ein eigenes Foto für den Hero-Bereich der
  Startseite hochladen (neuer Registry-Eintrag `hero-banner.png`, gleiche
  Custom-Datei-mit-Fallback-Logik wie Logo/LaTeX-Vorlagen). Damit ein beliebig großes/
  proportioniertes Foto nie verzerrt oder mit Rand erscheint, gibt es dafür eine eigene
  Zuschneiden-Oberfläche (`rect-crop.js`, Schwester-Skript zum bestehenden quadratischen
  Profilbild-Zuschnitt `avatar-crop.js`): Zoom-Regler + Ziehen, der Ausschnitt füllt beim
  Speichern immer exakt 1600 × 640 Pixel (Seitenverhältnis des Hero-Bereichs). Ohne eigenes
  Foto bleibt die mitgelieferte SVG-Illustration unverändert bestehen. Keine neue Migration
  nötig — rein dateibasiert, wie die übrigen Einträge unter Dateien.
- **Emoji-Sweep abgeschlossen (Phase 2): komplettes Portal-/Admin-Backoffice auf
  Phosphor-Icons.** Aufbauend auf Phase 1 (Startseite) sind jetzt auch alle Emojis im
  Mitgliederbereich, der Abrechnung/Rechnungen, den Dateien/Dokumenten, dem
  Beitritts-/Vertragsflow und dem kompletten Admin-Bereich (~38 Dateien, ~190 Stellen)
  durch `icon()` ersetzt. Sprite um 17 weitere Icons ergänzt (u. a. wrench, paperclip,
  folder-open, sign-out, eye, hourglass, flask). `<option>`-Werte in `<select>`-Feldern
  bleiben bewusst reiner Text (Browser rendern dort kein Markup); die client-seitige
  IBAN-Live-Validierung und die Upload-Statusanzeige nutzen jetzt dieselbe SVG-Sprite-
  Referenz per `innerHTML` statt Emoji. Eine bewusste Ausnahme bleibt bestehen: das
  ⚠️-Symbol im Markdown-Export des Audit-Logs, da dort reiner Text ausgegeben wird.
- **Icon-System (Phosphor, selbst gehostet) + dezente Hero-Animation auf der Startseite
  (Phase 1).** Die generischen Emojis im Theme-Toggle, im Sidebar-Menü und auf der Startseite
  sind durch ein einheitliches Phosphor-Icon-Sprite ersetzt (`webapp/public/assets/icons/`,
  neue Helferfunktion `icon()` in `functions.php`) — kein externer CDN-Request, Icon-Farbe folgt
  automatisch dem Theme (`currentColor`, inkl. Dark Mode). Auf der öffentlichen Startseite blendet
  GSAP (self-hosted unter `assets/js/vendor/`, `site-animations.js`) den Hero-Text jetzt sanft ein;
  Portal/Admin bleiben bewusst unverändert und animationsfrei, damit schnelles, wiederholtes
  Arbeiten (Mitgliederlisten, Rechnungen) nicht ausgebremst wird. Progressive-Enhancement-sicher:
  ohne JS bzw. falls GSAP nicht lädt, bleibt der Hero-Text normal sichtbar. Weitere ~42 Dateien mit
  Emojis (Phase 2) folgen nach Rückmeldung.
- **Backup-Übersicht im Admin (`/admin/backups`).** Zeigt, ob die nächtliche Sicherung wirklich
  läuft: Zeitpunkt/Alter der letzten erfolgreichen Sicherung (grün) bzw. eine deutliche Warnung,
  wenn sie **überfällig** ist (> 26 h), dazu alle vorhandenen Sicherungen mit Größe, Alter und dem
  fertigen Wiederherstellungs-Befehl. Das Backup-Verzeichnis ist dafür **nur lesend** (`:ro`) in
  den webapp-Container eingebunden — die Weboberfläche kann Backups anzeigen, aber nie verändern.
- **Getrennte Sicherung von Stammdaten und Messwerten.** `scripts/backup.sh` erzeugt jetzt
  zusätzlich einen kleinen **Stammdaten-Dump** (`eeg_stamm_*.dump`: Mitglieder, Rechnungen,
  Verträge, Zählpunkte, Konfiguration — ohne Messwerte). Damit lassen sich die kritischen Daten
  wiederherstellen, **ohne** die großen Messwerte anzufassen: `restore.sh` erkennt diesen Dump und
  ersetzt gezielt nur die enthaltenen Tabellen, die Messwert-Hypertables bleiben erhalten.
  Beide Dumps werden auf Gültigkeit geprüft; scheitert einer, geht die Alarm-Mail ans Admin-Postfach.
  (Messwerte sind TimescaleDB-Hypertables und lassen sich nicht zuverlässig einzeln dumpen — sie
  stecken weiterhin im vollständigen Dump; Begründung in `docs/BACKUP.md`.)
- **Zwei-Faktor-Authentifizierung (TOTP), pro Konto ein-/ausschaltbar.** Optionaler zweiter Faktor
  beim Login per 6-stelligem Code (RFC 6238, abhängigkeitsfrei; kompatibel mit Apple Passwörter,
  Google Authenticator etc.). Aktivieren/Deaktivieren jederzeit selbst im Profil – beim Einrichten
  wird der Code einmal bestätigt, bevor 2FA scharf geschaltet wird. Login ist dann zweistufig
  (Passwort → Code). Neue Spalten `users.totp_secret`/`totp_enabled` (`migrate_20260816`),
  TOTP-Kernfunktionen mit RFC-Testvektoren abgesichert (9 Tests). **Notfall-Reset** (App verloren):
  `UPDATE users SET totp_enabled=false, totp_secret=NULL WHERE email='…';`.
- **Audit-Log mit Vorher→Nachher-Werten.** Änderungen an Konfiguration/Stammdaten werden jetzt
  feldgenau protokolliert: **wer**, **wann**, **wo** und **welcher Wert von X auf Y** – lesbar
  („IBAN: „—" → „AT…"") und maschinenlesbar (`audit_log.aenderungen` JSONB, `migrate_20260815`).
  Instrumentiert: EEG-Stammdaten, Mitglied-Bearbeitung, E-Mail-Vorlagen und Mail-Konfiguration
  (sensible Werte wie Client-Secret/Logo werden nur als „geändert" vermerkt, nie im Klartext).
  Der bestehende Markdown-Export enthält die Diffs automatisch mit.
- **Jahresübersicht pro Mitglied.** Eine druckbare Zusammenfassung aller Rechnungen eines
  Kalenderjahres (Quartale Q1–Q4) mit Netto/USt/Brutto je Rechnung, Zahlungsstatus/Mahnstufe und
  Jahressummen. Erreichbar für den Obmann (`/portal/members/:id/jahresuebersicht`, Button am
  Mitglied) und für das Mitglied selbst im Portal (unter „Meine Dokumente"). Jahr per Klick
  wechselbar, „Drucken / als PDF speichern" über den Browser (keine eigene LaTeX-Vorlage nötig).
- **Rücklastschrift + Mahnwesen.** Wird ein SEPA-Einzug von der Bank zurückgebucht, meldet der
  Obmann die **Rücklastschrift** (Rechnung wieder offen). Danach lässt sich stufenweise mahnen:
  **Stufe 1 Zahlungserinnerung → 2 = 1. Mahnung → 3 = letzte Mahnung**, je mit E-Mail
  (Vorlage `mahnung`, im Admin editierbar) und optionaler, je EEG konfigurierbarer **Mahngebühr**
  (`communities.mahngebuehr_eur`), die sich aufsummiert. Die Mail nennt den offenen Brutto-Betrag,
  die Gebühren, den Gesamtbetrag, eine Zahlungsfrist und die EEG-IBAN zur Überweisung. Neue
  Spalten `invoices.mahnstufe`, `mahn_gebuehr_summe_eur`, `letzte_mahnung_at`, `ruecklastschrift_at`
  (`migrate_20260814`); Aktionen direkt in der Rechnungsliste.
- **Container-Healthchecks + Selbstheilung & Alarm.** Jetzt hat **jeder** Container einen
  Healthcheck (auch `traefik` per `--ping` und der `mqtt-subscriber` per Heartbeat-Datei) —
  `docker compose ps` zeigt für alle `healthy`/`unhealthy` statt nur „Up". Neuer Wächter
  `scripts/health_monitor.sh` (als Cron auf dem Host): startet einen unhealthy/gestoppten Dienst
  1–2× automatisch neu und alarmiert bei anhaltendem Problem das Admin-Postfach
  (`scripts/health_alert.php`, gleiche Microsoft-Graph-Anbindung wie der Backup-Alarm),
  mit 6-h-Cooldown gegen Mail-/Neustart-Fluten.
- **Dark-Mode-Kontrast der Aktions-Buttons behoben.** Getönte Buttons (grün/blau/amber/rot)
  waren als feste Inline-Farben gesetzt und schalteten im Dark Mode nicht mit (heller Button auf
  dunklem Grund, schlechter Kontrast). Neue Klassen `.btn-tint-*` mit eigener Hell- und
  Dunkel-Variante; alle betroffenen Buttons (Mitglied-Aktionen, Zahlungsstatus, Freigeben/Ablehnen …)
  darauf umgestellt.
- **Formelle E-Mail-Anrede + Anrede-Modus je Mitglied.** Alle Mitglieder-Mails (Einladung,
  Vertrag, Deaktivierung, SEPA-Vorabinfo) verwenden jetzt `{{anrede}} {{nachname}}`
  („Sehr geehrter Herr Lorenz") statt „Hallo {{vorname}}". Neues Feld **E-Mail-Anrede** am
  Mitglied (`members.email_anrede_mode`: automatisch / Herr / Frau / **Familie**) – einstellbar
  in der Mitglieder-Bearbeitung und beim Freigeben einer Online-Beitrittserklärung. Löst den
  Fall, dass z. B. die Ehefrau die Mails liest, der Vertrag aber auf den Mann läuft
  („Sehr geehrte Familie Lorenz"). Das Geschlecht der Person bleibt davon unberührt, der
  Nachname immer der des Vertragspartners. Passwort-Reset bleibt bewusst ohne Namen
  („Liebes Mitglied"). Alle Vorlagen auf die neue Fassung umgestellt (`migrate_20260813`).
- **E-Mail-Vorschau je Vorlage (Smartphone + Laptop):** in den E-Mail-Einstellungen zeigt eine
  Live-Vorschau, wie eine ausgehende Mail aussieht – in Smartphone-Breite (375 px) und
  Laptop-Breite (≈820 px), aktualisiert beim Tippen. **Vorlagen-Auswahl** (Rechnung/Vorabinfo,
  Passwort-Reset, Einladung, Verträge …) mit einem **Test-Nutzer**, der alle Platzhalter
  (`{{vorname}}`, `{{betrag}}`, `{{link}}` …) mit Beispiel-Werten füllt.
- **Signatur-Logo: Größe & Position steuerbar.** Breite/Höhe in Pixel einstellbar
  (`platform_mail_config.signature_logo_width/height`; nur Breite oder Höhe → proportional).
  Mit dem Platzhalter `{{logo}}` lässt sich das Bild an eine beliebige Stelle der Signatur
  setzen (z. B. zwischen Grußformel und Impressum) statt nur ans Ende.
- **Rechnungsliste in Brutto:** die Betrags-Spalte zeigt jetzt immer den Brutto-Betrag
  (bei Kleinunternehmer identisch mit netto, bei Standard inkl. USt) – konsistent mit
  Rechnung, SEPA-Einzug und Vorabinfo.
- **Steuer netto/brutto (USt-Ausweis):** neben der Kleinunternehmerregelung jetzt ein
  Standard-Pfad mit Umsatzsteuer (Default 20 %, je EEG einstellbar). Tarife bleiben netto;
  bei „Standard" weist die Rechnung Netto, USt und Brutto aus, und **SEPA-Einzug wie
  Vorabinfo verwenden den Brutto-Betrag**. Zentral in der getesteten Funktion `taxBreakdown()`
  (7 Tests). Kleinunternehmer bleibt Default — für bestehende EEGs ändert sich nichts.
- **SEPA-Test-XML mit Beispieldaten** (`/portal/billing/sepa-test-xml`, Button in der
  Abrechnung): erzeugt eine `pain.008`-Datei mit Platzhalter-Schuldnern zum Prüfen im
  Bank-/Banking-Tool, **bevor** echte EDA-Daten vorliegen — nutzt die hinterlegte
  Gläubiger-ID/IBAN, ohne DB-Eintrag oder echten Einzug.
- **Logo/Bild in der E-Mail-Signatur:** im Platform-Admin (E-Mail-Einstellungen) hochladbar,
  wird als Inline-Bild (Content-ID) unter jede ausgehende Mail gesetzt — auch bei
  No-Reply-Absendern in Outlook/Gmail zuverlässig sichtbar. In der DB als Base64 gehalten
  (`platform_mail_config.signature_logo_*`), übersteht so einen Geräteumzug.
- **SEPA-Lastschrift-Export (pain.008):** je freigegebenem Abrechnungslauf eine
  Sammellastschrift als XML herunterladbar (`/portal/billing/:id/sepa-xml`). Format pro EEG
  umschaltbar zwischen `pain.008.001.08` (Standard) und `.02` (`communities.sepa_pain_version`),
  Gläubiger-ID in den EEG-Einstellungen. Es werden nur einzuziehende Rechnungen (Saldo > 0) mit
  gültigem Mandat aufgenommen; Mandatsreferenz stammt aus `members.mandatsreferenz`. Reine,
  getestete Generatorfunktion `sepaPain008Xml()` (5 Tests). **Vor dem ersten Echt-Einzug mit dem
  Prüftool der Bank validieren.**
- **Einzug vs. Überweisung nach Vorzeichen + Zahlungsstatus:** unter *Rechnungen* zeigt jede
  freigegebene Rechnung ihren Zahlungsstatus (offen / eingezogen / überwiesen). Positive Salden
  werden per SEPA eingezogen und nach Bankbestätigung als „eingezogen" markiert, negative Salden
  (EEG schuldet dem Mitglied) vom Obmann überwiesen und als „überwiesen" markiert. Fortschritts-
  anzeige „X von Y erledigt" — der Abrechnungsprozess gilt erst als abgeschlossen, wenn alle
  Rechnungen erledigt sind (`invoices.payment_status`, `paid_at`).
- **SEPA-Vorabinformation (Pre-Notification):** bei der Freigabe eines Laufs geht am selben Tag
  an jedes einzuziehende Mitglied eine Mail mit dem Abbuchungsdatum raus (= Rechnungsdatum +
  Vorlauftage der EEG, Default **14**, `communities.sepa_prenotification_days`). Vorlage im
  Platform-Admin editierbar (`sepa_prenotification`), `invoices.prenotified_at` verhindert
  Doppel-Mails.
- **Audit-Log als Markdown exportierbar** (`/admin/log/export`) — wer/wann/was, für spätere
  Auswertung (auch per KI). Konfigurations-/Einstellungsänderungen werden protokolliert.
- **Verträge pro EEG abschaltbar** (`communities.contracts_enabled`): blendet Bezugs-/
  Einspeisevereinbarung überall aus (Mitglieder-Portal, Obmann-Ansichten, Dateien, Vertrags-
  status), wenn eine EEG ohne separate Verträge arbeitet.
- **Preisliste mit Tarif-Historie:** die öffentliche Preisliste zeigt neben dem aktuellen Tarif
  eine Änderungshistorie, damit Mitglieder Preisänderungen nachvollziehen können.

### Behoben
- **Öffentliches Live-Dashboard: "Erzeugung heute" konnte 0 kWh zeigen, obwohl real eingespeist
  wurde.** Die Tageskennzahl (`/api/live/:slug`) berechnete den Basiswert als ERSTE Messung des
  Tages (`MIN(...) WHERE time >= CURRENT_DATE`) -- bei nur 1-2 Messwerten "heute" (z. B. beim
  manuellen Testen mit `mosquitto_pub`, siehe ESP32-Firmware-README) ergab das MAX = MIN = 0.
  Jetzt wird stattdessen die letzte bekannte Messung VOR dem heutigen Tag als Basis genommen
  (fällt nur dann auf 0 zurück, wenn ein Zählpunkt buchstäblich noch nie zuvor gemeldet hat --
  dann lässt sich "heute" nicht sinnvoll von "insgesamt" trennen).
- **ESP32: OTA-Netzwerkport erschien nicht in der Arduino-IDE.** `ArduinoOTA` war korrekt
  eingerichtet (Hostname/Passwort/Callbacks/`begin()`/`handle()`) -- die wahrscheinlichste
  Ursache lag am standardmäßig aktiven ESP32-Modem-Sleep, der eingehende mDNS-Multicast-Pakete
  verzögert/verwirft und den Port dadurch nur unzuverlässig sichtbar machte. `WiFi.setSleep(false)`
  nach dem WLAN-Connect ergänzt (kostet bei einem dauerhaft am Netzteil hängenden Gerät keinen
  relevanten Strom).
- **Live-Leistungswerte (API + Dashboards) waren um ein Vielfaches zu hoch.** `GET /api/v1/live`,
  das öffentliche Live-Dashboard (`/api/live/:slug`) und die "Community-Gesamtleistung live"-Kachel
  im Obmann-Dashboard summierten `power_bezug_w`/`power_einspeisung_w` über ALLE Messzeilen eines
  2-Minuten-Fensters statt nur den jeweils neuesten Wert pro Zählpunkt zu nehmen -- bei
  5-Sekunden-Sendeintervall (seit der Live-Interval-Änderung) macht das bis zu ~24 Zeilen pro
  Zähler, die aufsummiert wurden. Ein Mitglied sah in Node-RED z.B. 70.000 W statt tatsächlicher
  5,8 kW Einspeisung. Behoben mit `DISTINCT ON (metering_point_id) ... ORDER BY ... time DESC`
  (jeweils nur die neueste Zeile pro Zähler), zusätzlich im Live-Dashboard: die Tageskennzahl
  (`today_kwh`) summierte kumulative Zählerstände über Zeit und Zähler hinweg statt pro Zähler
  Max-Min zu bilden, und die Zeitreihen-Chart-Daten summierten ebenfalls zeilenweise statt
  zuerst pro Bucket/Zähler zu mitteln und dann über die Zähler zu summieren.
- **Rohe Platzhalter in der Passwort-Reset-Mail.** Die Vorlage nutzte `{{anrede}} {{nachname}}`,
  die Reset-Route übergab diese Werte aber nicht — beim Empfänger stand wörtlich
  „{{anrede}} {{nachname}},". Die Werte werden jetzt übergeben (aus dem Mitgliedsdatensatz, sonst
  neutral aus dem Login-Konto). Zusätzlich als generelles Sicherheitsnetz: **unbefüllte
  Platzhalter werden vor dem Versand entfernt**, damit nie wieder Vorlagen-Code in einer Mail
  landet (`stripUnresolvedPlaceholders()`, 4 Tests).
- **E-Mail-Vorlagen SEPA-Vorabinfo & Mahnung im Admin editierbar.** Beide Keys fehlten in der
  Whitelist der Vorlagen-Speicherroute – ein Speichern lief auf HTTP 400. Ergänzt.
- **SEPA-Test-XML: Beispiel-IBANs prüfziffern-gültig.** Die Debtor-IBANs der Testdatei waren
  frei erfunden und wurden vom Bank-Prüftool (zu Recht) als ungültig abgewiesen – jetzt
  Mod-97-korrekte AT-Test-IBANs. Zusätzlich überspringt der echte SEPA-Export Mitglieder mit
  ungültiger IBAN (statt die ganze Sammellastschrift von der Bank zurückweisen zu lassen).

### Behoben / Betrieb (Vorfall 23.07.2026)
- **DB-Datenverlust-Fallstrick behoben:** `timescaledb-ha`-Mount auf das echte PGDATA
  (`/opt/eeg/timescaledb:/home/postgres/pgdata`) korrigiert und Image auf feste Digest gepinnt.
  Der bewegliche `:pg16`-Tag hatte PGDATA unbemerkt verschoben, wodurch die DB nach einem
  Container-Neubau leer wirkte.
- **Backup gehärtet** (`scripts/backup.sh`): prüft den Dump auf Gültigkeit (nicht leer, lesbar),
  rotiert (letzte 14), und **alarmiert per E-Mail** ans Admin-Postfach bei Fehlschlag
  (`scripts/backup_alert.php`, Microsoft-Graph-Versand). Cron-Zeitplan auf 02:00 dokumentiert
  inkl. Prüfschritt „ist der Cron wirklich installiert".
- **Neue Doku** `docs/INFRASTRUKTUR_PFADE.md`: vollständige Pfad-/Mount-Übersicht mit Diagramm
  und Erklärung des PGDATA-Fallstricks; in CLAUDE.md + Obsidian verlinkt.
- **Backup-Prüfung repariert:** `backup.sh` verwarf gültige Dumps fälschlich (pg_restore -l über
  eine Pipe schlägt bei custom-format immer fehl); jetzt Prüfung über eine seekbare Datei.
  `backup_alert.php` crashte an nicht definiertem `STDERR` (Aufruf per stdin).
- **Datei-Backup repariert & erweitert:** `backup-storage.sh` sicherte nur `uploads/avatars` +
  `uploads/members` (Ergebnis: leeres 45-Byte-Archiv). Jetzt wird **das komplette**
  `webapp-storage` gesichert (inkl. `pdfs/` mit Verträgen/Rechnungen und den
  Beitrittserklärungen = SEPA-Mandaten), mit Inhalts-Prüfung und Fehler-Alarm.
- **NAS-Sync** (`sync-to-nas.sh`) alarmiert jetzt ebenfalls per E-Mail bei Fehlschlag.
- **Alarm-Empfänger konfigurierbar** (Platform-Admin → E-Mail-Einstellungen, zwei Felder;
  `migrate_20260806`), damit die Alarmierung nach einem Geräteumzug ohne Code-Änderung
  weiterläuft.

## [0.9.1] – 2026-07-23
### Geändert
- **Abrechnungs-Freigabe nach EDA-Datenqualität statt starrer 60-Tage-Frist**: Ein Lauf kann
  freigegeben werden, sobald die Werte belastbar sind (EDA-Monatsbericht meldet den Zeitraum als
  vollständig **und** es liegen keine L3-Ersatzwerte mehr vor) — auch früher als nach 60 Tagen,
  bzw. gesperrt, solange die Daten unvollständig sind. Neue Spalte `billing_runs.eda_status`
  (aus dem Eder-XLSX-Monatsbericht), Auswahl in der Abrechnungsübersicht; `freigabe_nach` bleibt
  nur noch informativ. Siehe `docs/EDA_DATENQUALITAET.md`.
### Behoben
- **Abrechnungs-Datenfilter**: Die EDA-Mengensummen wurden über `quality IN ('L2','L3')`
  gebildet — das schloss die **besten** Werte (L1, gemessen) aus und rechnete die **nicht
  belastbaren** (L3) mit. Korrigiert auf `('L1','L2')`.
### Doku
- `docs/RASPBERRY_STABILITAET.md` an den tatsächlichen Befund angepasst (NVMe über PCIe,
  Root-FS read-write → USB-SATA/read-only ausgeschlossen; persistentes journald empfohlen,
  Verdacht auf OOM/Unterspannung/NVMe-Link/systemd refokussiert).

## [0.9.0] – 2026-07-22
Erster versionierter Meilenstein: die Plattform ist funktional weitgehend vollständig, aber
noch vor dem echten Produktivstart.

### Enthalten (Funktionsumfang zum Zeitpunkt 0.9.0)
- **Mandantenfähigkeit**: mehrere EEGs auf einer Installation, Datentrennung via PostgreSQL
  Row-Level Security.
- **Mitgliederverwaltung**: Anlage manuell und über ein öffentliches Online-Beitrittsformular
  (inkl. IBAN-Prüfziffernvalidierung, Zählpunktübernahme, E-Signatur der Beitrittserklärung).
- **Verträge**: Bezugs-/Einspeisevereinbarung als LaTeX-PDF, E-Signatur-Workflow, Versand per
  Microsoft-Graph-Mail.
- **Abrechnung**: Quartalslauf auf Basis der EDA-Daten, anteiliger Mitgliedsbeitrag bei
  unterjährigem Beitritt, zweistufiger Ablauf (berechnen → Rechnungen einzeln prüfen/anpassen →
  freigeben), manuelle Zusatzpositionen, 60-Tage-Korrekturfenster.
- **Rechnung**: neues 4-spaltiges Layout, Positionen pro Zählpunkt, Kleinunternehmer-/
  UID-Ausweis, SEPA-Vorabankündigung, eigenes EEG-Logo, konfigurierbarer Footer (Kontakt/Bank).
- **Plattform-Admin**: EEG-Verwaltung, Benutzer-/Rollenverwaltung, E-Mail-Einstellungen
  (Microsoft Graph, Signatur, Reply-To), Datei-/Vorlagen-Manager mit Variablen-Referenz.
- **Mitglieder-Portal**: eigene Verträge/Rechnungen/Dateien, API-Zugänge, DSGVO-Datenexport.
- **Betrieb**: Ein-Befehl-Setup (`scripts/setup.sh`), Backup/NAS-Sync, Docker-Log-Rotation,
  Doku zur Raspberry-Stabilität (Watchdog).
- **Qualität**: abhängigkeitsfreie Test-Suite (`tests/`) + GitHub-Actions-CI.

[Unreleased]: https://github.com/ropperp/eeg-platform/compare/v0.9.1...HEAD
[0.9.1]: https://github.com/ropperp/eeg-platform/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/ropperp/eeg-platform/releases/tag/v0.9.0
