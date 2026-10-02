-- Migration 2026-10-03: Rechnungsnummern wieder PLATTFORMWEIT eindeutig statt pro EEG.
--
-- migrate_20261002.sql hatte die UNIQUE-Constraint auf invoices.rechnungsnummer von global auf
-- (community_id, rechnungsnummer) umgestellt, damit zwei verschiedene EEGs nicht dieselbe
-- Nummer "RE-260001" vergeben können. Patrick, noch am selben Tag: als Plattformbetreiber über
-- mehrere EEGs hinweg muss er jede Rechnungsnummer eindeutig einer EEG zuordnen können -- bei
-- einer rein pro-EEG eindeutigen Nummer könnten zwei verschiedene EEGs exakt dieselbe Nummer
-- "RE-260001" vergeben, was aus Plattformsicht nicht mehr unterscheidbar wäre. Diese Migration
-- macht den Schritt rückgängig: zurück auf eine einzige, globale UNIQUE(rechnungsnummer).
--
-- (Die andere Hälfte von migrate_20261002.sql -- pdf_frozen_path/pdf_sha256 für eingefrorene
-- Rechnungs-PDFs -- bleibt unverändert bestehen, nur die Eindeutigkeits-Constraint wird hier
-- zurückgesetzt.)
DO $$
DECLARE
    conname text;
BEGIN
    SELECT con.conname INTO conname
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    WHERE rel.relname = 'invoices' AND con.conname = 'invoices_community_rechnungsnummer_key';
    IF conname IS NOT NULL THEN
        EXECUTE format('ALTER TABLE invoices DROP CONSTRAINT %I', conname);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint con JOIN pg_class rel ON rel.oid = con.conrelid
        WHERE rel.relname = 'invoices' AND con.conname = 'invoices_rechnungsnummer_key'
    ) THEN
        ALTER TABLE invoices ADD CONSTRAINT invoices_rechnungsnummer_key UNIQUE (rechnungsnummer);
    END IF;
END $$;
