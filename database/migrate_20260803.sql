-- Zusätzliche Rechnungs-Footer-Angaben je EEG (Kontakt + Bankverbindung) -- alle optional,
-- die Vorlage blendet die jeweilige Footer-Zeile aus, wenn leer. account_holder leer =
-- Vorlage fällt selbst auf den EEG-Namen zurück.
ALTER TABLE communities ADD COLUMN IF NOT EXISTS contact_phone TEXT;   -- Obmann/Kontakt-Mobilnummer
ALTER TABLE communities ADD COLUMN IF NOT EXISTS contact_email TEXT;   -- Kontakt-E-Mail
ALTER TABLE communities ADD COLUMN IF NOT EXISTS bank_name TEXT;
ALTER TABLE communities ADD COLUMN IF NOT EXISTS account_holder TEXT;
