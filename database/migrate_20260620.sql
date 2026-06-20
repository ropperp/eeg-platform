-- Migration 2026-06-20: notifications + audit_log
-- Idempotent: kann mehrfach ausgeführt werden (IF NOT EXISTS + DO-Blöcke)
-- Ausführen: docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260620.sql

-- ─────────────────────────────────────────
-- POSTFACH: mandanten-isolierte Benachrichtigungen
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS notifications (
    id           UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id UUID        REFERENCES communities(id) ON DELETE CASCADE,
    audience     TEXT        NOT NULL CHECK (audience IN ('platform_admin','manager','member')),
    member_id    UUID        REFERENCES members(id) ON DELETE CASCADE,
    type         TEXT        NOT NULL,
    title        TEXT        NOT NULL,
    body         TEXT        NOT NULL DEFAULT '',
    payload      JSONB,
    is_read      BOOLEAN     NOT NULL DEFAULT false,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    read_at      TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS notifications_community_audience_idx ON notifications (community_id, audience, is_read, created_at DESC);
CREATE INDEX IF NOT EXISTS notifications_member_idx             ON notifications (member_id)    WHERE member_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS notifications_platform_idx           ON notifications (community_id) WHERE community_id IS NULL;

ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;

DO $$ BEGIN
  CREATE POLICY community_isolation ON notifications
      USING (community_id = current_setting('app.community_id', true)::uuid);
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

-- ─────────────────────────────────────────
-- AUDIT-LOG: append-only Ereignisprotokoll
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS audit_log (
    id           UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    community_id UUID,
    user_id      UUID        REFERENCES users(id) ON DELETE SET NULL,
    actor_label  TEXT,
    action       TEXT        NOT NULL,
    entity_type  TEXT        NOT NULL,
    entity_id    TEXT,
    details      JSONB,
    ip           TEXT
);

CREATE INDEX IF NOT EXISTS audit_log_community_idx ON audit_log (community_id, created_at DESC);
CREATE INDEX IF NOT EXISTS audit_log_action_idx    ON audit_log (action, created_at DESC);
CREATE INDEX IF NOT EXISTS audit_log_user_idx      ON audit_log (user_id)      WHERE user_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS audit_log_platform_idx  ON audit_log (community_id) WHERE community_id IS NULL;

ALTER TABLE audit_log ENABLE ROW LEVEL SECURITY;

DO $$ BEGIN
  CREATE POLICY community_isolation ON audit_log
      USING (community_id = current_setting('app.community_id', true)::uuid);
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

-- Append-only: App-Datenbankrolle darf Einträge nicht ändern oder löschen
REVOKE UPDATE, DELETE ON audit_log FROM eeg;
