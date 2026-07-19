-- E-Mail-Signatur (global, allen ausgehenden Mails angehängt -- siehe Mailer::send()).
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS signature_html TEXT;

-- Manuelle Zusatzpositionen auf Rechnungen (z.B. einmaliger Rabatt/Gutschrift im ersten
-- Quartal) -- freier Text statt der drei festen Positionstypen, mit eigener Menge/Einheit,
-- damit sie unabhängig von Bezug/Einspeisung/Mitgliedsbeitrag auf der Rechnung erscheinen.
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS label TEXT;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS quantity NUMERIC(10,3);
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS unit TEXT;
ALTER TABLE invoice_items DROP CONSTRAINT IF EXISTS invoice_items_type_check;
ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_type_check
    CHECK (type IN ('bezug', 'einspeisung', 'mitgliedsbeitrag', 'manuell'));

-- Zusatzpositionen werden auf Ebene des Abrechnungslaufs erfasst (bevor freigegeben wird) und
-- gelten für alle Mitglieder dieses Laufs -- Billing::release() übernimmt sie beim Erzeugen
-- jeder einzelnen Rechnung 1:1 in invoice_items (type='manuell').
CREATE TABLE IF NOT EXISTS billing_run_extra_items (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    billing_run_id  UUID NOT NULL REFERENCES billing_runs(id) ON DELETE CASCADE,
    community_id    UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    label           TEXT NOT NULL,
    quantity        NUMERIC(10,3) NOT NULL DEFAULT 1,
    unit            TEXT NOT NULL DEFAULT 'Stk',
    amount_eur      NUMERIC(10,2) NOT NULL,        -- negativ = Gutschrift/Rabatt, positiv = zusätzliche Belastung
    created_at      TIMESTAMPTZ DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_billing_run_extra_items_run ON billing_run_extra_items(billing_run_id);

ALTER TABLE billing_run_extra_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE billing_run_extra_items FORCE ROW LEVEL SECURITY;
DO $$ BEGIN
    CREATE POLICY community_isolation ON billing_run_extra_items
        USING (community_id = current_setting('app.community_id', true)::uuid);
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;
