"""
Tests für eda-parser/worker.py gegen eine ECHTE PostgreSQL-Datenbank (Schema + alle Migrationen),
als Dienstkonto eeg_worker wie in Produktion:

  DB_HOST=... DB_PORT=... DB_NAME=... DB_USER=eeg_worker DB_PASSWORD=... python3 tests/python/test_eda_worker.py

Ohne Datenbank übersprungen (in der CI ein Fehler, siehe CI-Variable). Räumt seine Testdaten auf.
"""
import json
import os
import sys
import threading
import unittest
import uuid

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "..", "eda-parser"))
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "fixtures"))
import psycopg2  # noqa: E402

import worker  # noqa: E402

DSN = worker.DB_DSN
try:
    psycopg2.connect(DSN).close()
    DB_OK = True
except Exception as e:  # pragma: no cover
    DB_OK = False
    print("Keine Datenbank erreichbar -- übersprungen:", e)
    if os.environ.get("CI"):
        sys.exit(1)


def fake_runner(stdout, stderr="INFO Lese XLSX", code=0):
    calls = []

    def run(job, slug):
        calls.append((job["kind"], slug, job["file_path"]))
        return stdout, stderr, code
    run.calls = calls
    return run


@unittest.skipUnless(DB_OK, "keine Datenbank")
class EdaWorkerTest(unittest.TestCase):
    def setUp(self):
        self.conn = psycopg2.connect(DSN)
        self.cid = str(uuid.uuid4())
        self.slug = "worker-test-" + self.cid[:8]
        with self.conn.cursor() as cur:
            cur.execute("INSERT INTO communities (id, name, slug) VALUES (%s, 'Worker-Test', %s)", (self.cid, self.slug))
        self.conn.commit()

    def tearDown(self):
        with self.conn.cursor() as cur:
            for table in ("eda_measurements", "eda_imports", "metering_points"):
                cur.execute(f"DELETE FROM {table} WHERE community_id = %s", (self.cid,))
            cur.execute("DELETE FROM audit_log WHERE community_id = %s", (self.cid,))
            cur.execute("DELETE FROM eda_import_jobs WHERE community_id = %s", (self.cid,))
            cur.execute("DELETE FROM communities WHERE id = %s", (self.cid,))
        self.conn.commit()
        self.conn.close()

    def job(self, kind="monthly", source="upload", status="queued", path="/tmp/x.xlsx", extra_sql="", sha=None):
        with self.conn.cursor() as cur:
            cur.execute(
                "INSERT INTO eda_import_jobs (community_id, kind, source, file_path, original_name, file_sha256, status) "
                "VALUES (%s, %s, %s, %s, 'x.xlsx', %s, %s) RETURNING id",
                (self.cid, kind, source, path, sha or uuid.uuid4().hex, status))
            jid = cur.fetchone()[0]
            if extra_sql:
                cur.execute(extra_sql, (jid,))
        self.conn.commit()
        return jid

    def row(self, jid):
        with self.conn.cursor() as cur:
            cur.execute("SELECT status, attempts, stdout, stderr, exit_code, result, finished_at IS NOT NULL "
                        "FROM eda_import_jobs WHERE id = %s", (jid,))
            return cur.fetchone()

    def audits(self):
        with self.conn.cursor() as cur:
            # audit_log.id ist eine UUID -> nach Aktion/Text sortieren, nicht nach id
            cur.execute("SELECT aktion, beschreibung, ist_fehler, entity_typ FROM audit_log WHERE community_id = %s "
                        "ORDER BY aktion, beschreibung", (self.cid,))
            return cur.fetchall()

    def test_erfolg_speichert_ergebnis_und_audit(self):
        jid = self.job()
        out = json.dumps({"records": 12, "warnings": ["w"], "period_from": "2026-10-01", "period_to": "2026-10-31",
                          "neu_angelegt": [{"zaehlpunkt_nr": "AT001", "type_guess": "consumer", "metering_point_id": None}]})
        run = fake_runner(out)
        self.assertEqual(worker.process_one(self.conn, run), "done")
        self.assertEqual(run.calls, [("monthly", self.slug, "/tmp/x.xlsx")])
        status, attempts, stdout, stderr, code, result, finished = self.row(jid)
        self.assertEqual((status, attempts, code, finished), ("done", 1, 0, True))
        self.assertEqual(result["records"], 12)
        self.assertEqual(stderr, "INFO Lese XLSX")
        a = self.audits()
        self.assertEqual(a[0][:3], ("eda.import", "EDA-Import: 12 Datensätze importiert, 1 Warnung(en)", False))
        self.assertEqual(a[1][0], "eda.metering_point.autocreate")
        self.assertIn("Zählpunkt AT001 automatisch aus EDA-Import angelegt (Typ-Vermutung: consumer)", a[1][1])

    def test_fehler_ohne_json_wird_failed_mit_diagnose(self):
        jid = self.job(kind="interval")
        self.assertEqual(worker.process_one(self.conn, fake_runner("", "Traceback: kaputt", 1)), "failed")
        self.assertEqual(self.row(jid)[0], "failed")
        a = self.audits()
        self.assertEqual(len(a), 1)
        self.assertEqual(a[0][0], "eda.interval_import")
        self.assertTrue(a[0][2])
        self.assertTrue(a[0][1].startswith("Viertelstundenwerte-Import fehlgeschlagen: Traceback: kaputt"))

    def test_postfach_job_schreibt_kein_audit(self):
        self.job(source="mailbox")
        self.assertEqual(worker.process_one(self.conn, fake_runner('{"records": 1}')), "done")
        self.assertEqual(self.audits(), [])

    def test_leere_warteschlange(self):
        self.assertIsNone(worker.process_one(self.conn, fake_runner("{}")))

    def test_haengende_jobs_werden_neu_eingereiht_oder_aufgegeben(self):
        alt = "UPDATE eda_import_jobs SET started_at = now() - interval '2 hours', attempts = %s WHERE id = %%s"
        retry = self.job(status="running", extra_sql=alt % 1)
        aufgeben = self.job(status="running", extra_sql=alt % worker.MAX_ATTEMPTS)
        frisch = self.job(status="running", extra_sql="UPDATE eda_import_jobs SET started_at = now() WHERE id = %s")
        self.assertGreaterEqual(worker.requeue_stale(self.conn), 2)
        self.assertEqual(self.row(retry)[0], "queued")
        self.assertEqual(self.row(aufgeben)[0], "failed")
        self.assertEqual(self.row(frisch)[0], "running")

    def test_zwei_worker_uebernehmen_nie_denselben_job(self):
        ids = {self.job() for _ in range(6)}
        taken, lock = [], threading.Lock()

        def work():
            c = psycopg2.connect(DSN)
            while True:
                j = worker.claim_job(c)
                if j is None or str(j["community_id"]) != self.cid:
                    break
                with lock:
                    taken.append(j["id"])
            c.close()
        ts = [threading.Thread(target=work) for _ in range(3)]
        [t.start() for t in ts]
        [t.join() for t in ts]
        self.assertEqual(sorted(map(str, taken)), sorted(map(str, ids)))

    def test_echter_parser_mit_gueltiger_monatsdatei(self):
        try:
            import make_eda_monthly
        except ImportError:  # pragma: no cover -- openpyxl fehlt (nur im Worker-Image vorhanden)
            self.skipTest("openpyxl nicht installiert")
        path = "/tmp/eda-worker-test-gut.xlsx"
        make_eda_monthly.build(path, 2026, 2)
        jid = self.job(path=path)
        self.assertEqual(worker.process_one(self.conn), "done")
        status, _, _, _, code, result, _ = self.row(jid)
        self.assertEqual((code, result["records"], len(result["neu_angelegt"])), (0, 2, 2))
        with self.conn.cursor() as cur:
            cur.execute("SELECT count(*) FROM eda_measurements WHERE community_id = %s", (self.cid,))
            self.assertEqual(cur.fetchone()[0], 2)
        self.conn.commit()
        aktionen = [a[0] for a in self.audits()]
        self.assertEqual(sorted(aktionen), ["eda.import", "eda.metering_point.autocreate", "eda.metering_point.autocreate"])
        os.remove(path)

    def test_echter_parser_mit_kaputter_datei(self):
        path = "/tmp/eda-worker-test-kaputt.xlsx"
        with open(path, "w") as f:
            f.write("keine Excel-Datei")
        jid = self.job(path=path)
        self.assertEqual(worker.process_one(self.conn), "failed")
        status, _, stdout, stderr, code, result, _ = self.row(jid)
        self.assertNotEqual(code, 0)
        self.assertIsNone(result)
        self.assertTrue(stderr.strip())
        os.remove(path)


class AuditTextTest(unittest.TestCase):
    """Ohne Datenbank: Audit-Texte entsprechen den früheren PHP-Texten."""

    def test_interval_erfolg(self):
        job = {"source": "upload", "kind": "interval", "community_id": "c", "requested_by": "u"}
        rows = worker.audit_rows(job, {"records": 5, "neu_angelegt": [
            {"zaehlpunkt_nr": "Z", "type_guess": "producer", "metering_point_id": "m"}]}, "", "")
        self.assertEqual(rows[0]["beschreibung"], "Viertelstundenwerte-Import: 5 Datensätze importiert")
        self.assertEqual(rows[0]["user_id"], "u")
        self.assertEqual(rows[1]["entity_id"], "m")
        self.assertIn("aus Viertelstundenwerte-Import angelegt (Typ-Vermutung: producer)", rows[1]["beschreibung"])

    def test_diagnose_wie_php(self):
        self.assertEqual(worker.diagnostics("", ""), "Keine Ausgabe")
        self.assertEqual(worker.diagnostics("out", "err"), "err\nout")
        self.assertIsNone(worker.parse_result("[1]"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
