-- EDA-Import als Job für den eigenen Container eda-worker (docs/adr/0005-eda-import-als-worker.md).
-- Die Webapp legt einen Job an, der Worker holt ihn (FOR UPDATE SKIP LOCKED), ruft den bestehenden
-- Parser auf und schreibt stdout/stderr/Exit-Code zurück -- dieselben Daten, die die Webapp früher
-- direkt vom Unterprozess bekam. Idempotent (mehrfach ausführbar).

CREATE TABLE IF NOT EXISTS eda_import_jobs (
    id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id   UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    kind           TEXT NOT NULL CHECK (kind IN ('monthly', 'interval')),   -- parser.py / parser_interval.py
    source         TEXT NOT NULL CHECK (source IN ('upload', 'mailbox')),   -- Portal-Upload / Postfach-Abruf
    file_path      TEXT NOT NULL,                                          -- unter /var/www/html/storage/uploads
    original_name  TEXT NOT NULL,
    file_sha256    TEXT NOT NULL,
    requested_by   UUID REFERENCES users(id) ON DELETE SET NULL,
    status         TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    attempts       INT  NOT NULL DEFAULT 0,
    stdout         TEXT,
    stderr         TEXT,
    exit_code      INT,
    result         JSONB,                                                  -- geparstes stdout bei Erfolg
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    started_at     TIMESTAMPTZ,
    finished_at    TIMESTAMPTZ
);

-- Warteschlange: der Worker sucht nur offene Jobs, ältester zuerst.
CREATE INDEX IF NOT EXISTS eda_import_jobs_queue_idx ON eda_import_jobs (created_at) WHERE status = 'queued';
CREATE INDEX IF NOT EXISTS eda_import_jobs_community_idx ON eda_import_jobs (community_id, created_at DESC);
-- Dieselbe Datei kann nicht zweimal gleichzeitig in Arbeit sein (Doppelklick, Upload + Postfach).
CREATE UNIQUE INDEX IF NOT EXISTS eda_import_jobs_active_file_uidx
    ON eda_import_jobs (community_id, kind, file_sha256) WHERE status IN ('queued', 'running');

-- Mandantentrennung wie bei den übrigen Tabellen. Der Worker (eeg_worker) hat BYPASSRLS.
ALTER TABLE eda_import_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE eda_import_jobs FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS community_isolation ON eda_import_jobs;
CREATE POLICY community_isolation ON eda_import_jobs
    USING (community_id = current_setting('app.community_id', true)::uuid);

-- Rechte für die Laufzeit-Rollen, falls es sie schon gibt (sonst setzt sie
-- scripts/db_runtime_role_setup.sh bzw. ALTER DEFAULT PRIVILEGES).
DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'eeg_app') THEN
        GRANT SELECT, INSERT, UPDATE ON eda_import_jobs TO eeg_app;
    END IF;
    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'eeg_worker') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON eda_import_jobs TO eeg_worker;
    END IF;
END $$;
