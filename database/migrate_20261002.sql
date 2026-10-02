-- Migration 2026-10-02: Drei Abrechnungs-Fixes vor der ersten echten Mehrmandanten-/
-- Mehrquartals-Nutzung (gefunden beim Review zur laufenden Q3-Abrechnung):
--
-- 1. invoices.rechnungsnummer war PLATTFORMWEIT eindeutig (UNIQUE), obwohl die Nummer selbst
--    nur pro EEG fortlaufend ist (Billing::generateDrafts() zählt je community_id). Seit das
--    "RC<Marktpartner-ID>"-Präfix am 26.09.2026 aus der Nummer entfernt wurde (nur noch
--    "RE-<Jahr><laufende Nummer>"), würde die ERSTE Abrechnung einer zweiten EEG mit
--    "RE-260001" an der bereits von einer anderen EEG vergebenen Nummer scheitern. Fix:
--    UNIQUE jetzt auf (community_id, rechnungsnummer) statt global.
-- 2. Rechnungen eines freigegebenen/abgeschlossenen Laufs für das eigentliche, versendete PDF
--    "einfrieren" (siehe webapp/public/index.php, renderInvoicePdf()/Billing::finalize()) --
--    neue Spalten für den abgelegten Datei-Pfad + SHA-256-Hash zur Nachweisbarkeit.
DO $$
DECLARE
    conname text;
BEGIN
    SELECT con.conname INTO conname
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    WHERE rel.relname = 'invoices' AND con.contype = 'u'
      AND con.conkey = (
          SELECT array_agg(attnum) FROM pg_attribute
          WHERE attrelid = rel.oid AND attname = 'rechnungsnummer'
      );
    IF conname IS NOT NULL THEN
        EXECUTE format('ALTER TABLE invoices DROP CONSTRAINT %I', conname);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint con JOIN pg_class rel ON rel.oid = con.conrelid
        WHERE rel.relname = 'invoices' AND con.conname = 'invoices_community_rechnungsnummer_key'
    ) THEN
        ALTER TABLE invoices ADD CONSTRAINT invoices_community_rechnungsnummer_key
            UNIQUE (community_id, rechnungsnummer);
    END IF;
END $$;

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS pdf_frozen_path TEXT;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS pdf_sha256 TEXT;
