-- Migration 2026-09-10: audit_log -- NOT-NULL-Zwang auf zwei Alt-Spalten entfernen
--
-- Patrick, nach migrate_20260909.sql: logAudit() scheiterte weiterhin, diesmal mit
-- "null value in column \"action\" of relation \"audit_log\" violates not-null constraint".
-- Ursache: die auf diesem Server vorgefundene, ältere/andere audit_log-Struktur (siehe
-- migrate_20260909.sql) hat zwei Spalten aus einem offenbar verworfenen Schema-Entwurf --
-- "action" und "entity_type" -- die NOT NULL sind, aber KEINEN Default haben. Der aktuelle
-- Code (logAudit()/logAuditDiff() in webapp/public/index.php) kennt nur "aktion"/"entity_typ"
-- und befüllt "action"/"entity_type" nie -- jede INSERT schlägt deshalb weiterhin fehl, auch
-- nachdem die eigentlich benötigten Spalten schon vollständig vorhanden waren.
--
-- Fix: NOT NULL auf beiden Alt-Spalten entfernen (Spalten UND eventuell vorhandene historische
-- Werte bleiben unangetastet, nur der Zwang fällt weg). Idempotent -- auf einem Server, wo die
-- Spalten gar nicht existieren oder bereits nullable sind, ein reines No-Op.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'audit_log' AND column_name = 'action' AND is_nullable = 'NO'
    ) THEN
        ALTER TABLE audit_log ALTER COLUMN action DROP NOT NULL;
    END IF;
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'audit_log' AND column_name = 'entity_type' AND is_nullable = 'NO'
    ) THEN
        ALTER TABLE audit_log ALTER COLUMN entity_type DROP NOT NULL;
    END IF;
END $$;
