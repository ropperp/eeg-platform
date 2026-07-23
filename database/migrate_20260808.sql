-- SEPA-Grundlagen: Gläubiger-Identifikationsnummer (Creditor Identifier) je EEG.
-- Wird für die SEPA-Sammellastschrift (pain.008) im XML-Header benötigt (Format z.B.
-- AT..ZZZ..., bei der OeNB beantragt). Pflege über Portal -> EEG-Einstellungen -> Stammdaten.
ALTER TABLE communities ADD COLUMN IF NOT EXISTS creditor_id TEXT;

-- Mandatsdaten je Mitglied: Die Beitrittserklärung IST das SEPA-Mandat. Referenz (eindeutig je
-- Mandat) + Unterschriftsdatum werden für jede Lastschrift-Transaktion gebraucht. Ist keine
-- Referenz gesetzt, kann sie später aus der Kundennummer abgeleitet werden.
ALTER TABLE members ADD COLUMN IF NOT EXISTS sepa_mandate_ref TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS sepa_mandate_date DATE;
