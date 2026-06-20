"""
MQTT-Subscriber: ESP32 + Node-RED → TimescaleDB

Unterstützte Topic-Formate
──────────────────────────
1) eeg/{community_slug}/meter/{meter_code}/live          (ESP32, legacy)
   Payload: {"pp": W, "pm": W, "ep": Wh, "em": Wh, "znr": "..."}
   Community-Auflösung: LOWER(marktpartner_id) OR slug
   Metering-Point-Auflösung: metering_points.meter_code

2) eeg/{rc_nummer}/meter/{zaehler_nr}/power              (ESP32/Node-RED, neu)
   Payload: {"power_w": W, "meter_reading": Wh, "ts": "ISO8601"}
   Community-Auflösung: LOWER(marktpartner_id) = LOWER(rc_nummer)
   Metering-Point-Auflösung: metering_points.zaehler_nr  (13-stellige ESP-Zählernummer)
   NICHT zaehlpunkt_nr (AT...) — dieser bleibt dem EDA-Pfad vorbehalten.

Unbekannte Zählernummern im /power-Pfad erzeugen eine Meldung im Postfach
(manager + platform_admin) und einen audit_log-Eintrag. Dedupliziert pro
(community_id, zaehler_nr) innerhalb von 6 Stunden.
"""

import json
import logging
import os
import time

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
DB_HOST   = os.environ["DB_HOST"]
DB_PORT   = os.environ.get("DB_PORT", "5432")
DB_USER   = os.environ["DB_USER"]
DB_PASSWORD = os.environ["DB_PASSWORD"]
DB_NAME   = os.environ["DB_NAME"]

db_pool: ThreadedConnectionPool | None = None

# Caches – nur positive Treffer; None-Ergebnisse werden nicht gecacht,
# damit späte Zählpunkt-Zuordnung sofort wirkt.
community_cache: dict[str, str] = {}        # mqtt_id (lowercase) → community_id UUID
mp_by_code_cache: dict[str, str] = {}       # "community_id:meter_code" → mp UUID  (/live)
mp_by_zaehler_cache: dict[str, str] = {}    # "community_id:zaehler_nr"  → mp UUID  (/power)


# ──────────────────────────────────────────────────────────────────
# DB-Pool
# ──────────────────────────────────────────────────────────────────

def get_pool() -> ThreadedConnectionPool:
    global db_pool
    if db_pool is None:
        dsn = f"host={DB_HOST} port={DB_PORT} dbname={DB_NAME} user={DB_USER} password={DB_PASSWORD}"
        db_pool = ThreadedConnectionPool(minconn=1, maxconn=5, dsn=dsn)
    return db_pool


def get_conn():
    return get_pool().getconn()


def put_conn(conn):
    get_pool().putconn(conn)


# ──────────────────────────────────────────────────────────────────
# Lookup-Helfer
# ──────────────────────────────────────────────────────────────────

def get_community_id(mqtt_id: str) -> str | None:
    """Auflösung per LOWER(marktpartner_id) oder slug."""
    key = mqtt_id.lower()
    if key in community_cache:
        return community_cache[key]
    conn = get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id FROM communities WHERE (LOWER(marktpartner_id) = %s OR slug = %s) AND active = true",
                (key, key),
            )
            row = cur.fetchone()
            if row:
                community_cache[key] = str(row[0])
                return community_cache[key]
    finally:
        put_conn(conn)
    return None


def get_mp_by_meter_code(community_id: str, meter_code: str) -> str | None:
    """Metering-Point-UUID via meter_code (13-stellige Zählernummer, /live-Topic)."""
    cache_key = f"{community_id}:{meter_code}"
    if cache_key in mp_by_code_cache:
        return mp_by_code_cache[cache_key]
    conn = get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id FROM metering_points WHERE community_id = %s AND meter_code = %s AND active = true",
                (community_id, meter_code),
            )
            row = cur.fetchone()
            if row:
                mp_by_code_cache[cache_key] = str(row[0])
                return mp_by_code_cache[cache_key]
    finally:
        put_conn(conn)
    return None


def get_mp_by_zaehler_nr(community_id: str, zaehler_nr: str) -> str | None:
    """Metering-Point-UUID via zaehler_nr (13-stellige ESP-Zählernummer, /power-Topic)."""
    cache_key = f"{community_id}:{zaehler_nr}"
    if cache_key in mp_by_zaehler_cache:
        return mp_by_zaehler_cache[cache_key]
    conn = get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id FROM metering_points WHERE community_id = %s AND zaehler_nr = %s AND active = true",
                (community_id, zaehler_nr),
            )
            row = cur.fetchone()
            if row:
                mp_by_zaehler_cache[cache_key] = str(row[0])
                return mp_by_zaehler_cache[cache_key]
    finally:
        put_conn(conn)
    return None


# ──────────────────────────────────────────────────────────────────
# DB-Schreiboperationen
# ──────────────────────────────────────────────────────────────────

def insert_measurement(community_id: str, mp_id: str, bezug_w: int, einsp_w: int,
                       bezug_wh: int, einsp_wh: int, znr: str | None = None) -> None:
    conn = get_conn()
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
                (community_id, mp_id, bezug_w, einsp_w, bezug_wh, einsp_wh, znr),
            )
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        put_conn(conn)


def notify_exists_recent(community_id: str, type_: str, dedup_key: str, within_hours: int = 6) -> bool:
    """Deduplizierung: True wenn gleichartige Meldung im Zeitfenster existiert."""
    conn = get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT id FROM notifications
                WHERE type = %s
                  AND community_id = %s
                  AND payload->>'dedup_key' = %s
                  AND created_at >= now() - %s * INTERVAL '1 hour'
                LIMIT 1
                """,
                (type_, community_id, dedup_key, within_hours),
            )
            return cur.fetchone() is not None
    finally:
        put_conn(conn)


def notify_create(community_id: str | None, audience: str, type_: str,
                  title: str, body: str, payload: dict) -> None:
    conn = get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO notifications (community_id, audience, type, title, body, payload)
                VALUES (%s, %s, %s, %s, %s, %s)
                """,
                (community_id, audience, type_, title, body, json.dumps(payload, ensure_ascii=False)),
            )
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        put_conn(conn)


def audit_log_write(action: str, entity_type: str, entity_id: str | None = None,
                    details: dict | None = None, community_id: str | None = None,
                    actor_label: str = "system:mqtt-subscriber") -> None:
    conn = get_conn()
    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO audit_log (community_id, actor_label, action, entity_type, entity_id, details)
                VALUES (%s, %s, %s, %s, %s, %s)
                """,
                (
                    community_id,
                    actor_label,
                    action,
                    entity_type,
                    entity_id,
                    json.dumps(details, ensure_ascii=False) if details else None,
                ),
            )
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        put_conn(conn)


# ──────────────────────────────────────────────────────────────────
# Unbekannter Zählpunkt: Postfach + Audit
# ──────────────────────────────────────────────────────────────────

def handle_unassigned_meter(community_id: str, zaehler_nr: str, topic: str, payload: dict) -> None:
    """
    Sendet eine Postfach-Meldung an Manager und Platform-Admin und schreibt
    einen Audit-Eintrag. Dedupliziert pro (community_id, zaehler_nr) / 6 h.
    """
    type_  = "unassigned_meter"
    dedup  = zaehler_nr

    if notify_exists_recent(community_id, type_, dedup, within_hours=6):
        log.debug("Dedupe: Meldung für %s bereits vorhanden, überspringe", zaehler_nr)
        return

    title = "Unbekannte Zählernummer (ESP)"
    body  = (
        f"MQTT-Daten für Zählernummer {zaehler_nr} erhalten, "
        f"aber kein passender Metering-Point in dieser Community gefunden.\n"
        f"Bitte Zählpunkt anlegen und ESP-Zählernummer eintragen."
    )
    note_payload = {
        "zaehler_nr": zaehler_nr,
        "topic": topic,
        "power_w": payload.get("power_w"),
        "ts": payload.get("ts"),
        "dedup_key": dedup,
    }

    try:
        notify_create(community_id, "manager", type_, title, body, note_payload)
        notify_create(community_id, "platform_admin", type_, title, body, note_payload)
        audit_log_write(
            action="meter.unassigned",
            entity_type="metering_point",
            entity_id=zaehler_nr,
            details={"topic": topic, "power_w": payload.get("power_w"), "ts": payload.get("ts")},
            community_id=community_id,
        )
        log.warning("Unbekannte ESP-Zählernummer %s — Postfach + Audit geschrieben", zaehler_nr)
    except Exception as exc:
        log.error("Fehler beim Schreiben von Notify/Audit für %s: %s", zaehler_nr, exc)


# ──────────────────────────────────────────────────────────────────
# Message-Handler
# ──────────────────────────────────────────────────────────────────

def handle_live(parts: list[str], raw_payload: bytes) -> None:
    """
    Legacy-Format: eeg/{slug}/meter/{meter_code}/live
    Payload: {"pp": W, "pm": W, "ep": Wh, "em": Wh, "znr": "..."}
    """
    community_slug = parts[1]
    meter_code     = parts[3]
    topic          = "/".join(parts)

    try:
        payload = json.loads(raw_payload.decode())
    except json.JSONDecodeError:
        log.warning("Ungültiges JSON auf %s", topic)
        return

    if payload.get("pp", 0) > 100_000 or payload.get("pm", 0) > 100_000:
        log.warning("Unplausibler Wert auf %s: %s", topic, payload)
        return

    community_id = get_community_id(community_slug)
    if not community_id:
        log.debug("Unbekannte Community-Slug: %s", community_slug)
        return

    mp_id = get_mp_by_meter_code(community_id, meter_code)
    if not mp_id:
        log.warning("Unbekannter Meter-Code %s für Community %s — ignoriert", meter_code, community_slug)
        return

    try:
        insert_measurement(
            community_id, mp_id,
            bezug_w=int(payload.get("pp", 0)),
            einsp_w=int(payload.get("pm", 0)),
            bezug_wh=int(payload.get("ep", 0)),
            einsp_wh=int(payload.get("em", 0)),
            znr=payload.get("znr"),
        )
        log.debug("live gespeichert: %s → %s W", topic, payload.get("pp"))
    except Exception as exc:
        log.error("DB-Fehler bei %s: %s", topic, exc)


def handle_power(parts: list[str], raw_payload: bytes) -> None:
    """
    Neues Format: eeg/{rc_nummer}/meter/{zaehler_nr}/power
    Payload: {"power_w": W, "meter_reading": Wh, "ts": "ISO8601"}
    Positives power_w = Bezug, negatives power_w = Einspeisung.
    Auflösung per metering_points.zaehler_nr (13-stellige ESP-Zählernummer).
    """
    rc_nummer  = parts[1]
    zaehler_nr = parts[3]
    topic      = "/".join(parts)

    try:
        payload = json.loads(raw_payload.decode())
    except json.JSONDecodeError:
        log.warning("Ungültiges JSON auf %s", topic)
        return

    power_w       = int(payload.get("power_w", 0))
    meter_reading = int(payload.get("meter_reading", 0))

    if abs(power_w) > 100_000:
        log.warning("Unplausibler Wert auf %s: power_w=%s", topic, power_w)
        return

    community_id = get_community_id(rc_nummer)
    if not community_id:
        log.warning("Unbekannte RC-Nummer: %s — Topic ignoriert", rc_nummer)
        return

    mp_id = get_mp_by_zaehler_nr(community_id, zaehler_nr)
    if not mp_id:
        handle_unassigned_meter(community_id, zaehler_nr, topic, payload)
        return

    bezug_w = max(0, power_w)
    einsp_w = max(0, -power_w)

    try:
        insert_measurement(
            community_id, mp_id,
            bezug_w=bezug_w,
            einsp_w=einsp_w,
            bezug_wh=meter_reading,
            einsp_wh=0,
        )
        log.debug("power gespeichert: %s → %s W Bezug / %s W Einspeisung", topic, bezug_w, einsp_w)
    except Exception as exc:
        log.error("DB-Fehler bei %s: %s", topic, exc)


def on_message(client, userdata, msg: mqtt.MQTTMessage) -> None:
    parts = msg.topic.split("/")
    if len(parts) != 5 or parts[0] != "eeg" or parts[2] != "meter":
        log.warning("Unbekanntes Topic-Format: %s", msg.topic)
        return

    suffix = parts[4]
    if suffix == "live":
        handle_live(parts, msg.payload)
    elif suffix == "power":
        handle_power(parts, msg.payload)
    else:
        log.debug("Unbekanntes Topic-Suffix '%s' auf %s — ignoriert", suffix, msg.topic)


def on_connect(client, userdata, flags, rc, properties=None) -> None:
    if rc == 0:
        log.info("Verbunden mit MQTT-Broker %s:%s", MQTT_HOST, MQTT_PORT)
        client.subscribe("eeg/+/meter/+/live", qos=1)
        client.subscribe("eeg/+/meter/+/power", qos=1)
        log.info("Subscribed: eeg/+/meter/+/live  +  eeg/+/meter/+/power")
    else:
        log.error("MQTT-Verbindung fehlgeschlagen, rc=%s", rc)


def on_disconnect(client, userdata, disconnect_flags, rc, properties=None) -> None:
    if rc != 0:
        log.warning("MQTT-Verbindung unterbrochen (rc=%s), reconnect...", rc)


def main() -> None:
    for attempt in range(30):
        try:
            get_pool()
            log.info("DB-Verbindung OK")
            break
        except Exception as exc:
            log.warning("DB noch nicht bereit (%s), warte... (%d/30)", exc, attempt + 1)
            time.sleep(5)
    else:
        log.error("DB nicht erreichbar nach 30 Versuchen — Exit")
        raise SystemExit(1)

    client = mqtt.Client(
        mqtt.CallbackAPIVersion.VERSION2,
        client_id="eeg-mqtt-subscriber",
    )
    client.on_connect    = on_connect
    client.on_disconnect = on_disconnect
    client.on_message    = on_message
    client.reconnect_delay_set(min_delay=1, max_delay=30)

    while True:
        try:
            client.connect(MQTT_HOST, MQTT_PORT, keepalive=60)
            client.loop_forever()
        except Exception as exc:
            log.error("MQTT-Fehler: %s — reconnect in 10s", exc)
            time.sleep(10)


if __name__ == "__main__":
    main()
