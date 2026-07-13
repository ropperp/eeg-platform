-- Migration 2026-07-16: Reparatur notifications, SEPA-Zweitunterschrift, Admin-Log

-- ─────────────────────────────────────────
-- Reparatur: notifications fehlten auf dem Produktivsystem mehrere Spalten
-- (INSERT/UPDATE schlugen mit "column typ/referenz_typ does not exist" fehl).
-- CREATE TABLE IF NOT EXISTS greift nicht mehr, sobald die Tabelle (in welcher
-- Form auch immer) schon existiert — daher hier defensiv jede Spalte einzeln
-- ergänzen, unabhängig vom genauen Vorzustand.
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS notifications (
    id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id   UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE
);

ALTER TABLE notifications ADD COLUMN IF NOT EXISTS typ            TEXT;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS titel          TEXT;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS text           TEXT;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS referenz_typ   TEXT;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS referenz_id    UUID;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS status         TEXT NOT NULL DEFAULT 'offen';
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS created_at     TIMESTAMPTZ DEFAULT now();
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS erledigt_am    TIMESTAMPTZ;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS erledigt_von   UUID REFERENCES users(id);

-- Nachträglich NOT NULL setzen (falls die Tabelle vorher leer war oder alte Zeilen
-- über den Backfill unten befüllt werden) und CHECK-Constraint ergänzen.
UPDATE notifications SET typ = 'unbekannt' WHERE typ IS NULL;
UPDATE notifications SET titel = 'Benachrichtigung' WHERE titel IS NULL;
ALTER TABLE notifications ALTER COLUMN typ SET NOT NULL;
ALTER TABLE notifications ALTER COLUMN titel SET NOT NULL;

DO $$ BEGIN
    ALTER TABLE notifications ADD CONSTRAINT notifications_status_check CHECK (status IN ('offen', 'erledigt'));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

CREATE INDEX IF NOT EXISTS idx_notifications_community_status ON notifications(community_id, status);

ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications FORCE ROW LEVEL SECURITY;

DO $$ BEGIN
    CREATE POLICY community_isolation ON notifications
        USING (community_id = current_setting('app.community_id', true)::uuid);
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

-- ─────────────────────────────────────────
-- SEPA-Mandat: zweite, eigenständige Unterschrift zusätzlich zur
-- Beitrittserklärung, sofern eine IBAN angegeben wurde (Lastschrifteinzug).
-- ─────────────────────────────────────────

ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS bezug_zaehlpunkt TEXT;
ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS einspeisung_zaehlpunkt TEXT;
ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS sepa_signature_image TEXT;
ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS sepa_signed_at TIMESTAMPTZ;

-- ─────────────────────────────────────────
-- Admin-Aktivitätslog: platform_admin soll sehen, was in der Plattform
-- gemacht wird (Abrechnung, Mitglieder, EDA-Import, Fehlermeldungen,
-- Änderungen an Mitglied/EEG).
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS audit_log (
    id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id   UUID REFERENCES communities(id) ON DELETE CASCADE,
    user_id        UUID REFERENCES users(id),
    aktion         TEXT NOT NULL,   -- z.B. 'member.create', 'member.delete', 'billing.release', 'eda.import_error', ...
    entity_typ     TEXT,
    entity_id      UUID,
    beschreibung   TEXT NOT NULL,
    ist_fehler     BOOLEAN NOT NULL DEFAULT false,
    created_at     TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_audit_log_community ON audit_log(community_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at DESC);

-- Bewusst KEINE Row-Level-Security mit community_id-Filter: der Admin-Log-Screen
-- ist absichtlich plattformweit (platform_admin sieht alle EEGs), außerdem sind
-- manche Einträge (z.B. Login-Fehler, Systemfehler) nicht community-gebunden
-- (community_id NULL).
