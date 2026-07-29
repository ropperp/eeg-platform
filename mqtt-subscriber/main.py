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
Firmware) -- Online/Zuletzt-online-Tracking der Ausleseeinheit (ESB), siehe docs/ESB_IDEEN.md
Punkt 2 und esp32-firmware/p1-smart-meter/. Payload: {"status": "online"|"offline", "ts": "...",
"ssid": "...", "ip": "...", "wifi_password": "..."} -- ssid/ip/wifi_password sind optional
(aktueller Firmware-Stand schickt sie noch nicht mit, siehe ESB_IDEEN.md Punkt 1).
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
DB_HOST = os.environ["DB_HOST"]
DB_PORT = os.environ.get("DB_PORT", "5432")
DB_USER = os.environ["DB_USER"]
DB_PASSWORD = os.environ["DB_PASSWORD"]
DB_NAME = os.environ["DB_NAME"]
APP_SECRET = os.environ.get("APP_SECRET", "")

db_pool: ThreadedConnectionPool | None = None
community_cache: dict[str, str] = {}        # slug → community_id (UUID)
metering_point_cache: dict[str, str] = {}  # "community_id:znr" → metering_point UUID

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


def get_metering_point_uuid(community_id: str, zaehlernummer: str) -> str | None:
    """Sucht Metering-Point-UUID anhand der 13-stelligen Zählernummer (meter_code)."""
    cache_key = f"{community_id}:{zaehlernummer}"
    if cache_key in metering_point_cache:
        return metering_point_cache[cache_key]
    pool = get_db_pool()
    conn = pool.getconn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id FROM metering_points WHERE community_id = %s AND meter_code = %s",
                (community_id, zaehlernummer)
            )
            row = cur.fetchone()
            if row:
                metering_point_cache[cache_key] = str(row[0])
                return metering_point_cache[cache_key]
    finally:
        pool.putconn(conn)
    return None


def encrypt_secret(plain: str) -> str:
    """AES-256-CBC mit zufälligem IV, kompatibel zu encryptSecret()/decryptSecret() in
    webapp/src/functions.php (gleicher Schlüssel: sha256(APP_SECRET), IV dem Ciphertext
    vorangestellt, beides base64) -- fürs ESB-WLAN-Passwort, siehe docs/ESB_IDEEN.md Punkt 1."""
    key = hashlib.sha256(APP_SECRET.encode()).digest()
    iv = os.urandom(16)
    cipher = AES.new(key, AES.MODE_CBC, iv)
    ct = cipher.encrypt(pad(plain.encode("utf-8"), AES.block_size))
    return base64.b64encode(iv + ct).decode()


def update_status(community_id: str, metering_point_id: str, payload: dict) -> None:
    """Online/Zuletzt-online + optionale WLAN-Diagnosefelder aus dem Status-Heartbeat
    (eeg/{rc}/meter/{znr}/status) in metering_points schreiben, siehe migrate_20260817.sql.

    esb_last_seen_at wird bewusst NUR bei status=online aktualisiert -- bei einer
    Offline-Meldung (auch per Last-Will-Testament beim Verbindungsabbruch) soll der
    Zeitpunkt weiterhin den letzten BESTÄTIGTEN Online-Moment zeigen ("zuletzt online: vor
    X Minuten"), nicht den Zeitpunkt der Offline-Erkennung selbst.
    """
    online = payload.get("status") == "online"
    last_seen_sql = ", esb_last_seen_at = now()" if online else ""
    pool = get_db_pool()
    conn = pool.getconn()
    try:
        with conn.cursor() as cur:
            if payload.get("wifi_password"):
                cur.execute(
                    f"""
                    UPDATE metering_points
                    SET esb_online = %s{last_seen_sql},
                        wifi_ssid = COALESCE(%s, wifi_ssid), wifi_ip = COALESCE(%s, wifi_ip),
                        wifi_password_enc = %s
                    WHERE id = %s
                    """,
                    (online, payload.get("ssid"), payload.get("ip"),
                     encrypt_secret(payload["wifi_password"]), metering_point_id)
                )
            else:
                cur.execute(
                    f"""
                    UPDATE metering_points
                    SET esb_online = %s{last_seen_sql},
                        wifi_ssid = COALESCE(%s, wifi_ssid), wifi_ip = COALESCE(%s, wifi_ip)
                    WHERE id = %s
                    """,
                    (online, payload.get("ssid"), payload.get("ip"), metering_point_id)
                )
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        pool.putconn(conn)


def insert_measurement(community_id: str, metering_point_id: str, payload: dict) -> None:
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
                    payload.get("pp", 0),
                    payload.get("pm", 0),
                    payload.get("ep", 0),
                    payload.get("em", 0),
                    payload.get("znr"),
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

    metering_point_uuid = get_metering_point_uuid(community_id, zaehlernummer)
    if not metering_point_uuid:
        log.warning("Unbekannte Zählernummer %s für Community %s — Topic ignoriert", zaehlernummer, community_slug)
        return

    if kind == "status":
        try:
            update_status(community_id, metering_point_uuid, payload)
            log.debug("Status aktualisiert: %s → %s", msg.topic, payload.get("status"))
        except Exception as e:
            log.error("DB-Fehler bei %s: %s", msg.topic, e)
        return

    # Plausibilitätsprüfung (aus ESP32-Doku: > 100.000 W ist Fehler)
    if payload.get("pp", 0) > 100_000 or payload.get("pm", 0) > 100_000:
        log.warning("Unplausibler Messwert auf %s: %s", msg.topic, payload)
        return

    try:
        insert_measurement(community_id, metering_point_uuid, payload)
        log.debug("Gespeichert: %s → %s W Bezug", msg.topic, payload.get("pp"))
    except Exception as e:
        log.error("DB-Fehler bei %s: %s", msg.topic, e)


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
