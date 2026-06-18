"""
MQTT-Subscriber: ESP32 → TimescaleDB
Topic-Format: eeg/{community_slug}/meter/{metering_point_id}/live
Payload: {"pp": 1200, "pm": 0, "ep": 21000000, "em": 6900000, "znr": "1121268533587"}
  pp = Momentanleistung Bezug (W)
  pm = Momentanleistung Einspeisung (W)
  ep = Zählerstand Bezug (Wh)
  em = Zählerstand Einspeisung (Wh)
  znr = Zählernummer
"""

import json
import logging
import os
import time
import uuid

import paho.mqtt.client as mqtt
import psycopg2
import psycopg2.extras
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

db_pool: ThreadedConnectionPool | None = None
community_cache: dict[str, str] = {}       # slug → community_id
metering_point_cache: dict[str, str] = {}  # metering_point_id-string → uuid check


def get_db_pool() -> ThreadedConnectionPool:
    global db_pool
    if db_pool is None:
        dsn = f"host={DB_HOST} port={DB_PORT} dbname={DB_NAME} user={DB_USER} password={DB_PASSWORD}"
        db_pool = ThreadedConnectionPool(minconn=1, maxconn=5, dsn=dsn)
    return db_pool


def get_community_id(slug: str) -> str | None:
    if slug in community_cache:
        return community_cache[slug]
    pool = get_db_pool()
    conn = pool.getconn()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM communities WHERE slug = %s AND active = true", (slug,))
            row = cur.fetchone()
            if row:
                community_cache[slug] = str(row[0])
                return community_cache[slug]
    finally:
        pool.putconn(conn)
    return None


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
    # Topic: eeg/{community_slug}/meter/{metering_point_id}/live
    parts = msg.topic.split("/")
    if len(parts) != 5 or parts[0] != "eeg" or parts[2] != "meter" or parts[4] != "live":
        log.warning("Unbekanntes Topic: %s", msg.topic)
        return

    community_slug = parts[1]
    metering_point_id = parts[3]

    try:
        payload = json.loads(msg.payload.decode())
    except json.JSONDecodeError:
        log.warning("Ungültiges JSON auf Topic %s: %s", msg.topic, msg.payload)
        return

    # Plausibilitätsprüfung (aus ESP32-Doku: > 100.000 W ist Fehler)
    if payload.get("pp", 0) > 100_000 or payload.get("pm", 0) > 100_000:
        log.warning("Unplausibler Messwert auf %s: %s", msg.topic, payload)
        return

    community_id = get_community_id(community_slug)
    if not community_id:
        log.debug("Unbekannte Community-Slug: %s", community_slug)
        return

    try:
        insert_measurement(community_id, metering_point_id, payload)
        log.debug("Gespeichert: %s → %s W Bezug", msg.topic, payload.get("pp"))
    except Exception as e:
        log.error("DB-Fehler bei %s: %s", msg.topic, e)


def on_connect(client, userdata, flags, rc, properties=None) -> None:
    if rc == 0:
        log.info("Verbunden mit MQTT-Broker %s:%s", MQTT_HOST, MQTT_PORT)
        client.subscribe("eeg/+/meter/+/live", qos=1)
        log.info("Subscribed auf eeg/+/meter/+/live")
    else:
        log.error("MQTT-Verbindung fehlgeschlagen, rc=%s", rc)


def on_disconnect(client, userdata, disconnect_flags, rc, properties=None) -> None:
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

    while True:
        try:
            client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
            client.loop_forever()
        except Exception as e:
            log.error("MQTT-Fehler: %s — reconnect in 10s", e)
            time.sleep(10)


if __name__ == "__main__":
    main()
