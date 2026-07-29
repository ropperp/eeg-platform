# P1 Smart Meter Reader (ESB) — ESP32

Firmware für die **Ausleseeinheit (ESB)**, die Mitglieder der EEG Strompool Feldkirchen
Süd-West erhalten: liest die P1-Kundenschnittstelle des Kärnten-Netz-Smart-Meters
(Iskraemeco AM550) aus, entschlüsselt die DLMS/COSEM-Frames (AES-128-GCM) und schickt
Bezug/Einspeisung live per MQTT an die Plattform.

> **Stand:** Erster funktionierender Prototyp (Stand 2026-07-29), wird noch überarbeitet.
> Diese Datei dokumentiert den Code-Stand zum Zeitpunkt der Aufnahme ins Repo — nicht
> zwangsläufig, was aktuell auf einem Gerät im Feld läuft.

## Hardware

- SBC-NODEMCU-ESP32
- BC547-Transistor (Pegelanpassung + Invertierung), R1 10 kΩ, R2 4,7 kΩ, R3 1 kΩ, 1N5819-Diode
- RJ12-Stecker an der P1-Kundenschnittstelle

Pinout siehe Kopfkommentar in `sketch_ESP32_P1_Smart_Meter.ino`.

## Funktionsumfang (Stand dieses Commits)

- WLAN-Setup per Captive Portal (AP-Modus, Netzwerk-Scan, Speichern in Flash via `Preferences`)
- Zählpunkt-/RC-Nummer-Zuordnung + AES-Key-Eingabe über ein zweites Web-Formular
  (`/config`, HTTP-Basic-Auth)
- DLMS/COSEM-Frame-Parsing (P+/P- Zählerstand, Momentanleistung) aus dem AES-GCM-entschlüsselten
  Klartext
- MQTT-Publish der Live-Werte auf `eeg/{rc}/meter/{zaehler}/live`
  (Payload: `{"pp":…,"pm":…,"ep":…,"em":…,"znr":"…"}`, siehe `mqtt-subscriber/main.py`)
- **Status-Heartbeat** auf `eeg/{rc}/meter/{zaehler}/status` (retained, mit Last-Will-Testament
  `{"status":"offline"}` bei Verbindungsabbruch) — Grundlage für das Online/Zuletzt-online-Tracking
  auf der Plattform (siehe `docs/ESB_IDEEN.md`, Punkt 2)
- OTA-Updates (`ArduinoOTA`) — Firmware-Updates ohne Vor-Ort-Termin beim Mitglied

## Bezug zur Plattform (`eeg-platform`-Repo)

- `mqtt-subscriber/main.py` konsumiert `eeg/+/meter/+/live` und `eeg/+/meter/+/status` und schreibt
  in `esp_measurements` bzw. die Online/Zuletzt-online-Spalten von `metering_points`.
- Offene Ideen/Abhängigkeiten zwischen Firmware und Plattform: siehe
  [`docs/ESB_IDEEN.md`](../../docs/ESB_IDEEN.md) — dort bitte auch neue Ideen von der
  Firmware-Seite ergänzen, nicht nur von der Plattform-Seite.

## Bekannter Punkt zum Nacharbeiten

`httpPass`/`ArduinoOTA`-Passwort sind aktuell als Klartext-Konstante im Sketch hinterlegt
(`GreenData2026!`, geteilt über alle Geräte). Für den echten Rollout sinnvoll: pro Gerät
individuell setzen (z. B. beim Setup generiert und im `/config`-Formular anzeigbar, analog zum
AES-Key-Feld) statt eines für alle Geräte identischen, im Quellcode sichtbaren Passworts.
