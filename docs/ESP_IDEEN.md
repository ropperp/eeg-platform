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
- **WLAN-Passwort kam nicht zuverlässig an (Patrick 30.07.2026):** Wurde bisher nur EINMALIG
  beim allerersten MQTT-Connect nach dem Boot mitgeschickt -- ein fragiles Design: verpasst die
  Firmware genau diesen einen Moment (z. B. weil der Broker im exakten Augenblick noch nicht
  bereit war), kommt das Passwort für die gesamte restliche Laufzeit nie an, während SSID/IP
  trotzdem bei jedem Heartbeat ankamen. Genau das ist Patrick beim ersten Testgerät passiert.
  Jetzt wird das Passwort bei JEDEM periodischen Heartbeat mitgeschickt, nicht nur beim Connect.
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
