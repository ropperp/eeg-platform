"""
Sammel-Schreiben der ESP-Livewerte und Zusammenfassung in 15-Minuten-Fenster (16.11.2026).

Vorher schrieb der mqtt-subscriber JEDE eingehende Nachricht einzeln: eine Verbindung aus dem Pool
holen, INSERT, UPDATE der "zuletzt gesehen"-Zeit, COMMIT -- bei 20+ Zählern im 5-Sekunden-Takt über
ein Dutzend Transaktionen pro Sekunde (jede mit Schreib-Sync auf der SD-Karte/SSD des Raspberry Pi).
Jetzt werden die Zeilen im Speicher gesammelt und höchstens einmal pro Sekunde in EINER Transaktion
geschrieben (ein mehrzeiliges INSERT + ein UPDATE für alle beteiligten Zählpunkte). Die Livewerte sind
reine Visualisierung (keine Abrechnungsgrundlage), eine Sekunde Verzögerung ist unkritisch; geht der
Dienst unerwartet zu Ende, gehen höchstens die Werte der letzten Sekunde verloren.

Der Zeitstempel der Zeile ist der Eingang der Nachricht (nicht der Zeitpunkt des Schreibens).
"""

import logging
import threading
import time
from datetime import datetime, timedelta, timezone

import psycopg2.extras

log = logging.getLogger("measurement_buffer")


class MeasurementBuffer:
    def __init__(self, get_pool, flush_interval: float = 1.0, max_rows: int = 20000):
        self._get_pool = get_pool
        self._interval = flush_interval
        self._max_rows = max_rows            # Obergrenze, falls die Datenbank länger nicht erreichbar ist
        self._rows: list[tuple] = []
        self._lock = threading.Lock()
        self._stop = threading.Event()
        self._flush_lock = threading.Lock()  # nie zwei Schreibvorgänge gleichzeitig

    def add(self, community_id: str, metering_point_id: str, pp: int, pm: int, ep: int, em: int,
            znr: str | None, ts: datetime | None = None) -> None:
        row = (ts or datetime.now(timezone.utc), community_id, metering_point_id, pp, pm, ep, em, znr)
        with self._lock:
            self._rows.append(row)
            if len(self._rows) > self._max_rows:
                dropped = len(self._rows) - self._max_rows
                del self._rows[:dropped]
                log.error("Puffer voll -- %d älteste Messwerte verworfen (Datenbank nicht erreichbar?)", dropped)

    def pending(self) -> int:
        with self._lock:
            return len(self._rows)

    def flush(self) -> int:
        """Schreibt alle gesammelten Zeilen in einer Transaktion. Bei einem Fehler bleiben sie im
        Puffer und werden beim nächsten Durchlauf erneut versucht. Gibt die Anzahl geschriebener Zeilen zurück."""
        with self._flush_lock:
            with self._lock:
                rows, self._rows = self._rows, []
            if not rows:
                return 0
            pool = None
            conn = None
            try:
                pool = self._get_pool()
                conn = pool.getconn()
                with conn.cursor() as cur:
                    psycopg2.extras.execute_values(
                        cur,
                        """
                        INSERT INTO esp_measurements
                            (time, community_id, metering_point_id,
                             power_bezug_w, power_einspeisung_w,
                             energy_bezug_wh, energy_einspeisung_wh, znr)
                        VALUES %s
                        """,
                        rows,
                        page_size=1000,
                    )
                    mp_ids = sorted({r[2] for r in rows})
                    cur.execute(
                        """
                        UPDATE metering_points
                        SET esp_online = true, esp_last_seen_at = now(),
                            meter_reachable = true, meter_last_seen_at = now()
                        WHERE id = ANY(%s::uuid[])
                        """,
                        (mp_ids,),
                    )
                conn.commit()
                return len(rows)
            except Exception:
                # Auch wenn schon das Holen der Verbindung scheitert: Zeilen NICHT verlieren
                if conn is not None:
                    try:
                        conn.rollback()
                    except Exception:
                        pass
                with self._lock:
                    self._rows[:0] = rows          # vorne wieder einreihen, Reihenfolge bleibt
                    if len(self._rows) > self._max_rows:
                        del self._rows[: len(self._rows) - self._max_rows]
                raise
            finally:
                if conn is not None and pool is not None:
                    pool.putconn(conn)

    def run(self) -> None:
        """Schreibschleife (als Daemon-Thread)."""
        while not self._stop.is_set():
            self._stop.wait(self._interval)
            try:
                self.flush()
            except Exception as e:
                log.error("Sammel-Schreiben fehlgeschlagen (%s) -- neuer Versuch beim nächsten Durchlauf", e)

    def stop(self) -> None:
        """Beenden: Schleife stoppen und den Rest noch schreiben."""
        self._stop.set()
        try:
            self.flush()
        except Exception as e:
            log.error("Letztes Schreiben beim Beenden fehlgeschlagen: %s", e)


ROLLUP_SQL = """
INSERT INTO esp_15min
    (bucket, community_id, metering_point_id, samples,
     bezug_w_avg, einspeisung_w_avg, bezug_w_max, einspeisung_w_max,
     energy_bezug_wh_end, energy_einspeisung_wh_end)
SELECT date_bin('15 minutes', time, TIMESTAMPTZ '2000-01-01 00:00:00+00') AS bucket,
       community_id, metering_point_id, COUNT(*),
       ROUND(AVG(power_bezug_w))::int, ROUND(AVG(power_einspeisung_w))::int,
       MAX(power_bezug_w), MAX(power_einspeisung_w),
       (ARRAY_AGG(energy_bezug_wh ORDER BY time DESC))[1],
       (ARRAY_AGG(energy_einspeisung_wh ORDER BY time DESC))[1]
FROM esp_measurements
WHERE time >= %(von)s AND time < %(bis)s
GROUP BY 1, community_id, metering_point_id
ON CONFLICT (metering_point_id, bucket) DO UPDATE SET
    samples = EXCLUDED.samples,
    bezug_w_avg = EXCLUDED.bezug_w_avg, einspeisung_w_avg = EXCLUDED.einspeisung_w_avg,
    bezug_w_max = EXCLUDED.bezug_w_max, einspeisung_w_max = EXCLUDED.einspeisung_w_max,
    energy_bezug_wh_end = EXCLUDED.energy_bezug_wh_end,
    energy_einspeisung_wh_end = EXCLUDED.energy_einspeisung_wh_end
"""

_BUCKET = timedelta(minutes=15)


def _floor15(ts: datetime) -> datetime:
    ts = ts.astimezone(timezone.utc)
    return ts.replace(minute=ts.minute - ts.minute % 15, second=0, microsecond=0)


def rollup_esp_15min(conn, now: datetime | None = None, max_span: timedelta = timedelta(days=1)) -> int:
    """Fasst fertige 15-Minuten-Fenster der Rohdaten in esp_15min zusammen. Macht dort weiter, wo der
    letzte Durchlauf aufgehört hat (Merkpunkt esp_rollup_state.done_until; bei der ersten Ausführung
    ab dem ältesten Rohwert), höchstens `max_span` pro Aufruf, damit ein Nachholen über viele Monate
    den Dienst nicht blockiert. Nur FERTIGE Fenster (mit 5 Minuten Nachlauf für verspätet
    geschriebene Werte). Gibt zurück: 1, wenn ein Zeitraum bearbeitet wurde (der Aufrufer macht dann
    gleich weiter), 0, wenn nichts zu tun ist."""
    now = now or datetime.now(timezone.utc)
    done_until = _floor15(now - timedelta(minutes=5))
    with conn.cursor() as cur:
        cur.execute("SELECT done_until FROM esp_rollup_state WHERE id = 1")
        row = cur.fetchone()
        if row is not None:
            start = row[0]
        else:
            cur.execute("SELECT MIN(time) FROM esp_measurements")
            first = cur.fetchone()[0]
            if first is None:
                conn.rollback()
                return 0
            start = _floor15(first)
        if start >= done_until:
            conn.rollback()
            return 0
        end = min(start + max_span, done_until)
        cur.execute(ROLLUP_SQL, {"von": start, "bis": end})
        cur.execute(
            "INSERT INTO esp_rollup_state (id, done_until) VALUES (1, %s) "
            "ON CONFLICT (id) DO UPDATE SET done_until = EXCLUDED.done_until",
            (end,),
        )
    conn.commit()
    return 1
