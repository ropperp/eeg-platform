-- Migration 2026-06-19: Vertragsfelder + Generierungszeitstempel
ALTER TABLE members ADD COLUMN IF NOT EXISTS member_iban TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS member_bic  TEXT;

ALTER TABLE members ADD COLUMN IF NOT EXISTS member_since DATE DEFAULT CURRENT_DATE;
ALTER TABLE members ADD COLUMN IF NOT EXISTS member_until DATE;

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_status TEXT NOT NULL DEFAULT 'none';
ALTER TABLE members ADD CONSTRAINT IF NOT EXISTS chk_contract_bezug_status
    CHECK (contract_bezug_status IN ('none', 'created', 'signed'));

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_generated_at TIMESTAMPTZ;

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_status TEXT NOT NULL DEFAULT 'none';
ALTER TABLE members ADD CONSTRAINT IF NOT EXISTS chk_contract_einspeisung_status
    CHECK (contract_einspeisung_status IN ('none', 'created', 'signed'));

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_generated_at TIMESTAMPTZ;
