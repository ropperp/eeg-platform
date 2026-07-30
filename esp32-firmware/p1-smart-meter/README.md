# P1 Smart Meter Reader — ESP32

Firmware für die **Ausleseeinheit (ESP32)**, die Mitglieder der EEG Strompool Feldkirchen
Süd-West erhalten: liest die P1-Kundenschnittstelle des Kärnten-Netz-Smart-Meters
(Iskraemeco AM550) aus, entschlüsselt die DLMS/COSEM-Frames (AES-128-GCM) und schickt
Bezug/Einspeisung live per MQTT an die Plattform.

> **Stand:** Erster funktionierender Prototyp (Stand 2026-07-29), wird noch überarbeitet.
> Diese Datei dokumentiert den Code-Stand zum Zeitpunkt der Aufnahme ins Repo — nicht
> zwangsläufig, was aktuell auf einem Gerät im Feld läuft.

## Hardware

- SBC-NODEMCU-ESP32
- RJ12-Stecker an der P1-Kundenschnittstelle
- **Seit 2026-07-30 ohne BC547-Transistor** (R1/R2/R3/Diode entfallen damit ebenfalls) --
  RJ12 Pin 5 (Daten) liegt jetzt direkt auf RX2 (GPIO16). Der Transistor hat neben der
  Invertierung des Signals (jetzt per Software, `invert=true` in `P1Serial.begin()`) auch die
  **Pegelanpassung** übernommen -- **vor dem ersten Anschließen ohne Transistor unbedingt mit
  einem Multimeter prüfen, dass die P1-Schnittstelle des Zählers dort nicht mehr als 3.3V
  ausgibt** (der ESP32-GPIO ist nicht 5V-tolerant). Falls doch 5V: nicht direkt verbinden,
  sondern einen einfachen Spannungsteiler oder wieder einen Pegelwandler dazwischenschalten.

Pinout siehe Kopfkommentar in `sketch_ESP32_P1_Smart_Meter.ino`.

## Funktionsumfang (Stand dieses Commits)

- WLAN-Setup per Captive Portal (AP-Modus, Netzwerk-Scan, Speichern in Flash via `Preferences`)
- Zählpunkt-/RC-Nummer-Zuordnung + AES-Key-Eingabe über ein zweites Web-Formular
  (`/config`, HTTP-Basic-Auth)
- DLMS/COSEM-Frame-Parsing (P+/P- Zählerstand, Momentanleistung) aus dem AES-GCM-entschlüsselten
  Klartext
- MQTT-Publish der Live-Werte auf `eeg/{rc}/meter/{zaehler}/live`, gedrosselt auf ein
  konfigurierbares Intervall (`live-daten-intervall` im `/config`-Formular, Standard **5 s** --
  bewusst getrennt vom Heartbeat-Intervall, siehe unten)
  (Payload: `{"pp":…,"pm":…,"ep":…,"em":…,"znr":"…"}`, siehe `mqtt-subscriber/main.py`). Die
  Plattform ordnet ausschließlich nach der im Topic übertragenen (= im `/config`-Formular
  eingetragenen) Zählernummer zu -- die Firmware vergleicht diese vor jedem Publish gegen die
  tatsächlich aus dem P1-Telegramm gelesene (`znr` im Payload) und sendet bei Abweichung nicht,
  damit ein Tippfehler in der Konfiguration keine Daten dem falschen Zählpunkt zuordnet.
- **Status-Heartbeat** auf `eeg/{rc}/meter/{zaehler}/status` (retained, mit Last-Will-Testament
  `{"status":"offline"}` bei Verbindungsabbruch), eigenes Intervall (`heartbeat-intervall`,
  Standard 30 s) — Grundlage für das Online/Zuletzt-online-Tracking auf der Plattform (siehe
  `docs/ESP_IDEEN.md`, Punkt 2). Enthält zusätzlich:
  - `ssid`/`ip`/`wifi_password`: bei JEDEM Heartbeat mitgeschickt (nicht nur beim Verbindungs-
    aufbau -- sonst käme das Passwort nie an, falls genau dieser eine Connect-Moment einmal
    nicht ankommt) — landet auf der Plattform verschlüsselt, nie im Klartext gespeichert
  - `meter_ok`: ob zuletzt (< 2 Minuten) ein gültiges P1-Telegramm vom Smart Meter empfangen
    wurde — getrennt vom WLAN/MQTT-Online-Status des ESP selbst, damit sich Inselbetrieb/
    Stromausfall beim Mitglied (Zähler nicht erreichbar, ESP aber online) von einem
    ESP-/Plattform-Problem unterscheiden lässt (Punkt 4)
- **MQTT über TLS (Port 8883) + Benutzername/Passwort**: `mqtt-port` im `/config`-Formular auf
  `8883` stellen schaltet automatisch auf `WiFiClientSecure` um (`setInsecure()` -- prüft das
  Server-Zertifikat nicht, verschlüsselt die Verbindung aber trotzdem; kein Zertifikat muss
  aufs Gerät verteilt werden). Benutzername/Passwort vom Obmann/Admin erhalten (siehe
  `scripts/mqtt_secure_setup.sh` im Hauptrepo) und im selben Formular eintragen -- der Broker
  verlangt das inzwischen auf beiden Ports.
- OTA-Updates (`ArduinoOTA`) — Firmware-Updates ohne Vor-Ort-Termin beim Mitglied

## Bezug zur Plattform (`eeg-platform`-Repo)

- `mqtt-subscriber/main.py` konsumiert `eeg/+/meter/+/live` und `eeg/+/meter/+/status` und schreibt
  in `esp_measurements` bzw. die Online/Zuletzt-online- und Zähler-Erreichbarkeits-Spalten von
  `metering_points`.
- Offene Ideen/Abhängigkeiten zwischen Firmware und Plattform: siehe
  [`docs/ESP_IDEEN.md`](../../docs/ESP_IDEEN.md) — dort bitte auch neue Ideen von der
  Firmware-Seite ergänzen, nicht nur von der Plattform-Seite.

## Testen ohne Hardware (simulierte Live-Daten per MQTT)

Um die komplette Pipeline (MQTT → `mqtt-subscriber` → DB → Live-Dashboard/API) durchzuspielen,
ohne dass ein ESP32 tatsächlich an einem Smart Meter hängt, reicht ein beliebiger
MQTT-Publisher (z. B. `mosquitto_pub`, in den meisten Linux-Distros über `mosquitto-clients`
verfügbar) vom selben Netz aus, das den Broker erreicht:

```bash
# -u/-P: seit dem TLS/Auth-Setup (scripts/mqtt_secure_setup.sh) auf beiden Ports nötig.
# Status-Heartbeat (setzt esp_online=true, aktualisiert esp_last_seen_at)
mosquitto_pub -h <server-ip> -p 1883 -u "$MQTT_USER" -P "$MQTT_PASSWORD" \
  -t 'eeg/rc108175/meter/<zaehlernummer>/status' -m '{"status":"online","meter_ok":true}' -r

# Live-Werte (Watt/Wh) -- alle 5-10s wiederholen, um "echte" Live-Daten zu simulieren
mosquitto_pub -h <server-ip> -p 1883 -u "$MQTT_USER" -P "$MQTT_PASSWORD" \
  -t 'eeg/rc108175/meter/<zaehlernummer>/live' \
  -m '{"pp":850,"pm":0,"ep":21000000,"em":6900000,"znr":"<zaehlernummer>"}'
```

`<zaehlernummer>` muss exakt dem `meter_code` eines existierenden, aktiven Zählpunkts in der DB
entsprechen (`rc108175` dem `marktpartner_id` der Community, klein geschrieben) -- sonst wird die
Nachricht mit „Unbekannte Zählernummer … Topic ignoriert" verworfen (siehe
`mqtt-subscriber/main.py`). Am einfachsten dafür einen eigenen Test-Zählpunkt mit einer gut
merkbaren, aber garantiert nicht echten 13-stelligen Nummer anlegen (z. B. `9999999999999`) statt
einen echten Zählpunkt eines Mitglieds zu verwenden.

## Bekannter Punkt zum Nacharbeiten

`httpPass`/`ArduinoOTA`-Passwort sind aktuell als Klartext-Konstante im Sketch hinterlegt
(`GreenData2026!`, geteilt über alle Geräte). Für den echten Rollout sinnvoll: pro Gerät
individuell setzen (z. B. beim Setup generiert und im `/config`-Formular anzeigbar, analog zum
AES-Key-Feld) statt eines für alle Geräte identischen, im Quellcode sichtbaren Passworts.
