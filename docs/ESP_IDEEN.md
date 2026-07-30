# ESP-Ideen & Backlog (Hardware/Firmware ↔ Plattform)

Gemeinsame Ideen-Sammlung für die Ausleseeinheit (ESP32), die sowohl von diesem Chat
(Plattform-Seite: `eeg-platform`) als auch vom ESP-Code-Chat (Firmware/Hardware) gelesen
werden kann. Neue Ideen hier ergänzen, nicht löschen — erledigte Punkte als „Umgesetzt"
markieren statt zu entfernen, damit die Historie nachvollziehbar bleibt.

> **Hinweis (30.07.2026):** Die Ausleseeinheit heißt **ESP** (ESP32), nicht „ESB" — das war
> eine falsche Abkürzung aus einer früheren Session. Betroffene Spalten (`esb_online` →
> `esp_online`, `esb_last_seen_at` → `esp_last_seen_at`), Variablen, UI-Texte und diese Datei
> (vorher `ESB_IDEEN.md`) wurden entsprechend umbenannt.

---

## Offen

### 5. Hardware ohne BC547-Transistor -- Pegelanpassung noch zu verifizieren
**Stand (Patrick, 30.07.2026):** Der BC547-Transistor (R1/R2/R3/Diode) wurde aus dem Aufbau
entfernt, RJ12 Pin 5 (Daten) liegt jetzt direkt auf RX2. Software entsprechend angepasst
(`invert=true` in `P1Serial.begin()`, siehe Kommentar im Sketch) -- der Transistor hat das
Signal invertiert, ohne ihn muss die Software das jetzt übernehmen. **Noch offen/ungeprüft:**
der Transistor hat außerdem die Spannung angepasst; ob die P1-Schnittstelle des Zählers ohne
ihn eine für den ESP32-GPIO unbedenkliche Spannung (≤ 3.3V) liefert, ist noch nicht mit einem
Multimeter verifiziert. Falls sich beim Testen 5V herausstellen: nicht dauerhaft ohne
Schutzbeschaltung (Spannungsteiler o. Ä.) betreiben.

**Status:** in Erprobung.

### 3. EDA-Monatsexport-Import mit automatischem Zählpunkt-Abgleich
**Idee (Patrick, 24.07.2026):** Beim Hochladen des **EDA-Monatsexports** (der Datei, die man
ohnehin für die Quartalsabrechnung importiert) soll die Plattform die enthaltenen Zählpunkte
automatisch mit dem eigenen Bestand abgleichen und Abweichungen klar melden — nicht nur eine
generische Fehlermeldung, sondern jeweils **ausformuliert, warum** etwas nicht passt.

**Gewünschtes Verhalten:**
1. Alle Zählpunkte (Zählpunktnummern) aus der Datei extrahieren.
2. Mit den in der EEG angelegten Zählpunkten abgleichen:
   - **In der Datei, aber noch nicht angelegt →** automatisch anlegen (soweit eindeutig
     möglich) und im Report auflisten, was neu angelegt wurde.
   - **In der Plattform aktiv, aber in der Datei nicht enthalten („fehlt") →** Warnung mit
     Begründung (z. B. „Zählpunkt AT00… ist bei uns aktiv, taucht im EDA-Export für diesen
     Monat aber nicht auf — evtl. Abmeldung, Zählerwechsel oder Datenlücke; bitte prüfen.").
   - **In der Datei, aber keinem Mitglied/keiner Anlage zuordenbar („zu viel") →** Warnung mit
     Begründung (z. B. „Zählpunkt AT00… ist im EDA-Export, gehört aber zu keinem Mitglied dieser
     EEG — gehört er hierher, fehlt eine Zuordnung; sonst ist es ein Fremd-Zählpunkt.").
3. **Ergebnis als übersichtlicher Report:** Abschnitte „neu angelegt", „Warnungen (fehlt/zu
   viel)", „Fehler", jeweils mit **verständlicher, ausformulierter Begründung** statt rohem
   „Error". Import soll auch bei Warnungen durchlaufen, klare Fehler (unlesbare Datei, falsches
   Format) sauber erklären.

**Für die Umsetzung zu klären / berücksichtigen (Plattform-Seite):**
- **Dateiformat des EDA-Monatsexports** genau festlegen (Eder-XLSX?): welche Spalten enthalten
  Zählpunktnummer, Zeitraum, Werte, Wertekategorie (L1/L2/L3, siehe `docs/EDA_DATENQUALITAET.md`)?
- **Zuordnung Zählpunkt → Mitglied** bei automatisch angelegten: welchem Mitglied zuordnen? Wohl
  zunächst „offen/nicht zugeordnet" markieren und der Obmann verknüpft manuell — sonst rät die
  Plattform falsch.
- Aufsetzen auf dem bestehenden EDA-Import (`eda_imports`/`eda_measurements`, `migrate_20260716`)
  und der Abrechnungs-Datenqualitätslogik (`Billing::datenqualitaetProblem()`).
- Nachvollziehbarkeit: Import-Lauf + Ergebnis im **Audit-Log** festhalten (seit 2026-08-15 mit
  Vorher/Nachher-Werten) — wer hat wann welche Zählpunkte automatisch angelegt.

**Status:** noch nicht begonnen — Backlog. Nächster sinnvoller Schritt, sobald echte
EDA-Exportdateien vorliegen (Format-Muster nötig).

---

## Umgesetzt

- **Live-Leistungsanzeigen per 5s-Polling statt Seiten-Reload (Patrick 30.07.2026):** Werte,
  "von denen man weiß, dass sie sich aktualisieren" (aktuelle Leistung), sollen sich selbst
  aktualisieren statt die ganze Seite neu zu laden. Neue schlanke JSON-Endpunkte
  `/portal/api/current-power` (Mitglied, nutzt `memberCurrentNetPowerW()`) und
  `/portal/api/live-power` (Obmann, nutzt neue gemeinsame Funktion `communityLivePower()`,
  ersetzt die bisher inline in `manager_dashboard.php` stehende SQL). Beide Dashboards pollen
  per `fetch()` alle 5s und schreiben nur die betroffenen DOM-Elemente neu. Das öffentliche
  Live-Dashboard (`/live`) hatte dieses Muster schon (Chart.js + Polling) -- Intervall dort von
  10s auf 5s verkürzt, passend zum 5s-ESP-Sendeintervall.
- **Testphase-Reset für Live-ESP-Messdaten pro Mitglied (Patrick 30.07.2026):** Patrick testet
  gerade aktiv mit echter/simulierter Hardware und wollte `esp_measurements` zwischendurch
  zurücksetzen können, OHNE die Daten anderer Mitglieder anzufassen. Neuer Button bei den
  Zählpunkten eines Mitglieds (`/portal/members/:id/reset-live-data`), nur im Testmodus
  sichtbar/ausführbar -- löscht alle Messzeilen und den Online-/WLAN-Status ausschließlich für
  die Zählpunkte DIESES Mitglieds, mit Bestätigungsdialog und Audit-Log-Eintrag. **Erster
  Versuch schlug fehl:** `meter_reachable` auf NULL zurückzusetzen verletzte den NOT-NULL-
  Constraint der Spalte (`migrate_20260820.sql`, Standard `false`) -- SQLSTATE[23502] direkt
  beim ersten Test durch Patrick. Auf `false` statt `NULL` korrigiert.
- **Bug: "Erzeugung heute" auf dem öffentlichen Live-Dashboard konnte 0 kWh zeigen (Patrick
  30.07.2026):** Patrick meldete, trotz realer Einspeisung zeige die Tageskennzahl nicht das
  Erwartete. Ursache: Basiswert war die ERSTE Messung des Tages -- bei wenigen Testmessungen
  "heute" (z. B. manuell per `mosquitto_pub`) ergab MAX=MIN=0. Fix: Basiswert ist jetzt die
  letzte bekannte Messung VOR dem heutigen Tag (fällt nur auf 0 zurück, wenn ein Zählpunkt
  buchstäblich noch nie zuvor gemeldet hat).
- **Erste echte Nutzung der ESP-Live-Leistungsdaten im Mitglieder-Dashboard (Patrick
  30.07.2026):** Bisher zeigte `/portal/dashboard` für Mitglieder nur EDA-Monatswerte (kein
  Live-Bezug auf `esp_measurements`, siehe frühere Fassung des Kommentars in
  `member_dashboard.php`: "keine Ausleseeinheit produktionsreif im Feld"). Jetzt zusätzlich:
  (1) Kachel "Aktuelle Leistung" (Netto-Leistung aus dem jeweils neuesten ESP-Messwert je
  eigenem Zählpunkt, positiv = Bezug, negativ = Einspeisung), (2) für Erzeuger/Prosumer eine
  selbst berechnete Live-Kennzahl "Einspeisung in die Gemeinschaft" mit wählbarem Zeitraum.
  Bewusst als ergänzende, klar gekennzeichnete Schätzung neben den unveränderten
  EDA-Monatswerten -- Abgrenzung zum amtlichen Aufteilungsschlüssel ausführlich in
  `docs/AUFTEILUNGSSCHLUESSEL.md` dokumentiert. **Nach Rückfrage korrigiert:** zunächst wurde
  Bezug/Einspeisung je Viertelstunden-Fenster aus gemittelter Momentanleistung geschätzt --
  Patrick wies zurecht darauf hin, dass die Zuteilung IMMER zuerst je Fenster gematcht werden
  muss (sonst würde z. B. an einem Sonnentag die tagsüber hohe Einspeisung den nächtlichen
  Netzbezug rechnerisch "ausgleichen", obwohl beides nie zeitgleich auftrat) UND dass die
  Registerstand-Differenz (`energy_bezug_wh`/`energy_einspeisung_wh`, dieselben kumulativen
  Zähler wie beim Smart Meter) die exaktere, lückenrobustere Grundlage ist als gemittelte
  Leistung. Beides war beim Fenster-Matching bereits korrekt (min() wurde schon pro Fenster vor
  der Summierung gebildet), die Energie-Berechnung selbst aber auf Registerstand-Differenz
  umgestellt. Bekannte Grenze: bei sehr langen Zeiträumen ("dieses Jahr") aggregiert die Abfrage
  potenziell Millionen Messzeilen live pro Seitenaufruf -- noch nicht mit Vorberechnung/Caching
  optimiert, falls sich das in der Praxis als zu langsam herausstellt.
- **Bug: OTA-Netzwerkport erscheint nicht in der Arduino-IDE (Patrick 30.07.2026):** Code-Review
  ergab, dass `ArduinoOTA` korrekt eingerichtet ist (Hostname/Passwort/Callbacks/`begin()` beim
  WLAN-Connect, `handle()` in jedem `loop()`) -- kein Konfigurationsfehler. Wahrscheinlichste
  Ursache: der ESP32-Modem-Sleep (WiFi-Stromsparmodus, standardmäßig aktiv) verzögert/verwirft
  eingehende mDNS-Multicast-Pakete, worauf die OTA-Port-Erkennung der Arduino-IDE basiert -- das
  Gerät ist dadurch nur unzuverlässig sichtbar. Fix: `WiFi.setSleep(false)` direkt nach
  `WiFi.mode(WIFI_STA)` in `connectSTA()`. Kostet bei einem dauerhaft am Netzteil hängenden Gerät
  keinen relevanten Strom. Falls weiterhin nicht sichtbar: gleiches Netz/Subnetz ohne
  Client-Isolation prüfen, ~10-20s nach Boot warten, sonst per IP direkt mit `espota.py`
  hochladen (siehe README). Nebeneffekt aus Patricks Upload-Log geprüft: Firmware nutzt aktuell
  1.160.540 von 1.310.720 Bytes (88 %) des OTA-App-Partitions-Slots (4-MB-Flash-Schema) und
  55.560 von 327.680 Bytes (16 %) RAM -- RAM hat viel Luft, der Flash-Slot wird aber langsam eng
  für künftige Features (siehe Bestell-Empfehlung in `CLAUDE.md`/Sitzungslog).
- **Bug: Live-Leistungswerte um ein Vielfaches zu hoch (Patrick 30.07.2026):** Patrick meldete
  über Node-RED (`GET /api/v1/live`) 70 kW Einspeisung, obwohl das eigene ESP-Webinterface und
  Loxone korrekt ~5,8 kW zeigten. Ursache: die Abfrage summierte `power_*_w` über ALLE Messzeilen
  eines 2-Minuten-Fensters statt nur den neuesten Wert je Zählpunkt zu nehmen -- seit dem
  5-Sekunden-Live-Intervall (Punkt oben) macht das bis zu ~24 Zeilen pro Zähler in 2 Minuten,
  die aufsummiert wurden. Betraf drei Stellen: `/api/v1/live`, das öffentliche Live-Dashboard
  (`/api/live/:slug`, inkl. Tageskennzahl und Zeitreihen-Chart) sowie die "Community-
  Gesamtleistung live"-Kachel im Obmann-Dashboard. Behoben mit `DISTINCT ON (metering_point_id)`
  (jeweils nur die neueste Zeile pro Zähler) statt blindem `SUM()` über das Zeitfenster.
- **Konfigurierbare ESP-Offline-Schwelle (Patrick 30.07.2026):** Bisher galt ein ESP als
  online, solange die letzte Statusmeldung "online" war -- ganz ohne Rücksicht darauf, wie
  lange das her ist. Ein hängengebliebenes Gerät (TCP-Verbindung technisch noch offen,
  Firmware aber abgestürzt) hätte theoretisch für immer als online angezeigt werden können,
  weil das MQTT-Last-Will-Testament dann nie auslöst. Jetzt zusätzliche, konfigurierbare
  Zeitschwelle (Platform-Admin → E-Mail-Einstellungen → neuer Abschnitt „ESP32 /
  Ausleseeinheiten", Standard 5 Minuten): ein Gerät gilt nur online, wenn `esp_online` UND
  `esp_last_seen_at` nicht älter als die Schwelle ist. Gleichzeitig die Einstellungsseite in
  „Plattform-Technik" und „E-Mail (Microsoft Graph)" unterteilt, damit klar ist, was
  zusammengehört.
- **MQTT-Broker mit TLS + Benutzername/Passwort (Patrick 30.07.2026):** Mosquitto lief bisher
  mit `allow_anonymous true` und ganz ohne Verschlüsselung -- für ein internes Testnetz tragbar,
  aber eine Voraussetzung für den echten Rollout (Mitglieder-ESP32s zuhause, außerhalb des
  eigenen Netzes). `scripts/mqtt_secure_setup.sh` erzeugt ein selbstsigniertes Zertifikat
  (Port 8883, 10 Jahre gültig) und Zugangsdaten (`.env`: `MQTT_USER`/`MQTT_PASSWORD`), schreibt
  die Mosquitto-Passwort-Datei und startet die betroffenen Container neu. Firmware wählt
  automatisch `WiFiClientSecure` (TLS, `setInsecure()` -- kein Zertifikat auf dem Gerät nötig),
  sobald `mqtt-port` auf 8883 gestellt wird; Benutzername/Passwort trägt man im selben
  `/config`-Formular ein. Externer Zugriff (Router-Port-Forward) noch nicht eingerichtet,
  siehe `CLAUDE.md`.
- **Eigenes Live-Daten-Intervall (Patrick 30.07.2026):** Live-Werte (Bezug/Einspeisung) waren
  fix auf 30 s gedrosselt -- über dieselbe Variable wie der ESP-Online-Heartbeat, obwohl beides
  nichts miteinander zu tun hat. Jetzt eigenes Feld `live-daten-intervall` im `/config`-Formular,
  Standard 5 s, unabhängig vom Heartbeat-Intervall.
- **WLAN-Passwort-Sendefrequenz, zweimal überarbeitet (Patrick 30.07.2026):** Ursprünglich nur
  EINMALIG beim allerersten MQTT-Connect nach dem Boot mitgeschickt -- ein fragiles Design:
  verpasst die Firmware genau diesen einen Moment (z. B. weil der Broker im exakten Augenblick
  noch nicht bereit war), kommt das Passwort für die gesamte restliche Laufzeit nie an, während
  SSID/IP trotzdem bei jedem Heartbeat ankamen. Genau das ist Patrick beim ersten Testgerät
  passiert -- als erster Fix wurde das Passwort testweise bei JEDEM periodischen Heartbeat
  mitgeschickt. Nach kurzer Rücksprache (jetzt mit TLS auf Port 8883 zwar unbedenklich
  verschlüsselt, aber unnötig oft auf der Leitung) **finale Lösung:** Passwort nur noch beim
  MQTT-(Re-)Connect (`mqttReconnect()`) senden -- das deckt "einmal pro Boot" UND "bei einem
  WLAN-Wechsel" ab, weil ein Wechsel über das Config-Formular immer `ESP.restart()` auslöst.
  SSID/IP weiterhin bei jedem Heartbeat. Plattformseitig behält `metering_points` den zuletzt
  bekannten SSID/IP/Passwort-Wert, bis tatsächlich ein neuer ankommt, und meldet eine offene
  Postfach-Benachrichtigung bei echtem SSID-Wechsel (nicht bei reiner IP-Änderung, die
  passiert routinemäßig per DHCP).
- **Benachrichtigung bei unbekannter Zählernummer (Patrick 30.07.2026):** Sendet ein Gerät
  Daten für eine Zählernummer, die (noch) keinem Zählpunkt in der jeweiligen EEG zugeordnet
  ist, landete das bisher NUR im Container-Log des `mqtt-subscriber` (unsichtbar für Obmänner/
  Admins) -- obwohl genau das ursprünglich gewünscht war. Jetzt erzeugt der erste solche
  Vorfall eine offene Benachrichtigung im Postfach (`/portal/postfach`), solange bis der
  passende Zählpunkt angelegt/korrigiert und die Meldung erledigt wird (kein Spam bei jeder
  einzelnen Nachricht -- nur eine offene Meldung je Zählernummer).
- **Zählernummer-Abgleich vor dem Publish (Patrick 30.07.2026):** Die Plattform ordnet
  eingehende Live-/Status-Daten ausschließlich anhand der im MQTT-**Topic** übertragenen
  Zählernummer einem Zählpunkt zu -- das ist die im `/config`-Formular des ESP manuell
  eingetragene `cfgZaehler`, NICHT die tatsächlich aus dem P1-Telegramm gelesene Nummer. Ohne
  Abgleich hätte ein Tippfehler in der Konfiguration Daten unbemerkt dem falschen Zählpunkt
  zugeordnet. Die Firmware vergleicht jetzt vor jedem Publish beide Werte (`topicSafe()` auf
  beiden Seiten, damit Füllzeichen im Telegramm keinen falschen Mismatch auslösen) und sendet
  bei Abweichung nicht -- das lokale Dashboard (`/`, `/data`) zeigt die gelesenen Werte trotzdem
  an, damit ein Mismatch beim Einrichten überhaupt auffällt.
- **Punkt 4 (Zähler-Erreichbarkeit für Inselbetrieb-Erkennung, Patrick 30.07.2026):** Die
  Firmware erfasst jetzt getrennt vom ESP-eigenen Online-Status, ob zuletzt ein gültiges
  P1-Telegramm vom Smart Meter empfangen wurde (`meter_ok` im Status-Heartbeat), und schickt
  das mit. Grund: Bei Stromausfall/Inselbetrieb beim Mitglied bleibt der ESP über WLAN unter
  Umständen erreichbar (z. B. Akku/Notstrom), verliert aber die Kommunikation zum Zähler —
  daran lässt sich erkennen, dass ein Problem beim Kunden liegt und nicht an der Plattform
  oder am ESP selbst. Neue Spalten `metering_points.meter_reachable` / `meter_last_seen_at`
  (`migrate_20260820.sql`), Anzeige als eigene Spalte in `member_detail.php` sowie als Warn-
  Badge in der Status-Kachelzeile des Manager-Dashboards.
- **Punkt 1 (WLAN-Diagnoseinfos vom ESP an die Plattform, Patrick 30.07.2026):** Die Firmware
  schickt beim Status-Heartbeat jetzt zusätzlich `ssid`, `ip` und (nur bei Änderung, damit es
  nicht bei jedem Heartbeat unnötig erneut verschlüsselt wird) `wifi_password` mit. Landet
  verschlüsselt (`wifi_password_enc`) in `metering_points`, sichtbar für Obmänner/Admins über
  den „WLAN-Info anzeigen"-Klick in `member_detail.php` (bestehende Umsetzung von 2026-07-29,
  jetzt mit echten Daten statt nur vorbereiteten Spalten).
- **Punkt 2 (API-Schnittstelle für Live-Energiedaten):** `GET /api/v1/live` liefert Bezug/
  Einspeisung des Mitglieds in Watt (aus `esp_measurements`, letzte 2 Minuten) + die
  Autarkie-Quote der gesamten Community, gleiches Bearer-Token-Auth-Muster wie `/api/v1/me`.
  Gibt `0`-Werte zurück statt eines Fehlers, solange das Mitglied noch keine eigene
  Ausleseeinheit hat. Siehe Platform-Task #64 und #83. 2026-07-29.
- **ESP-Online-/Zuletzt-online-Tracking** (Grundlage für Punkt 1+4): `metering_points.esp_online`
  / `esp_last_seen_at`, gespeist über den bereits vom ESP32-Sketch gesendeten
  Status-Heartbeat (`eeg/{rc}/meter/{znr}/status`). 2026-07-29 (Spalten am 30.07.2026 von
  `esb_*` auf `esp_*` umbenannt, siehe Hinweis oben).
