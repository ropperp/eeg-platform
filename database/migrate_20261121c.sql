-- Migration 2026-11-21 (c): Rechnungsintervall höchstens quartalsweise oder jährlich (statt monatlich/jährlich).
-- Nach migrate_20261121b.sql einspielen. Idempotent.
--   docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261121c.sql
ALTER TABLE members DROP CONSTRAINT IF EXISTS members_invoice_interval_check;
ALTER TABLE membership_applications DROP CONSTRAINT IF EXISTS membership_applications_invoice_interval_check;
UPDATE members SET invoice_interval = 'quarterly' WHERE invoice_interval = 'monthly';
UPDATE membership_applications SET invoice_interval = 'quarterly' WHERE invoice_interval = 'monthly';
ALTER TABLE members ADD CONSTRAINT members_invoice_interval_check CHECK (invoice_interval IN ('quarterly', 'yearly'));
ALTER TABLE membership_applications ADD CONSTRAINT membership_applications_invoice_interval_check CHECK (invoice_interval IN ('quarterly', 'yearly'));
