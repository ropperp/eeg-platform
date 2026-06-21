-- Migration 2026-06-21: system_status für Backup-Monitoring
-- Idempotent: IF NOT EXISTS / kann mehrfach ausgeführt werden
-- Ausführen: docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260621.sql

CREATE TABLE IF NOT EXISTS system_status (
    key        TEXT PRIMARY KEY,
    value      TEXT,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

COMMENT ON TABLE system_status IS
    'Plattform-weite Key/Value-Statuswerte (z.B. Backup-Status). Kein RLS, kein Mandantenbezug.';
