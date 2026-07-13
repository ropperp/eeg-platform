-- Migration 2026-07-15: Bankdaten-Ergänzung, Zustimmungen, Löschbarkeit (Superadmin)

-- ─────────────────────────────────────────
-- members: Kontoinhaber/Adresse für SEPA + rechtliche Zustimmungen
-- ─────────────────────────────────────────

ALTER TABLE members ADD COLUMN IF NOT EXISTS kontoinhaber TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS konto_adresse TEXT;

ALTER TABLE members ADD COLUMN IF NOT EXISTS zustimmung_mitgliedschaft BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE members ADD COLUMN IF NOT EXISTS zustimmung_vollmacht BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE members ADD COLUMN IF NOT EXISTS zustimmung_widerrufsfrist BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE members ADD COLUMN IF NOT EXISTS zustimmung_email_kommunikation BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE members ADD COLUMN IF NOT EXISTS zustimmung_datenschutz BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE members ADD COLUMN IF NOT EXISTS zustimmung_agb BOOLEAN NOT NULL DEFAULT false;

-- Bestehende Mitglieder sind bereits auf anderem Weg (Papier) beigetreten — nicht rückwirkend
-- als "nicht zugestimmt" markieren, sonst würden künftige Prüfungen sie fälschlich blockieren.
UPDATE members SET
    zustimmung_mitgliedschaft = true,
    zustimmung_vollmacht = true,
    zustimmung_widerrufsfrist = true,
    zustimmung_email_kommunikation = true,
    zustimmung_datenschutz = true,
    zustimmung_agb = true
WHERE created_at < now();

-- ─────────────────────────────────────────
-- Löschbarkeit: fehlende ON DELETE CASCADE ergänzen, damit Superadmin
-- Testdaten (Mitglieder, Abrechnungen) sauber löschen kann
-- ─────────────────────────────────────────

ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_billing_run_id_fkey;
ALTER TABLE invoices ADD CONSTRAINT invoices_billing_run_id_fkey
    FOREIGN KEY (billing_run_id) REFERENCES billing_runs(id) ON DELETE CASCADE;

ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_member_id_fkey;
ALTER TABLE invoices ADD CONSTRAINT invoices_member_id_fkey
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE;

ALTER TABLE membership_applications DROP CONSTRAINT IF EXISTS membership_applications_member_id_fkey;
ALTER TABLE membership_applications ADD CONSTRAINT membership_applications_member_id_fkey
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL;

-- Login-Konto eines Mitglieds muss löschbar sein (Testbenutzer), ohne die Mitglieds-/Kundendaten
-- selbst zu entfernen — Mitglied bleibt bestehen, verliert nur die Verknüpfung zum User-Login.
ALTER TABLE members DROP CONSTRAINT IF EXISTS members_user_id_fkey;
ALTER TABLE members ADD CONSTRAINT members_user_id_fkey
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
