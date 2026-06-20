# Node-RED Testflow — MQTT → EEG-Plattform

Dieser Flow simuliert zwei ESP32-Szenarien ohne echte Hardware:
- **Gültiger Fall**: bekannter Zählpunkt → Messwert landet in `esp_measurements`
- **Fehlerfall**: unbekannter Zählpunkt → Postfach-Meldung + Audit-Eintrag

---

## Voraussetzungen

| Was | Wo |
|-----|----|
| Node-RED | lokal oder auf dem Raspi (Port 1880) |
| MQTT-Broker | Mosquitto im Docker-Stack (intern Port 1883, extern je nach Konfiguration) |
| Pilot-Community | `marktpartner_id = RC108175` muss in der DB existieren |
| Bekannter Zählpunkt | ein Eintrag in `metering_points` mit `zaehlpunkt_nr = AT0070000956010000000000000689442` (oder ein anderer aktiver Zählpunkt der Community) |

---

## Topic-Format

```
eeg/<RC-Nummer>/meter/<zaehlpunkt_nr>/power
```

| Segment | Inhalt |
|---------|--------|
| `RC-Nummer` | `communities.marktpartner_id` (z.B. `RC108175`) — Groß-/Kleinschreibung egal |
| `zaehlpunkt_nr` | `metering_points.zaehlpunkt_nr` (AT...) — wird intern auf Großschreibung normiert |

---

## Payload-Format

```json
{
  "power_w": 1450,
  "meter_reading": 21873000,
  "ts": "2026-06-20T10:00:00Z"
}
```

| Feld | Typ | Einheit | Hinweis |
|------|-----|---------|---------|
| `power_w` | integer | Watt | positiv = Bezug, negativ = Einspeisung |
| `meter_reading` | integer | Wh | absoluter Zählerstand |
| `ts` | string | ISO-8601 | wird nur im Fehler-Payload gespeichert, Messzeitpunkt ist `now()` |

---

## Flow-Aufbau in Node-RED

### Nodes

```
[inject] → [function: Gültiger Fall]  → [mqtt out: power/gültig]
[inject] → [function: Fehlerfall]     → [mqtt out: power/fehler]
```

### 1. MQTT-out-Node (für beide Flows gleich)

| Feld | Wert |
|------|------|
| Server | `localhost:1883` (oder `<raspi-ip>:1883`) |
| QoS | 1 |
| Retain | false |
| Topic | wird von der Function gesetzt |

### 2. Function-Node „Gültiger Fall"

```javascript
// Bekannten Zählpunkt ersetzen, falls ein anderer in der DB vorhanden ist
const zaehlpunkt = "AT0070000956010000000000000689442";

msg.topic   = `eeg/RC108175/meter/${zaehlpunkt}/power`;
msg.payload = JSON.stringify({
    power_w:       Math.round(800 + Math.random() * 1200),   // 800–2000 W Bezug
    meter_reading: 21873000 + Math.round(Math.random() * 10),
    ts:            new Date().toISOString()
});
return msg;
```

### 3. Function-Node „Fehlerfall"

```javascript
msg.topic   = "eeg/RC108175/meter/AT00000000000000000000000000UNKNOWN/power";
msg.payload = JSON.stringify({
    power_w:       500,
    meter_reading: 0,
    ts:            new Date().toISOString()
});
return msg;
```

### 4. Inject-Nodes

| Inject | Interval | Beschreibung |
|--------|----------|--------------|
| Gültiger Fall | alle 5 s | simuliert kontinuierlichen ESP32-Datenstrom |
| Fehlerfall | alle 30 s | löst Alarm aus (Dedupe: max. 1 × alle 6 h) |

---

## Flow als JSON importieren

Node-RED → Hamburger-Menü → Import → JSON einfügen:

```json
[
  {
    "id": "inject-valid",
    "type": "inject",
    "name": "Gültiger Fall (5s)",
    "repeat": "5",
    "crontab": "",
    "once": true,
    "wires": [["fn-valid"]]
  },
  {
    "id": "fn-valid",
    "type": "function",
    "name": "Payload: Gültiger Fall",
    "func": "const znr = \"AT0070000956010000000000000689442\";\nmsg.topic = `eeg/RC108175/meter/${znr}/power`;\nmsg.payload = JSON.stringify({\n  power_w: Math.round(800 + Math.random() * 1200),\n  meter_reading: 21873000 + Math.round(Math.random() * 10),\n  ts: new Date().toISOString()\n});\nreturn msg;",
    "outputs": 1,
    "wires": [["mqtt-out"]]
  },
  {
    "id": "inject-error",
    "type": "inject",
    "name": "Fehlerfall (30s)",
    "repeat": "30",
    "crontab": "",
    "once": false,
    "wires": [["fn-error"]]
  },
  {
    "id": "fn-error",
    "type": "function",
    "name": "Payload: Fehlerfall",
    "func": "msg.topic = \"eeg/RC108175/meter/AT00000000000000000000000000UNKNOWN/power\";\nmsg.payload = JSON.stringify({\n  power_w: 500,\n  meter_reading: 0,\n  ts: new Date().toISOString()\n});\nreturn msg;",
    "outputs": 1,
    "wires": [["mqtt-out"]]
  },
  {
    "id": "mqtt-out",
    "type": "mqtt out",
    "name": "EEG-Broker",
    "topic": "",
    "qos": "1",
    "retain": "false",
    "broker": "mqtt-broker-config",
    "wires": []
  },
  {
    "id": "mqtt-broker-config",
    "type": "mqtt-broker",
    "name": "Mosquitto lokal",
    "broker": "localhost",
    "port": "1883",
    "clientid": "nodered-test",
    "keepalive": "60",
    "usetls": false,
    "protocolVersion": "4"
  }
]
```

> **Hinweis:** Die Broker-Adresse `localhost` anpassen wenn Node-RED nicht auf
> demselben Host wie Mosquitto läuft (z.B. `192.168.x.x` oder `raspi.local`).

---

## Verifikation

### Gültiger Fall

```bash
# Neue Zeile in esp_measurements prüfen
docker compose exec timescaledb psql -U eeg -d eeg_platform \
  -c "SELECT time, power_bezug_w, energy_bezug_wh FROM esp_measurements ORDER BY time DESC LIMIT 5;"

# Live-Dashboard im Browser öffnen
# → http://localhost/live  (Autarkie-Anzeige + Watt-Wert sollte sich aktualisieren)
```

### Fehlerfall

```bash
# Postfach-Meldungen prüfen
docker compose exec timescaledb psql -U eeg -d eeg_platform \
  -c "SELECT audience, title, created_at FROM notifications WHERE type='unassigned_meter' ORDER BY created_at DESC LIMIT 5;"

# Audit-Log prüfen
docker compose exec timescaledb psql -U eeg -d eeg_platform \
  -c "SELECT action, entity_id, created_at FROM audit_log WHERE action='meter.unassigned' ORDER BY created_at DESC LIMIT 5;"

# Im Portal prüfen:
# Manager → /portal/postfach  (Meldung „Unbekannter Zählpunkt")
# Admin   → /admin/audit      (Eintrag meter.unassigned)
```

### Deduplizierung

Der Subscriber schreibt die Meldung **maximal alle 6 Stunden** pro Zählpunkt.
Bei wiederholtem Senden des Fehlerfalls bleibt die Zahl der `notifications`-Zeilen
stabil — kein Spam im Postfach.

```bash
# Dedupe-Verhalten beobachten (Wiederholung 2× innerhalb 6 h → nur 1 Eintrag)
docker compose exec timescaledb psql -U eeg -d eeg_platform \
  -c "SELECT COUNT(*) FROM notifications WHERE type='unassigned_meter';"
# Erwartet: 2 (je 1 × manager, 1 × platform_admin), auch nach 10 Wiederholungen
```

---

## Bekannten Zählpunkt nachschlagen

```bash
docker compose exec timescaledb psql -U eeg -d eeg_platform \
  -c "SELECT zaehlpunkt_nr, type, active FROM metering_points WHERE community_id = (SELECT id FROM communities WHERE marktpartner_id = 'RC108175');"
```

Die ausgegebene `zaehlpunkt_nr` (AT...) in den Function-Node „Gültiger Fall" eintragen.
