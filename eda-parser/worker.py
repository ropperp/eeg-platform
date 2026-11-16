"""
EDA-Worker: verarbeitet EDA-Importjobs aus der Tabelle eda_import_jobs (migrate_20261118.sql) in
einem eigenen Container, statt den Parser im Webapp-Prozess laufen zu lassen
(docs/adr/0005-eda-import-als-worker.md).

Ablauf je Job:
  1. Job atomar übernehmen (UPDATE ... WHERE id = (SELECT ... FOR UPDATE SKIP LOCKED)) -- mehrere
     Worker oder Neustarts übernehmen nie denselben Job doppelt.
  2. Den bestehenden Parser (parser.py bzw. parser_interval.py) unverändert als Unterprozess
     starten, genau wie früher webapp/src/EdaParserRunner.php.
  3. stdout/stderr/Exit-Code und (bei gültigem JSON) das Ergebnis speichern, Status done/failed.
  4. Bei Uploads aus dem Portal die Audit-Einträge schreiben (gleiche Texte wie früher in PHP).
     Postfach-Jobs protokolliert weiterhin EdaAutoImporter.php selbst, das auf das Ergebnis wartet.

Ein Job, der länger als STALE_MINUTES auf "running" steht (Worker abgestürzt), wird erneut
eingereiht, höchstens MAX_ATTEMPTS Versuche. Parser-Fehler selbst werden NICHT wiederholt (eine
kaputte Datei bleibt kaputt).

Gesundheit: Datei /tmp/eda_worker_healthy wird bei jedem Durchlauf aktualisiert (Healthcheck in
docker-compose.yml).
"""
import json
import logging
import os
import subprocess
import sys
import threading
import time
from typing import Callable, Optional

import psycopg2
import psycopg2.extras

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
log = logging.getLogger("eda-worker")

PARSER_DIR = os.path.dirname(os.path.abspath(__file__))
PARSERS = {"monthly": "parser.py", "interval": "parser_interval.py"}
POLL_SECONDS = float(os.environ.get("EDA_WORKER_POLL_SECONDS", "3"))
PARSER_TIMEOUT = int(os.environ.get("EDA_WORKER_TIMEOUT_SECONDS", "1800"))
STALE_MINUTES = int(os.environ.get("EDA_WORKER_STALE_MINUTES", "40"))
MAX_ATTEMPTS = 3
HEARTBEAT_FILE = "/tmp/eda_worker_healthy"

DB_DSN = (
    f"host={os.environ.get('DB_HOST', 'localhost')} "
    f"port={os.environ.get('DB_PORT', '5432')} "
    f"dbname={os.environ.get('DB_NAME', 'eeg_platform')} "
    f"user={os.environ.get('DB_USER', 'eeg')} "
    f"password={os.environ.get('DB_PASSWORD', '')}"
)

# (stdout, stderr, exit_code)
RunResult = tuple[str, str, int]
Runner = Callable[[dict, str], RunResult]


def run_parser(job: dict, community_slug: str) -> RunResult:
    """Startet den passenden Parser wie früher EdaParserRunner.php (stdout = JSON, stderr = Log)."""
    cmd = [sys.executable, os.path.join(PARSER_DIR, PARSERS[job["kind"]]),
           "--file", job["file_path"], "--community", community_slug]
    if job.get("requested_by"):
        cmd += ["--user-id", str(job["requested_by"])]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=PARSER_TIMEOUT, env=os.environ.copy())
        return p.stdout, p.stderr, p.returncode
    except subprocess.TimeoutExpired as e:
        return (e.stdout or "") if isinstance(e.stdout, str) else "", f"Zeitüberschreitung nach {PARSER_TIMEOUT} s", -1


def parse_result(stdout: str) -> Optional[dict]:
    """JSON-Ergebnis des Parsers oder None (dann gilt der Lauf als fehlgeschlagen, wie in PHP)."""
    try:
        data = json.loads(stdout)
    except (ValueError, TypeError):
        return None
    return data if isinstance(data, dict) else None


def diagnostics(stdout: str, stderr: str) -> str:
    """Wie EdaParserRunner::diagnostics(): stderr vor stdout."""
    combined = (stderr + "\n" + stdout).strip()
    return combined if combined else "Keine Ausgabe"


def audit_rows(job: dict, result: Optional[dict], stdout: str, stderr: str) -> list[dict]:
    """Audit-Einträge für einen Portal-Upload -- Wortlaut wie früher in routes/portal.php."""
    if job["source"] != "upload":
        return []
    interval = job["kind"] == "interval"
    aktion = "eda.interval_import" if interval else "eda.import"
    label = "Viertelstundenwerte-Import" if interval else "EDA-Import"
    base = {"community_id": job["community_id"], "user_id": job.get("requested_by"),
            "entity_typ": None, "entity_id": None, "ist_fehler": False}
    if result is None:
        return [{**base, "aktion": aktion, "ist_fehler": True,
                 "beschreibung": f"{label} fehlgeschlagen: " + diagnostics(stdout, stderr)[:4000]}]
    text = f"{label}: {result.get('records', '?')} Datensätze importiert"
    if result.get("warnings"):
        text += f", {len(result['warnings'])} Warnung(en)"
    rows = [{**base, "aktion": aktion, "beschreibung": text}]
    quelle = "Viertelstundenwerte-Import" if interval else "EDA-Import"
    for neu in result.get("neu_angelegt") or []:
        rows.append({**base, "aktion": "eda.metering_point.autocreate", "entity_typ": "metering_point",
                     "entity_id": neu.get("metering_point_id"),
                     "beschreibung": f"Zählpunkt {neu.get('zaehlpunkt_nr')} automatisch aus {quelle} angelegt "
                                     f"(Typ-Vermutung: {neu.get('type_guess')}), noch keinem Mitglied zugeordnet."})
    return rows


def requeue_stale(conn) -> int:
    """Jobs eines abgestürzten Workers: erneut einreihen oder nach MAX_ATTEMPTS aufgeben."""
    with conn.cursor() as cur:
        cur.execute(
            """UPDATE eda_import_jobs
               SET status = CASE WHEN attempts >= %s THEN 'failed' ELSE 'queued' END,
                   stderr = CASE WHEN attempts >= %s THEN 'Abgebrochen: Worker wurde mehrfach während des Imports beendet.'
                                 ELSE stderr END,
                   finished_at = CASE WHEN attempts >= %s THEN now() ELSE NULL END
               WHERE status = 'running' AND started_at < now() - (%s || ' minutes')::interval""",
            (MAX_ATTEMPTS, MAX_ATTEMPTS, MAX_ATTEMPTS, str(STALE_MINUTES)),
        )
        n = cur.rowcount
    conn.commit()
    return n


def claim_job(conn) -> Optional[dict]:
    """Nimmt den ältesten wartenden Job und setzt ihn auf running (oder None)."""
    with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
        cur.execute(
            """UPDATE eda_import_jobs SET status = 'running', started_at = now(), attempts = attempts + 1
               WHERE id = (SELECT id FROM eda_import_jobs WHERE status = 'queued'
                           ORDER BY created_at FOR UPDATE SKIP LOCKED LIMIT 1)
               RETURNING id, community_id, kind, source, file_path, original_name, requested_by, attempts"""
        )
        row = cur.fetchone()
    conn.commit()
    return dict(row) if row else None


def finish_job(conn, job: dict, stdout: str, stderr: str, exit_code: int) -> str:
    """Speichert das Ergebnis und schreibt Audit-Einträge in EINER Transaktion. Liefert den Status."""
    result = parse_result(stdout)
    status = "done" if result is not None else "failed"
    with conn.cursor() as cur:
        cur.execute(
            """UPDATE eda_import_jobs SET status = %s, stdout = %s, stderr = %s, exit_code = %s,
                      result = %s, finished_at = now()
               WHERE id = %s""",
            (status, stdout, stderr, exit_code, json.dumps(result) if result is not None else None, job["id"]),
        )
        for a in audit_rows(job, result, stdout, stderr):
            cur.execute(
                """INSERT INTO audit_log (community_id, user_id, aktion, entity_typ, entity_id, beschreibung, ist_fehler)
                   VALUES (%s, %s, %s, %s, %s, %s, %s)""",
                (a["community_id"], a["user_id"], a["aktion"], a["entity_typ"], a["entity_id"],
                 a["beschreibung"], a["ist_fehler"]),
            )
    conn.commit()
    return status


def process_one(conn, runner: Runner = run_parser) -> Optional[str]:
    """Verarbeitet höchstens einen Job. Liefert dessen Status oder None, wenn nichts zu tun war."""
    job = claim_job(conn)
    if job is None:
        return None
    with conn.cursor() as cur:
        cur.execute("SELECT slug FROM communities WHERE id = %s", (job["community_id"],))
        row = cur.fetchone()
    conn.commit()
    if row is None:
        return finish_job(conn, job, "", "Community nicht gefunden", -1)
    log.info("Job %s: %s (%s, %s, Versuch %s)", job["id"], job["original_name"], job["kind"], job["source"], job["attempts"])
    stdout, stderr, code = runner(job, row[0])
    status = finish_job(conn, job, stdout, stderr, code)
    log.info("Job %s: %s", job["id"], status)
    return status


_last_progress = time.time()


def heartbeat_loop() -> None:
    """Eigener Thread: ein langer Import (Minuten) darf den Healthcheck nicht auslösen. Gesund ist der
    Worker, solange die Hauptschleife innerhalb der Parser-Zeitgrenze (+5 min) vorankommt."""
    while True:
        if time.time() - _last_progress < PARSER_TIMEOUT + 300:
            try:
                with open(HEARTBEAT_FILE, "w") as f:
                    f.write(str(time.time()))
            except OSError as e:
                log.warning("Heartbeat nicht schreibbar: %s", e)
        time.sleep(20)


def main() -> None:
    global _last_progress
    log.info("EDA-Worker gestartet (Abfrage alle %s s)", POLL_SECONDS)
    threading.Thread(target=heartbeat_loop, daemon=True).start()
    conn = None
    last_stale_check = 0.0
    while True:
        try:
            if conn is None or conn.closed:
                conn = psycopg2.connect(DB_DSN)
            if time.time() - last_stale_check > 60:
                n = requeue_stale(conn)
                if n:
                    log.warning("%s hängende(n) Job(s) neu eingereiht bzw. aufgegeben", n)
                last_stale_check = time.time()
            _last_progress = time.time()
            if process_one(conn) is None:
                time.sleep(POLL_SECONDS)
        except psycopg2.Error as e:
            log.error("Datenbankfehler: %s -- neuer Versuch in 10 s", e)
            try:
                if conn is not None:
                    conn.close()
            except psycopg2.Error:
                pass
            conn = None
            time.sleep(10)


if __name__ == "__main__":
    main()
