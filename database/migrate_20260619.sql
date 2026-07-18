-- Migration 2026-06-19: Vertragsfelder + Generierungszeitstempel
ALTER TABLE members ADD COLUMN IF NOT EXISTS member_iban TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS member_bic  TEXT;

ALTER TABLE members ADD COLUMN IF NOT EXISTS member_since DATE DEFAULT CURRENT_DATE;
ALTER TABLE members ADD COLUMN IF NOT EXISTS member_until DATE;

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_status TEXT NOT NULL DEFAULT 'none';
-- Postgres kennt "ADD CONSTRAINT IF NOT EXISTS" nicht (nur bei ADD COLUMN gültig) -- das hat
-- hier von Anfang an einen Syntaxfehler geworfen, auf JEDER Postgres-Version, unabhängig vom
-- DB-Zustand. Sichere Alternative: DO-Block, der ein bereits vorhandenes Constraint abfängt.
DO $$ BEGIN
    ALTER TABLE members ADD CONSTRAINT chk_contract_bezug_status
        CHECK (contract_bezug_status IN ('none', 'created', 'signed'));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_generated_at TIMESTAMPTZ;

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_status TEXT NOT NULL DEFAULT 'none';
DO $$ BEGIN
    ALTER TABLE members ADD CONSTRAINT chk_contract_einspeisung_status
        CHECK (contract_einspeisung_status IN ('none', 'created', 'signed'));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_generated_at TIMESTAMPTZ;
