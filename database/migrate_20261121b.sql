-- Migration 2026-11-21 (b): ElWG-Rechtstexte (Bürgerenergie ab 01.10.2026).
--
-- Ausführen:
--   docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261121b.sql
-- Idempotent (mehrfaches Ausführen schadet nicht).

-- ─────────────────────────────────────────
-- 1. Festgehaltene Vertragsfassung
--    Verträge werden live aus der LaTeX-Vorlage gerendert. Ein unterschriebener Vertrag ohne
--    festgehaltene Fassung (NULL) nutzt die eingefrorene Altfassung (*_elwog2010.tex); beim
--    Unterschreiben schreibt die Webapp die aktuelle Fassung (CONTRACT_TEMPLATE_VERSION) hinein.
-- ─────────────────────────────────────────
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_template TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_template TEXT;

-- ─────────────────────────────────────────
-- 2. Plattform-Einstellungen (platform_settings, eine Zeile): Angaben, die nicht im Code stehen
--    sollen (öffentliches Repo) und vom Plattform-Admin gepflegt werden.
--    - Betreiber-Anschrift und Rechtsform für das Impressum (§ 5 ECG)
--    - Hosting-Anbieter als Auftragsverarbeiter (leer = eigener Server in Österreich)
--    - Netzgebühr-Ersparnis in Prozent und Stand der Netztarife für Marketing-Texte
-- ─────────────────────────────────────────
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS operator_legal_form TEXT;
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS operator_address TEXT;
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS hosting_provider TEXT;
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS hosting_provider_note TEXT;
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS netz_savings_percent NUMERIC(5,1);
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS netz_tariff_stand DATE;

-- ─────────────────────────────────────────
-- 3. Lieferantenpflichten nach § 69 ElWG (pro Gemeinschaft, Standard: aus)
--    Pflicht, wenn ein Haushaltsmitglied mit Anlage > 30 kW oder die Gemeinschaft bzw. sonstige
--    Teilnehmer mit > 100 kW teilnehmen, oder wenn ein Organisator bestellt ist.
--    Schaltet frei: Dokumente "Allgemeine Lieferbedingungen" (alb) und "Informationsblatt"
--    (infoblatt), Bestätigung des Informationsblatts beim Online-Beitritt, Rechnungsintervall je
--    Mitglied, Rechnungs-PDF mit abgesetzten Energiewerten, Mitteilung über Änderungen.
-- ─────────────────────────────────────────
ALTER TABLE communities ADD COLUMN IF NOT EXISTS supplier_duties_enabled BOOLEAN NOT NULL DEFAULT false;

ALTER TABLE legal_documents DROP CONSTRAINT IF EXISTS legal_documents_doc_key_check;
ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_doc_key_check
    CHECK (doc_key IN ('agb', 'statuten', 'widerruf', 'impressum', 'datenschutz', 'barrierefreiheit', 'kontakt', 'alb', 'infoblatt'));

-- Rechnungsintervall (§ 69 Abs 1 Z 6, § 43 Abs 2 ElWG): NULL = Standard der Gemeinschaft.
ALTER TABLE members ADD COLUMN IF NOT EXISTS invoice_interval TEXT;
ALTER TABLE members DROP CONSTRAINT IF EXISTS members_invoice_interval_check;
ALTER TABLE members ADD CONSTRAINT members_invoice_interval_check CHECK (invoice_interval IN ('monthly', 'yearly'));

ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS invoice_interval TEXT;
ALTER TABLE membership_applications DROP CONSTRAINT IF EXISTS membership_applications_invoice_interval_check;
ALTER TABLE membership_applications ADD CONSTRAINT membership_applications_invoice_interval_check CHECK (invoice_interval IN ('monthly', 'yearly'));

-- Nachweis der Bestätigung des Informationsblatts beim Online-Beitritt: Zeitpunkt und
-- Fingerabdruck des angezeigten Textes (der Text selbst steht im Verlauf von legal_documents).
ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS infoblatt_confirmed_at TIMESTAMPTZ;
ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS infoblatt_sha256 TEXT;

-- ─────────────────────────────────────────
-- 4. Änderungen der Lieferbedingungen (§ 69 ElWG): Mitteilung an die Mitglieder, Wirksamkeit
--    frühestens einen Monat später. Ein Eintrag = eine Mitteilung (Nachweis).
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS supplier_term_changes (
    id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id  UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    beschreibung  TEXT NOT NULL,
    wirksam_ab    DATE NOT NULL,
    empfaenger    INTEGER NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by    UUID REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_supplier_term_changes_community ON supplier_term_changes (community_id, created_at DESC);
ALTER TABLE supplier_term_changes ENABLE ROW LEVEL SECURITY;
ALTER TABLE supplier_term_changes FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS community_isolation ON supplier_term_changes;
CREATE POLICY community_isolation ON supplier_term_changes
    USING (community_id = current_setting('app.community_id', true)::uuid);
