"""
MQTT-Subscriber: ESP32 → TimescaleDB
Topic-Format: eeg/{community_slug}/meter/{metering_point_id}/live
Payload: {"pp": 1200, "pm": 0, "ep": 21000000, "em": 6900000, "znr": "1121268533587"}
  pp = Momentanleistung Bezug (W)
  pm = Momentanleistung Einspeisung (W)
  ep = Zählerstand Bezug (Wh)
  em = Zählerstand Einspeisung (Wh)
  znr = Zählernummer

Zusätzlich: eeg/{community_slug}/meter/{znr}/status (retained, mit Last-Will-Testament der
Firmware) -- Online/Zuletzt-online-Tracking der Ausleseeinheit (ESP32), siehe docs/ESP_IDEEN.md
Punkt 2 und esp32-firmware/p1-smart-meter/. Payload: {"status": "online"|"offline", "ts": "...",
"ssid": "...", "ip": "...", "wifi_password": "...", "meter_ok": true|false, "fw": "1.0.0"} --
ssid/ip/wifi_password/meter_ok/fw sind optional (ältere Firmware-Stände schicken sie ggf. nicht
mit). meter_ok = ob der ESP zuletzt ein gültiges P1-Telegramm vom Smart Meter empfangen hat
(getrennt vom ESP-eigenen Online-Status -- bei Stromausfall/Inselbetrieb beim Mitglied bleibt
der ESP über WLAN erreichbar, verliert aber die Verbindung zum Zähler, siehe docs/ESP_IDEEN.md
Punkt 4). fw = FIRMWARE_VERSION der Firmware (Patrick, 12.08.2026: soll auf der Plattform
sichtbar sein, ob ein Gerät schon aktualisiert hat oder ein Vor-Ort-Termin nötig ist).
"""

import base64
import hashlib
import json
import logging
import os
import threading
import time
import uuid

import paho.mqtt.client as mqtt
import psycopg2
import psycopg2.extras
from Crypto.Cipher import AES
from Crypto.Util.Padding import pad
from psycopg2.pool import ThreadedConnectionPool

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s"
)
log = logging.getLogger(__name__)

MQTT_HOST = os.environ["MQTT_HOST"]
MQTT_PORT = int(os.environ.get("MQTT_PORT", 1883))
# Seit dem TLS/Auth-Setup (scripts/mqtt_secure_setup.sh, allow_anonymous=false) verlangt der
# Broker Zugangsdaten von jedem Client, auch intern.
MQTT_USER = os.environ.get("MQTT_USER", "")
MQTT_PASSWORD = os.environ.get("MQTT_PASSWORD", "")
DB_HOST = os.environ["DB_HOST"]
DB_PORT = os.environ.get("DB_PORT", "5432")
DB_USER = os.environ["DB_USER"]
DB_PASSWORD = os.environ["DB_PASSWORD"]
DB_NAME = os.environ["DB_NAME"]
APP_SECRET = os.environ.get("APP_SECRET", "")

db_pool: ThreadedConnectionPool | None = None
community_cache: dict[str, str] = {}        # slug → community_id (UUID)
metering_point_cache: dict[str, list[dict]] = {}  # "community_id:znr" → [{"id":..., "type":...}, ...]

# Heartbeat für den Docker-Healthcheck: solange die MQTT-Verbindung steht, wird diese Datei
# regelmäßig aktualisiert. Der Healthcheck (docker-compose.yml) gilt als gesund, wenn die Datei
# jünger als 90 s ist. So bekommt auch dieser Container (ohne HTTP-Endpunkt) ein echtes
# healthy/unhealthy statt nur "running".
HEARTBEAT_FILE = "/tmp/mqtt_subscriber_healthy"
_connected = False


def touch_heartbeat() -> None:
    try:
        with open(HEARTBEAT_FILE, "w") as f:
            f.write(str(time.time()))
    except OSError as e:
        log.warning("Heartbeat konnte nicht geschrieben werden: %s", e)


def heartbeat_loop() -> None:
    while True:
        if _connected:
            touch_heartbeat()
        time.sleep(20)


def get_db_pool() -> ThreadedConnectionPool:
    global db_pool
    if db_pool is None:
        dsn = f"host={DB_HOST} port={DB_PORT} dbname={DB_NAME} user={DB_USER} password={DB_PASSWORD}"
        db_pool = ThreadedConnectionPool(minconn=1, maxconn=5, dsn=dsn)
    return db_pool


def get_community_id(mqtt_id: str) -> str | None:
    """Sucht Community anhand der MQTT-ID (= LOWER(marktpartner_id), Fallback: slug)."""
    if mqtt_id in community_cache:
        return community_cache[mqtt_id]
    pool = get_db_pool()
    conn = pool.getconn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id FROM communities WHERE (LOWER(marktpartner_id) = %s OR slug = %s) AND active = true",
                (mqtt_id, mqtt_id)
            )
            row = cur.fetchone()
            if row:
                community_cache[mqtt_id] = str(row[0])
                return community_cache[mqtt_id]
    finally:
        pool.putconn(conn)
    return None


def get_metering_points(community_id: str, zaehlernummer: str) -> list[dict]:
    """Sucht ALLE aktiven Zählpunkte anhand der 13-stelligen Zählernummer (meter_code).

    In Österreich haben Bezug und Einspeisung eines Prosumers unterschiedliche
    Zählpunktnummern (AT...), teilen sich aber dieselbe Zählernummer -- ein physischer Zähler/
    eine ESP-Ausleseeinheit liefert also Daten für ZWEI Zählpunkte gleichzeitig (Patrick,
    30.07.2026, nach anfänglich falscher Annahme "ein Zählpunkt pro Zähler"). Gibt deshalb eine
    Liste zurück (normalerweise 1 Eintrag, bei einem Prosumer-Zähler 2), statt nur eine UUID."""
    cache_key = f"{community_id}:{zaehlernummer}"
    if cache_key in metering_point_cache:
        return metering_point_cache[cache_key]
    pool = get_db_pool()
    conn = pool.getconn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, type FROM metering_points WHERE community_id = %s AND meter_code = %s AND active = true",
                (community_id, zaehlernummer)
            )
            rows = cur.fetchall()
            result = [{"id": str(r[0]), "type": r[1]} for r in rows]
            # Leeres Ergebnis bewusst NICHT cachen -- sonst würde eine noch unbekannte
            # Zählernummer für immer als "unbekannt" gelten, auch nachdem sie im Portal
            # nachträglich richtig eingetragen wurde (gleiches Verhalten wie zuvor).
            if result:
                metering_point_cache[cache_key] = result
            return result
    finally:
        pool.putconn(conn)


def encrypt_secret(plain: str) -> str:
    """AES-256-CBC mit zufälligem IV, kompatibel zu encryptSecret()/decryptSecret() in
    webapp/src/functions.php (gleicher Schlüssel: sha256(APP_SECRET), IV dem Ciphertext
    vorangestellt, beides base64) -- fürs ESP-WLAN-Passwort, siehe docs/ESP_IDEEN.md Punkt 1."""
    key = hashlib.sha256(APP_SECRET.encode()).digest()
    iv = os.urandom(16)
    cipher = AES.new(key, AES.MODE_CBC, iv)
    ct = cipher.encrypt(pad(plain.encode("utf-8"), AES.block_size))
    return base64.b64encode(iv + ct).decode()


def update_status(community_id: str, metering_point_id: str, payload: dict, zaehlernummer: str) -> None:
    """Online/Zuletzt-online + optionale WLAN-Diagnosefelder aus dem Status-Heartbeat
    (eeg/{rc}/meter/{znr}/status) in metering_points schreiben, siehe migrate_20260817.sql
    und migrate_20260820.sql (meter_reachable/meter_last_seen_at).

    esp_last_seen_at wird bewusst NUR bei status=online aktualisiert -- bei einer
    Offline-Meldung (auch per Last-Will-Testament beim Verbindungsabbruch) soll der
    Zeitpunkt weiterhin den letzten BESTÄTIGTEN Online-Moment zeigen ("zuletzt online: vor
    X Minuten"), nicht den Zeitpunkt der Offline-Erkennung selbst. Gleiches Prinzip für
    meter_last_seen_at/meter_ok: das ist der ESP-eigene Online-Status (WLAN/MQTT) getrennt
    vom Zähler-Erreichbarkeitsstatus (P1-Kommunikation) -- ein ESP kann online sein, während
    der Zähler nicht erreichbar ist (z.B. Inselbetrieb/Stromausfall beim Mitglied), siehe
    docs/ESP_IDEEN.md Punkt 4. meter_ok fehlt bei älteren Firmware-Ständen im Payload und
    bleibt dann unverändert (kein Downgrade auf "nicht erreichbar" nur wegen alter Firmware).
    Gleiches Muster für esp_firmware_version (fw): COALESCE gegen den bisherigen Wert, damit
    ein einzelner Heartbeat ohne "fw" (sehr alte Firmware) den zuletzt bekannten Stand nicht
    überschreibt -- Anzeige "aktuell/Update verfügbar" siehe latestFirmwareVersion() in
    webapp/public/index.php.
    """
    online = payload.get("status") == "online"
    last_seen_sql = ", esp_last_seen_at = now()" if online else ""
    meter_ok = payload.get("meter_ok")
    meter_sql = ""
    meter_params: list = []
    if meter_ok is not None:
        meter_sql = ", meter_reachable = %s"
        meter_params.append(bool(meter_ok))
        if meter_ok:
            meter_sql += ", meter_last_seen_at = now()"
    new_ssid = payload.get("ssid")
    pool = get_db_pool()
    conn = pool.getconn()
    old_ssid = None
    try:
        with conn.cursor() as cur:
            # Alten SSID-Wert VOR dem Update lesen, um einen echten Netzwerkwechsel zu erkennen
            # (nicht bloß eine routinemäßige IP-Änderung per DHCP -- die soll laut Patrick
            # keine Meldung auslösen, siehe docs/ESP_IDEEN.md).
            if new_ssid:
                cur.execute("SELECT wifi_ssid FROM metering_points WHERE id = %s", (metering_point_id,))
                row = cur.fetchone()
                old_ssid = row[0] if row else None

            if payload.get("wifi_password"):
                cur.execute(
                    f"""
                    UPDATE metering_points
                    SET esp_online = %s{last_seen_sql}{meter_sql},
                        wifi_ssid = COALESCE(%s, wifi_ssid), wifi_ip = COALESCE(%s, wifi_ip),
                        wifi_password_enc = %s,
                        esp_firmware_version = COALESCE(%s, esp_firmware_version)
                    WHERE id = %s
                    """,
                    (online, *meter_params, payload.get("ssid"), payload.get("ip"),
                     encrypt_secret(payload["wifi_password"]), payload.get("fw"), metering_point_id)
                )
            else:
                cur.execute(
                    f"""
                    UPDATE metering_points
                    SET esp_online = %s{last_seen_sql}{meter_sql},
                        wifi_ssid = COALESCE(%s, wifi_ssid), wifi_ip = COALESCE(%s, wifi_ip),
                        esp_firmware_version = COALESCE(%s, esp_firmware_version)
                    WHERE id = %s
                    """,
                    (online, *meter_params, payload.get("ssid"), payload.get("ip"),
                     payload.get("fw"), metering_point_id)
                )
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        pool.putconn(conn)

    if new_ssid and old_ssid and new_ssid != old_ssid:
        try:
            notify_ssid_changed(community_id, zaehlernummer, old_ssid, new_ssid)
        except Exception as e:
            log.error("Konnte Benachrichtigung für SSID-Wechsel nicht schreiben: %s", e)


def insert_measurement(community_id: str, metering_point_id: str, mp_type: str, payload: dict) -> None:
    """Schreibt eine Messzeile für EINEN Zählpunkt. Eine ESP-Nachricht liefert immer beide
    Richtungen zusammen (pp/ep = Bezug, pm/em = Einspeisung) -- teilt sie sich ein physischer
    Zähler mit einem Prosumer-Zählpunktpaar (unterschiedliche Zählpunktnummern, gleiche
    Zählernummer), bekommt der Bezugs-Zählpunkt nur pp/ep und der Einspeise-Zählpunkt nur
    pm/em, jeweils mit der anderen Richtung auf 0 -- sonst würde beim Aufsummieren über die
    Zählpunkte eines Mitglieds/der Community jede Seite doppelt gezählt (Patrick, 30.07.2026).
    Ein "prosumer"-Zählpunkt (ein einzelner, offiziell kombinierter Zählpunkt) bekommt weiterhin
    beide Richtungen in einer Zeile."""
    pp = payload.get("pp", 0)
    pm = payload.get("pm", 0)
    ep = payload.get("ep", 0)
    em = payload.get("em", 0)
    if mp_type == "consumer":
        pm, em = 0, 0
    elif mp_type == "producer":
        pp, ep = 0, 0
    pool = get_db_pool()
    conn = pool.getconn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO esp_measurements
                    (time, community_id, metering_point_id,
                     power_bezug_w, power_einspeisung_w,
                     energy_bezug_wh, energy_einspeisung_wh, znr)
                VALUES (now(), %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    community_id,
                    metering_point_id,
                    pp,
                    pm,
                    ep,
                    em,
                    payload.get("znr"),
                )
            )
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        pool.putconn(conn)


def notify_unknown_meter(community_id: str, zaehlernummer: str) -> None:
    """Meldet eine unbekannte Zählernummer als offene Benachrichtigung im Postfach
    (/portal/postfach), damit der Obmann sieht, dass ein Gerät bereits Daten für einen noch
    nicht angelegten Zählpunkt sendet -- typischerweise beim erstmaligen Einrichten eines ESP32,
    bevor der passende Zählpunkt im Portal existiert. Nur EINE offene Meldung je Zählernummer,
    sonst würde jede eingehende Nachricht (alle paar Sekunden) eine neue Zeile erzeugen -- dafür
    beginnt der Text-Wert immer mit "<zaehlernummer>:", darüber wird auf ein bereits offenes
    Duplikat geprüft."""
    pool = get_db_pool()
    conn = pool.getconn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT 1 FROM notifications
                WHERE community_id = %s AND typ = 'unbekannter_zaehler' AND status = 'offen'
                  AND text LIKE %s
                """,
                (community_id, f"{zaehlernummer}:%")
            )
            if cur.fetchone():
                return
            cur.execute(
                """
                INSERT INTO notifications (community_id, typ, titel, text)
                VALUES (%s, 'unbekannter_zaehler', %s, %s)
                """,
                (
                    community_id,
                    "Unbekannte Zählernummer gemeldet",
                    f"{zaehlernummer}: Ein Gerät sendet Daten für die Zählernummer {zaehlernummer}, "
                    "die noch keinem Zählpunkt in dieser EEG zugeordnet ist. Bitte unter "
                    "„Mitglieder“ → Zählpunkte die passende Zählernummer eintragen oder die "
                    "Nummer auf dem Gerät korrigieren.",
                )
            )
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        pool.putconn(conn)


def notify_ssid_changed(community_id: str, zaehlernummer: str, old_ssid: str, new_ssid: str) -> None:
    """Meldet einen echten WLAN-Netzwerkwechsel (neue SSID) als offene Benachrichtigung im
    Postfach -- auf Wunsch von Patrick bewusst NUR bei einer geänderten SSID, NICHT bei einer
    reinen IP-Adressänderung (die passiert routinemäßig per DHCP und wäre keine Meldung wert).
    Gleiches Dedup-Muster wie notify_unknown_meter(): nur EINE offene Meldung je Zählernummer,
    sonst würde jeder weitere Heartbeat mit der neuen SSID erneut eine Zeile erzeugen."""
    pool = get_db_pool()
    conn = pool.getconn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT 1 FROM notifications
                WHERE community_id = %s AND typ = 'ssid_geaendert' AND status = 'offen'
                  AND text LIKE %s
                """,
                (community_id, f"{zaehlernummer}:%")
            )
            if cur.fetchone():
                return
            cur.execute(
                """
                INSERT INTO notifications (community_id, typ, titel, text)
                VALUES (%s, 'ssid_geaendert', %s, %s)
                """,
                (
                    community_id,
                    "WLAN-Netzwerk gewechselt",
                    f"{zaehlernummer}: Das Gerät für Zählernummer {zaehlernummer} meldet ein neues "
                    f"WLAN-Netzwerk (\"{old_ssid}\" → \"{new_ssid}\"). Falls das nicht beabsichtigt "
                    "war, bitte das Gerät und dessen WLAN-Zugangsdaten prüfen.",
                )
            )
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        pool.putconn(conn)


def on_message(client, userdata, msg: mqtt.MQTTMessage) -> None:
    # Topic: eeg/{community_slug}/meter/{znr}/live ODER eeg/{community_slug}/meter/{znr}/status
    parts = msg.topic.split("/")
    if len(parts) != 5 or parts[0] != "eeg" or parts[2] != "meter" or parts[4] not in ("live", "status"):
        log.warning("Unbekanntes Topic: %s", msg.topic)
        return

    community_slug = parts[1]
    zaehlernummer = parts[3]  # 13-stellige Zählernummer vom ESP32
    kind = parts[4]

    try:
        payload = json.loads(msg.payload.decode())
    except json.JSONDecodeError:
        log.warning("Ungültiges JSON auf Topic %s: %s", msg.topic, msg.payload)
        return

    community_id = get_community_id(community_slug)
    if not community_id:
        log.debug("Unbekannte Community-Slug: %s", community_slug)
        return

    metering_points = get_metering_points(community_id, zaehlernummer)
    if not metering_points:
        log.warning("Unbekannte Zählernummer %s für Community %s — Topic ignoriert", zaehlernummer, community_slug)
        try:
            notify_unknown_meter(community_id, zaehlernummer)
        except Exception as e:
            log.error("Konnte Benachrichtigung für unbekannte Zählernummer nicht schreiben: %s", e)
        return

    # Eine Zählernummer kann zu ZWEI Zählpunkten gehören (Bezug + Einspeisung eines Prosumers,
    # unterschiedliche Zählpunktnummern, ein physischer Zähler/eine ESP-Einheit) -- beide
    # bekommen dieselbe Status-/Messnachricht, siehe get_metering_points() (Patrick, 30.07.2026).
    if kind == "status":
        for mp in metering_points:
            try:
                update_status(community_id, mp["id"], payload, zaehlernummer)
            except Exception as e:
                log.error("DB-Fehler bei %s (Zählpunkt %s): %s", msg.topic, mp["id"], e)
        log.debug("Status aktualisiert: %s → %s (%d Zählpunkt(e))", msg.topic, payload.get("status"), len(metering_points))
        return

    # Plausibilitätsprüfung (aus ESP32-Doku: > 100.000 W ist Fehler)
    if payload.get("pp", 0) > 100_000 or payload.get("pm", 0) > 100_000:
        log.warning("Unplausibler Messwert auf %s: %s", msg.topic, payload)
        return

    for mp in metering_points:
        try:
            insert_measurement(community_id, mp["id"], mp["type"], payload)
        except Exception as e:
            log.error("DB-Fehler bei %s (Zählpunkt %s): %s", msg.topic, mp["id"], e)
    log.debug("Gespeichert: %s → %s W Bezug (%d Zählpunkt(e))", msg.topic, payload.get("pp"), len(metering_points))


def on_connect(client, userdata, flags, rc, properties=None) -> None:
    global _connected
    if rc == 0:
        _connected = True
        touch_heartbeat()
        log.info("Verbunden mit MQTT-Broker %s:%s", MQTT_HOST, MQTT_PORT)
        client.subscribe("eeg/+/meter/+/live", qos=1)
        client.subscribe("eeg/+/meter/+/status", qos=1)
        log.info("Subscribed auf eeg/+/meter/+/live und eeg/+/meter/+/status")
    else:
        log.error("MQTT-Verbindung fehlgeschlagen, rc=%s", rc)


def on_disconnect(client, userdata, disconnect_flags, rc, properties=None) -> None:
    global _connected
    _connected = False
    if rc != 0:
        log.warning("MQTT-Verbindung unterbrochen (rc=%s), reconnect in 5s...", rc)


def main() -> None:
    # Warten bis DB bereit ist
    for attempt in range(30):
        try:
            get_db_pool()
            log.info("DB-Verbindung OK")
            break
        except Exception as e:
            log.warning("DB noch nicht bereit (%s), warte... (%d/30)", e, attempt + 1)
            time.sleep(5)
    else:
        log.error("DB nicht erreichbar nach 30 Versuchen — Exit")
        raise SystemExit(1)

    client = mqtt.Client(
        mqtt.CallbackAPIVersion.VERSION2,
        client_id="eeg-mqtt-subscriber"
    )
    if MQTT_USER:
        client.username_pw_set(MQTT_USER, MQTT_PASSWORD)
    client.on_connect = on_connect
    client.on_disconnect = on_disconnect
    client.on_message = on_message

    client.reconnect_delay_set(min_delay=1, max_delay=30)

    # Heartbeat-Thread starten (Daemon -> endet mit dem Prozess).
    threading.Thread(target=heartbeat_loop, daemon=True).start()

    while True:
        try:
            client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
            client.loop_forever()
        except Exception as e:
            log.error("MQTT-Fehler: %s — reconnect in 10s", e)
            time.sleep(10)


if __name__ == "__main__":
    main()
