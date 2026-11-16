"""
Tests für mqtt-subscriber/measurement_buffer.py gegen eine ECHTE PostgreSQL-Datenbank mit dem
vollständigen Schema (init.sql + alle Migrationen, inkl. der Trigger auf esp_measurements).

  DB_HOST=... DB_PORT=... DB_NAME=... DB_USER=... python3 tests/python/test_measurement_buffer.py

Ohne erreichbare Datenbank werden die Tests übersprungen. Räumt seine Testdaten wieder auf.
"""
import os
import sys
import unittest
import uuid
from datetime import datetime, timedelta, timezone

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "..", "mqtt-subscriber"))
import psycopg2  # noqa: E402
from psycopg2.pool import ThreadedConnectionPool  # noqa: E402

from measurement_buffer import MeasurementBuffer, rollup_esp_15min  # noqa: E402

DSN = f"host={os.environ.get('DB_HOST', 'localhost')} port={os.environ.get('DB_PORT', '5432')} " \
      f"dbname={os.environ.get('DB_NAME', 'eeg_platform')} user={os.environ.get('DB_USER', 'eeg')} " \
      f"password={os.environ.get('DB_PASSWORD', '')}"
try:
    POOL = ThreadedConnectionPool(1, 3, DSN)
except Exception as e:  # pragma: no cover
    POOL = None
    print("Keine Datenbank erreichbar -- übersprungen:", e)
    if os.environ.get("CI"):
        # In der CI wäre ein stilles Überspringen ein falsches Grün.
        sys.exit(1)


@unittest.skipIf(POOL is None, "keine Datenbank")
class BufferTest(unittest.TestCase):
    def setUp(self):
        self.conn = POOL.getconn()
        self.conn.autocommit = True
        with self.conn.cursor() as c:
            c.execute("DELETE FROM esp_rollup_state")      # Tests gehören der Datenbank, ein gemeinsamer Merkpunkt
        self.cid = str(uuid.uuid4())
        self.mps = [str(uuid.uuid4()) for _ in range(3)]
        with self.conn.cursor() as c:
            c.execute("INSERT INTO communities (id, name, slug) VALUES (%s, 'Testgemeinschaft', %s)", (self.cid, 'tg-' + self.cid[:8]))
            for i, mp in enumerate(self.mps):
                c.execute("INSERT INTO metering_points (id, community_id, zaehlpunkt_nr, meter_code, type, active, registered_at) "
                          "VALUES (%s, %s, %s, %s, 'consumer', true, CURRENT_DATE)", (mp, self.cid, f"AT{uuid.uuid4().hex}", f"M{i}"))
        self.buf = MeasurementBuffer(lambda: POOL, flush_interval=0.05)

    def tearDown(self):
        with self.conn.cursor() as c:
            c.execute("DELETE FROM esp_rollup_state")
            c.execute("DELETE FROM esp_15min WHERE community_id = %s", (self.cid,))
            c.execute("DELETE FROM esp_measurements WHERE community_id = %s", (self.cid,))
            c.execute("DELETE FROM metering_points WHERE community_id = %s", (self.cid,))
            c.execute("DELETE FROM communities WHERE id = %s", (self.cid,))
        POOL.putconn(self.conn)

    def q(self, sql, *args):
        with self.conn.cursor() as c:
            c.execute(sql, args)
            return c.fetchall()

    def test_flush_schreibt_alles_in_einer_transaktion_und_setzt_zuletzt_gesehen(self):
        t0 = datetime.now(timezone.utc)
        for i in range(30):
            self.buf.add(self.cid, self.mps[i % 3], 100 + i, 0, 1000 + i, 0, "Z1", ts=t0 + timedelta(seconds=i))
        self.assertEqual(self.buf.pending(), 30)
        self.assertEqual(self.buf.flush(), 30)
        self.assertEqual(self.buf.pending(), 0)
        self.assertEqual(self.q("SELECT count(*) FROM esp_measurements WHERE community_id = %s", self.cid)[0][0], 30)
        seen = self.q("SELECT count(*) FROM metering_points WHERE community_id = %s AND esp_online AND esp_last_seen_at IS NOT NULL AND meter_reachable", self.cid)
        self.assertEqual(seen[0][0], 3)
        self.assertEqual(self.buf.flush(), 0)   # nichts mehr zu tun

    def test_zeitstempel_ist_der_eingang_nicht_der_schreibzeitpunkt(self):
        ts = datetime(2026, 11, 1, 12, 0, 5, tzinfo=timezone.utc)
        self.buf.add(self.cid, self.mps[0], 1, 0, 2, 0, None, ts=ts)
        self.buf.flush()
        self.assertEqual(self.q("SELECT time FROM esp_measurements WHERE community_id = %s", self.cid)[0][0], ts)

    def test_fehler_beim_schreiben_behaelt_zeilen_in_reihenfolge_und_versucht_erneut(self):
        class KaputterPool:
            def getconn(self): raise psycopg2.OperationalError("DB weg")
            def putconn(self, c): pass
        b = MeasurementBuffer(lambda: KaputterPool())
        b.add(self.cid, self.mps[0], 1, 0, 1, 0, None, ts=datetime(2026, 11, 1, 10, 0, 1, tzinfo=timezone.utc))
        with self.assertRaises(psycopg2.OperationalError):
            b.flush()
        b.add(self.cid, self.mps[0], 2, 0, 2, 0, None, ts=datetime(2026, 11, 1, 10, 0, 2, tzinfo=timezone.utc))
        self.assertEqual(b.pending(), 2)
        b._get_pool = lambda: POOL                     # Datenbank wieder da
        self.assertEqual(b.flush(), 2)
        self.assertEqual([r[0] for r in self.q("SELECT power_bezug_w FROM esp_measurements WHERE community_id = %s ORDER BY time", self.cid)], [1, 2])

    def test_obergrenze_verwirft_aelteste_statt_unbegrenzt_zu_wachsen(self):
        b = MeasurementBuffer(lambda: POOL, max_rows=10)
        for i in range(25):
            b.add(self.cid, self.mps[0], i, 0, 0, 0, None)
        self.assertEqual(b.pending(), 10)
        b.flush()
        self.assertEqual(sorted(r[0] for r in self.q("SELECT power_bezug_w FROM esp_measurements WHERE community_id = %s", self.cid)), list(range(15, 25)))

    def test_ein_transaktionsvergleich_1000_nachrichten(self):
        before = self.q("SELECT xact_commit FROM pg_stat_database WHERE datname = current_database()")[0][0]
        t0 = datetime.now(timezone.utc)
        for i in range(1000):
            self.buf.add(self.cid, self.mps[i % 3], i, 0, i, 0, None, ts=t0 + timedelta(milliseconds=i))
        self.buf.flush()
        after = self.q("SELECT xact_commit FROM pg_stat_database WHERE datname = current_database()")[0][0]
        used = after - before
        print(f"\n   1000 Nachrichten -> ca. {used} Transaktionen insgesamt (vorher: 1000 Commits allein fürs Schreiben)")
        self.assertLess(used, 30)

    def _raw(self, mp, ts, pb, pe, eb, ee):
        with self.conn.cursor() as c:
            c.execute("INSERT INTO esp_measurements (time, community_id, metering_point_id, power_bezug_w, power_einspeisung_w, energy_bezug_wh, energy_einspeisung_wh) VALUES (%s,%s,%s,%s,%s,%s,%s)",
                      (ts, self.cid, mp, pb, pe, eb, ee))

    def test_zusammenfassung_15_minuten(self):
        base = datetime(2026, 11, 1, 10, 0, tzinfo=timezone.utc)
        for sec, (pb, pe, eb, ee) in {5: (100, 0, 5000, 0), 300: (300, 10, 5010, 1), 899: (200, 20, 5020, 2)}.items():
            self._raw(self.mps[0], base + timedelta(seconds=sec), pb, pe, eb, ee)      # Fenster 10:00
        self._raw(self.mps[0], base + timedelta(minutes=15, seconds=1), 50, 0, 5030, 2)  # Fenster 10:15
        now = base + timedelta(hours=2)
        n = rollup_esp_15min(self.conn_for_rollup(), now=now)
        self.assertEqual(n, 1)
        rows = self.q("SELECT bucket, samples, bezug_w_avg, einspeisung_w_avg, bezug_w_max, einspeisung_w_max, energy_bezug_wh_end, energy_einspeisung_wh_end FROM esp_15min WHERE community_id = %s ORDER BY bucket", self.cid)
        self.assertEqual(len(rows), 2)
        b, samples, bavg, eavg, bmax, emax, ebend, eeend = rows[0]
        self.assertEqual((b, samples, bavg, eavg, bmax, emax, ebend, eeend), (base, 3, 200, 10, 300, 20, 5020, 2))
        self.assertEqual(rows[1][0], base + timedelta(minutes=15))
        self.assertEqual(rows[1][6], 5030)

    def conn_for_rollup(self):
        c = psycopg2.connect(DSN)
        self.addCleanup(c.close)
        return c

    def test_zusammenfassung_nur_fertige_fenster_und_wiederholbar(self):
        base = datetime(2026, 11, 1, 10, 0, tzinfo=timezone.utc)
        self._raw(self.mps[1], base + timedelta(seconds=10), 10, 0, 100, 0)          # Fenster 10:00 (fertig)
        self._raw(self.mps[1], base + timedelta(minutes=30, seconds=10), 10, 0, 110, 0)  # Fenster 10:30 (läuft noch)
        c = self.conn_for_rollup()
        now = base + timedelta(minutes=34)       # 10:34 -> fertig bis 10:15 (5 Min Nachlauf: 10:29 -> 10:15)
        self.assertGreaterEqual(rollup_esp_15min(c, now=now), 1)
        self.assertEqual(self.q("SELECT count(*) FROM esp_15min WHERE community_id = %s", self.cid)[0][0], 1)
        self.assertEqual(rollup_esp_15min(c, now=now), 0)                            # nichts Neues
        later = base + timedelta(hours=1)
        rollup_esp_15min(c, now=later)
        self.assertEqual(self.q("SELECT count(*) FROM esp_15min WHERE community_id = %s", self.cid)[0][0], 2)

    def test_nachholen_tageweise(self):
        d0 = datetime(2026, 10, 1, 12, 0, tzinfo=timezone.utc)
        self._raw(self.mps[2], d0, 1, 0, 1, 0)
        self._raw(self.mps[2], d0 + timedelta(days=2), 2, 0, 2, 0)
        c = self.conn_for_rollup()
        now = d0 + timedelta(days=5)
        calls = 0
        while rollup_esp_15min(c, now=now, max_span=timedelta(days=1)) and calls < 10:
            calls += 1
        self.assertGreaterEqual(calls, 2)       # mehrere Schritte nötig (ein Tag je Aufruf)
        self.assertEqual(self.q("SELECT count(*) FROM esp_15min WHERE community_id = %s", self.cid)[0][0], 2)
        self.assertLess(calls, 10)              # endet: leere Tage zwischen den Werten schieben den Merkpunkt trotzdem weiter


if __name__ == "__main__":
    unittest.main(verbosity=2)
