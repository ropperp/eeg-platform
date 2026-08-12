# P1 Smart Meter Reader — ESP32

Firmware für die **Ausleseeinheit (ESP32)**, die Mitglieder der EEG Strompool Feldkirchen
Süd-West erhalten: liest die P1-Kundenschnittstelle des Kärnten-Netz-Smart-Meters
(Iskraemeco AM550) aus, entschlüsselt die DLMS/COSEM-Frames (AES-128-GCM) und schickt
Bezug/Einspeisung live per MQTT an die Plattform.

> **Stand:** Erster funktionierender Prototyp (Stand 2026-07-29), wird noch überarbeitet.
> Diese Datei dokumentiert den Code-Stand zum Zeitpunkt der Aufnahme ins Repo — nicht
> zwangsläufig, was aktuell auf einem Gerät im Feld läuft.

> **Hinweis (12.08.2026):** Der Ordner heißt bewusst genauso wie die `.ino`-Datei
> (`p1-smart-meter/p1-smart-meter.ino`) -- Arduino verlangt das für jeden Sketch und erstellt
> beim Öffnen sonst automatisch einen neuen, passend benannten Ordner. Beim Umbenennen der
> `.ino`-Datei künftig immer den Ordner mit umbenennen (und umgekehrt), sonst bricht das wieder.

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

Pinout siehe Kopfkommentar in `p1-smart-meter.ino`.

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
  - `ssid`/`ip`: bei JEDEM Heartbeat mitgeschickt
  - `wifi_password`: NUR beim MQTT-(Re-)Connect mitgeschickt (`mqttReconnect()`), nicht bei
    jedem periodischen Heartbeat -- das deckt sowohl "einmal pro Boot" als auch "bei einem
    WLAN-Wechsel" ab, da ein WLAN-Wechsel über das Config-Formular immer zu `ESP.restart()`
    führt (Stand 2026-07-30, auf Wunsch weniger häufig als zuvor). Landet auf der Plattform
    verschlüsselt, nie im Klartext gespeichert; SSID/IP werden dort beibehalten, bis ein
    tatsächlich neuer Wert ankommt (kein Überschreiben mit leeren/fehlenden Werten)
  - `meter_ok`: ob zuletzt (< 2 Minuten) ein gültiges P1-Telegramm vom Smart Meter empfangen
    wurde — getrennt vom WLAN/MQTT-Online-Status des ESP selbst, damit sich Inselbetrieb/
    Stromausfall beim Mitglied (Zähler nicht erreichbar, ESP aber online) von einem
    ESP-/Plattform-Problem unterscheiden lässt (Punkt 4)
  - `fw`: `FIRMWARE_VERSION` des Geräts, bei JEDEM Heartbeat mitgeschickt (seit 12.08.2026,
    Patrick: soll auf der Plattform sichtbar sein, ob ein Gerät schon aktualisiert hat oder ein
    Vor-Ort-Termin nötig ist). Wird unter Mitglied → Zählpunkt gegen die neueste GitHub-Release-
    Version verglichen und als Badge angezeigt ("FW 1.0.0 · aktuell" bzw. "· Update verfügbar")
- **MQTT-Fernkonfiguration** (seit 12.08.2026): Gerät abonniert `eeg/{rc}/meter/{zaehler}/cmd`
  (`onMqttMessage()`) -- die Plattform kann darüber Host/Port/Benutzer/Passwort ALLER bereits im
  Feld laufenden Geräte zentral ändern (z.B. Domain-Umzug), ohne dass am Router des Mitglieds
  irgendein Port offen sein muss (das Gerät baut die Verbindung ja selbst ausgehend auf). Details
  und Sicherheitsnetz (automatischer Rollback bei falschen neuen Werten): siehe
  `docs/ESP_IDEEN.md`.
- **MQTT über TLS (Port 8883) + Benutzername/Passwort**: `mqtt-port` im `/config`-Formular auf
  `8883` stellen schaltet automatisch auf `WiFiClientSecure` um (`setInsecure()` -- prüft das
  Server-Zertifikat nicht, verschlüsselt die Verbindung aber trotzdem; kein Zertifikat muss
  aufs Gerät verteilt werden). Benutzername/Passwort vom Obmann/Admin erhalten (siehe
  `scripts/mqtt_secure_setup.sh` im Hauptrepo) und im selben Formular eintragen -- der Broker
  verlangt das inzwischen auf beiden Ports.
- OTA-Updates (`ArduinoOTA`) — Firmware-Updates ohne Vor-Ort-Termin beim Mitglied, aber ein
  manueller "Push" vom eigenen Rechner (gleiches WLAN/Subnetz nötig). Der OTA-/mDNS-Hostname ist
  seit 03.08.2026 pro Gerät eindeutig (`p1-smartmeter-XXXX`, letzte
  4 Hex-Stellen der Chip-MAC, gleiches Muster wie der Setup-AP-Name `P1-Setup-XXXX`) — vorher
  hatten alle Geräte denselben Hostnamen `p1-smartmeter`, was bei mehreren gleichzeitig laufenden
  Geräten zu einem mDNS-Namenskonflikt führte (`dns-sd -B _arduino._tcp` zeigte dann z. B.
  `p1-smartmeter` UND `p1-smartmeter-2` gleichzeitig, nicht zuverlässig einem bestimmten
  physischen Gerät zuordenbar). Taucht der Netzwerk-Port in der Arduino-IDE (Tools → Port)
  trotzdem nicht auf: `WiFi.setSleep(false)` wird seit 30.07.2026 direkt nach dem WLAN-Connect
  gesetzt (Modem-Sleep verzögert/verwirft sonst eingehende mDNS-Multicast-Pakete, macht den Port
  "mal da, mal nicht" sichtbar). Zusätzlich prüfen: Rechner und ESP im selben WLAN/Subnetz (keine
  Client-/AP-Isolation, kein Gastnetz) -- lässt sich unabhängig von der Arduino-IDE testen mit
  `ping <hostname>.local` und `dns-sd -B _arduino._tcp` (macOS); antworten beide, liegt es nicht
  an Firewall/Netzwerk, sondern höchstens an der IDE-Portliste (Dropdown neu öffnen oder IDE neu
  starten, ~10-20 s nach dem Boot warten). Funktioniert es weiterhin nicht, per IP direkt
  hochladen (`espota.py -i <esp-ip> -p 3232 --auth=<passwort> -f firmware.bin`, IP steht im
  seriellen Log als "WLAN verbunden. IP: …" oder im Router).
- **Automatisches Firmware-Update über GitHub Releases** (seit 09.08.2026, Patrick: "dann brauch
  ich nicht mehr zu jedem Kunden zu fahren"). Anders als `ArduinoOTA` oben ist das ein "Pull" --
  jedes Gerät fragt selbstständig (Standard: stündlich, `/config` → "automatische
  firmware-updates") bei GitHub nach, ob es eine neuere passende Firmware-Version gibt, lädt sie
  bei Bedarf herunter und flasht sich selbst. Kein Kabel, kein gemeinsames WLAN, kein
  Vor-Ort-Termin nötig -- funktioniert auch bei Geräten, die schon beim Mitglied zuhause verbaut
  sind. Details, Release-Ablauf und wichtige Einschränkungen: siehe eigener Abschnitt unten.

## Automatisches Firmware-Update (GitHub Releases)

Jedes Gerät prüft periodisch (Standard: stündlich, `checkForFirmwareUpdate()` im Sketch) über
die GitHub-API, ob ein neuerer passender Release mit angehängter `.bin`-Datei existiert, lädt
sie herunter und flasht sich selbst (`HTTPUpdate`, danach automatischer Neustart).

### Neue Version veröffentlichen

1. `FIRMWARE_VERSION` im Sketch erhöhen (z. B. `"1.0.0"` → `"1.1.0"`) -- sonst erkennt kein Gerät
   den neuen Release als "neuer".
2. Kompilieren und exportieren: Arduino-IDE → Sketch → **Sketch exportieren** (oder Skizze
   kompilieren, dann die erzeugte `.bin` im Build-Ordner suchen). Die Datei vor dem Hochladen auf
   `p1-smartmeter.bin` umbenennen -- der Dateiname muss exakt passen (`OTA_ASSET_NAME` im Sketch).
3. Auf GitHub einen neuen Release anlegen:
   - Tag: `p1-smartmeter-v` + die neue Version, also z. B. `p1-smartmeter-v1.1.0` (**nicht**
     einfach `v1.1.0` -- dieses Präfix ist bewusst anders als die `vX.Y.Z`-Tags der Plattform
     selbst im selben Repo, siehe `CLAUDE.md`, damit sich die beiden nie in die Quere kommen).
   - Die `p1-smartmeter.bin` als Anhang (Asset) hochladen.
   - **Beta/interner Test:** Checkbox **"Set as a pre-release"** aktivieren. Geräte im Feld
     ignorieren Pre-Releases automatisch (per API-Feld `"prerelease":true`) -- so lassen sich
     beliebig viele Testversionen veröffentlichen, ohne dass ein Kundengerät sie je bekommt. Zum
     Testen selbst: Gerät mit `ArduinoOTA` oder Kabel manuell auf diese Version bringen.
   - **Echter Rollout:** Checkbox NICHT aktivieren ("Set as the latest release" stattdessen).
     Ab dem nächsten stündlichen Check holt sich jedes Gerät mit `cfgAutoUpdate` (Standard: an)
     automatisch die neue Version.
4. Testen: Im `/config`-Formular eines Testgeräts auf "jetzt auf update prüfen" klicken statt auf
   den nächsten stündlichen Check zu warten -- Ergebnis erscheint im Log-Ringpuffer auf der
   Startseite (`/`) des Geräts.

### Wichtige Einschränkungen (bitte vor dem ersten echten Rollout lesen)

- **Ungetestet/nicht kompiliert:** Der Code für dieses Feature wurde in dieser Sitzung geschrieben,
  aber in dieser Umgebung steht kein ESP32-Toolchain zur Verfügung -- es wurde **nicht kompiliert
  oder auf echter Hardware getestet**. Bitte zuerst auf einem Testgerät (Kabel oder `ArduinoOTA`,
  nicht automatisch) verifizieren, dass es kompiliert und der Update-Ablauf tatsächlich
  funktioniert, bevor irgendein Kundengerät `cfgAutoUpdate` aktiv hat.
- **Neue Abhängigkeit:** Bibliothek `ArduinoJson` (Benoit Blanchon, v7) muss einmalig über den
  Library Manager der Arduino-IDE installiert werden -- vorher hatte dieses Projekt keine
  JSON-Bibliothek als Abhängigkeit.
- **Kein Rollback bei kaputter Firmware.** Der Sketch nutzt aktuell keine explizite
  App-Rollback-Bestätigung (`esp_ota_mark_app_valid_cancel_rollback()`) -- eine fehlerhafte
  Version, die zwar erfolgreich flasht, sich danach aber aufhängt oder nicht mehr bootet, springt
  NICHT automatisch auf die vorherige Version zurück. Deshalb unbedingt zuerst als Pre-Release
  (Beta) auf eigener Testhardware verifizieren, dass ein Release tatsächlich stabil läuft, bevor
  er als echter (nicht-Pre-)Release for alle Kundengeräte veröffentlicht wird.
- Der Update-Vorgang selbst (Download + Flash) blockiert den ESP32 kurzzeitig (typischerweise
  wenige bis ~30 Sekunden je nach WLAN-Geschwindigkeit und Firmware-Größe) -- in dieser Zeit
  werden keine P1-Telegramme verarbeitet oder MQTT-Nachrichten gesendet, das Gerät startet
  danach automatisch neu.
- Um das GitHub-Repo für die Update-Suche zu wechseln (z. B. eigenes, separates Firmware-Repo
  statt `eeg-platform`): `OTA_UPDATE_REPO` im Sketch anpassen (Format `"owner/repo"`).

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
# Status-Heartbeat (setzt esp_online=true, aktualisiert esp_last_seen_at, esp_firmware_version)
mosquitto_pub -h <server-ip> -p 1883 -u "$MQTT_USER" -P "$MQTT_PASSWORD" \
  -t 'eeg/rc108175/meter/<zaehlernummer>/status' -m '{"status":"online","meter_ok":true,"fw":"1.0.0"}' -r

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
