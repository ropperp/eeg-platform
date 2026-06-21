# EEG SaaS-Plattform — Projektübersicht für Claude

## Projekt
Diplomarbeit HTL: Multi-Tenant SaaS für österreichische Energiegemeinschaften (EEG).
Repo: `ropperp/eeg-platform` | Branch: `claude/awesome-mendel-otivt9`

## Stack
- **webapp**: nginx + PHP 8.2 (kein Framework), PostgreSQL/TimescaleDB via psycopg2-ähnlichem DB-Helper
- **mqtt-subscriber**: Python 3.11, paho-mqtt 2.1.0 (`CallbackAPIVersion.VERSION1`), psycopg2
- **DB**: TimescaleDB (PostgreSQL 16), Redis (Sessions)
- **Proxy**: Traefik v3.1 (TLS, Let's Encrypt)
- **MQTT**: Eclipse Mosquitto 2

## Wichtige Datenschutz-Regel
**Personenbezogene Daten bleiben in der DB. NIEMALS nach GitHub pushen.**

---

## Datenmodell (Kern)

### Drei Zählerkennungen — klar trennen

| Feld | Tabelle | Bedeutung | MQTT-Topic |
|------|---------|-----------|------------|
| `zaehlpunkt_nr` | `metering_points` | AT0070000... — EDA-Pfad (Netzbetreiber) | `/live` (Legacy) |
| `meter_code` | `metering_points` | 13-stellig aus EDA-XLSX | `/live` |
| `zaehler_nr` | `metering_points` | 13-stellige ESP32-Gerätenummer | `/power` |

Ein physischer Zähler kann **zwei** `metering_points`-Einträge haben:
- `type = 'consumer'` → AT...-Nr. für Bezug (EDA)
- `type = 'producer'` → AT...-Nr. für Einspeisung (EDA)

Beide teilen die gleiche `zaehler_nr`. Der MQTT-Subscriber wählt via `ORDER BY type LIMIT 1` konsistent den `consumer`-Eintrag.

### esp_measurements (TimescaleDB Hypertable)
```
time                 TIMESTAMPTZ
community_id         UUID
metering_point_id    UUID  → FK zu metering_points
power_bezug_w        INTEGER   -- Momentanleistung Bezug (W), 0 wenn Einspeisung
power_einspeisung_w  INTEGER   -- Momentanleistung Einspeisung (W), 0 wenn Bezug
energy_bezug_wh      BIGINT    -- Zählerstand Bezug (Wh) vom ESP
energy_einspeisung_wh BIGINT   -- Zählerstand Einspeisung (Wh) — aktuell immer 0
znr                  TEXT      -- zaehler_nr aus Topic-Pfad
```

**Wichtig**: `power_w` vom ESP ist vorzeichenbehaftet. Subscriber setzt:
- `power_w > 0` → `power_bezug_w = power_w`, `power_einspeisung_w = 0`
- `power_w < 0` → `power_bezug_w = 0`, `power_einspeisung_w = abs(power_w)`

### Live-Queries: DISTINCT ON (znr) verwenden
Ein Zähler kann in 2 Minuten mal Bezug, mal Einspeisung gehabt haben → SUM würde beide > 0 anzeigen.
**Korrektes Muster** für Momentanwert:
```sql
SELECT COALESCE(SUM(latest.power_bezug_w), 0) AS bezug_w,
       COALESCE(SUM(latest.power_einspeisung_w), 0) AS einsp_w,
       COUNT(*) AS active_meters
FROM (
    SELECT DISTINCT ON (COALESCE(znr, metering_point_id::TEXT))
           power_bezug_w, power_einspeisung_w
    FROM esp_measurements
    WHERE community_id = ? AND time >= now() - INTERVAL '2 minutes'
    ORDER BY COALESCE(znr, metering_point_id::TEXT), time DESC
) latest
```

---

## MQTT-Spec (ESP32 Firmware)

### Topic-Format
```
eeg/<rc-nummer>/meter/<zaehler_nr>/power
```
- `rc-nummer`: Marktpartner-ID der Gemeinschaft (z.B. `rc108175`), case-insensitive
- `zaehler_nr`: 13-stellige Zählernummer des ESP32-Geräts

### Payload (JSON)
```json
{
  "power_w": 1234,
  "meter_reading": 5678901,
  "ts": "2026-06-21T20:03:50Z"
}
```
| Feld | Typ | Pflicht | Beschreibung |
|------|-----|---------|--------------|
| `power_w` | int | ja | Momentanleistung in Watt. **Vorzeichen**: positiv = Netzbezug, negativ = Einspeisung |
| `meter_reading` | int | ja | Zählerstand Bezug in **Wh** (nicht kWh) |
| `ts` | string | nein | ISO 8601 UTC. Weglassen wenn kein NTP — Server nutzt dann eigene Zeit |

**Hinweise für ESP-Firmware**:
- `znr` gehört **NICHT** in den Payload — kommt aus dem Topic-Pfad
- Gleichzeitig Bezug UND Einspeisung ist physisch unmöglich → bei Bezug positiv, bei Einspeisung negativ
- `meter_reading` = Bezugs-Zählerstand. Einspeise-Zählerstand wird aktuell NICHT per MQTT gesendet
- QoS 0 reicht für Echtzeit-Visualisierung
- Publish-Intervall: 1–5 Sekunden empfohlen

---

## mqtt-subscriber/main.py — Kritische Details

### paho-mqtt 2.1.0
```python
client = mqtt.Client(
    mqtt.CallbackAPIVersion.VERSION1,   # VERSION2 bricht on_message-Dispatch
    client_id="eeg-mqtt-subscriber",
)
```
- `on_disconnect` muss 3 Parameter haben: `(client, userdata, rc)` — VERSION1-Signatur
- paho v2 **schluckt** Callback-Exceptions intern (nur DEBUG-Log sichtbar)
- Deshalb: on_message komplett in `try/except Exception: log.exception(...)` wrappen

### DB-Pool (psycopg2 ThreadedConnectionPool)
- `_db_select_one()` rollt bei Exception zurück → Pool-Vergiftung durch `InFailedSqlTransaction` verhindert
- Negative Treffer (kein MP gefunden) werden **nicht** gecacht → späte zaehler_nr-Zuweisung wirkt sofort

### Dedup-Logik (handle_unassigned_meter)
- 6-Stunden-Fenster: nur eine Warnung pro zaehler_nr pro 6h
- Nach Dedup-Fire: nur `log.debug` → **unsichtbar bei INFO-Level** → scheinbar stille Nachrichten
- Diagnose: nach `active = true` setzen löst sich von selbst auf

---

## Webapp-Routen (index.php)

| Route | Auth | Beschreibung |
|-------|------|--------------|
| `GET /api/portal/live` | Login required | Live-Werte für aktive Community (AJAX-Polling, 5s) |
| `GET /api/live/:slug` | public | Öffentliches Live-Dashboard |
| `GET /api/communities/search` | public | Community-Suche |
| `GET /portal/dashboard` | Login | Manager- oder Member-Dashboard |
| `GET /portal/members` | Manager | Mitgliederliste |
| `GET /portal/members/:id` | Manager | Mitglieder-Detail inkl. Zählpunkte |

---

## Datenbankmigrationen
Bereits gelaufen:
- `database/migrate_20260622.sql`: Spalte `zaehler_nr TEXT` + Index auf `metering_points`

Neue Instanz aufsetzen: `database/init.sql` enthält das vollständige Schema inkl. `zaehler_nr`.

---

## Docker / Deployment
```bash
# Nach Code-Änderungen:
git pull origin claude/awesome-mendel-otivt9
docker compose build --no-cache mqtt-subscriber webapp
docker compose up -d

# Logs prüfen:
docker compose logs mqtt-subscriber --tail=20
docker compose logs webapp --tail=20

# DB-Direktzugriff:
docker compose exec timescaledb psql -U eeg -d eeg_platform
```

**Wichtig**: `docker compose build` ohne `--no-cache` kann alte Codestände cachen → bei mqtt-subscriber immer `--no-cache` verwenden wenn main.py geändert wurde.

---

## Bekannte Fallstricke
1. **metering_points.active = false**: Portal-Löschen setzt `active = false` (Soft-Delete). MQTT-Subscriber findet den MP nicht → `handle_unassigned_meter` → 6h-Dedup → stille `log.debug` → keine Zeilen in esp_measurements
2. **Zwei MPs gleiche zaehler_nr**: Subscriber wählt per `ORDER BY type LIMIT 1` immer 'consumer'. Live-Queries nutzen `DISTINCT ON (znr)` gegen Doppelzählung
3. **paho VERSION2**: Bricht on_message-Dispatch mit VERSION1-Signaturen. Immer VERSION1 verwenden
