-- SEPA-Lastschrift: Konfiguration je EEG + Zahlungsstatus je Rechnung.
--
-- sepa_pain_version: XML-Format der Sammellastschrift, je nach Bank umschaltbar
--   '08' = pain.008.001.08 (neue ISO-20022-Fassung, Standard) | '02' = pain.008.001.02 (alt).
-- sepa_prenotification_days: Vorlauftage zwischen Vorabinformation (Pre-Notification) und
--   Abbuchung. Default 14 (per Mandatstext oft auf 1 verkürzbar).
ALTER TABLE communities ADD COLUMN IF NOT EXISTS sepa_pain_version TEXT NOT NULL DEFAULT '08';
ALTER TABLE communities ADD COLUMN IF NOT EXISTS sepa_prenotification_days INTEGER NOT NULL DEFAULT 14;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'communities_sepa_pain_version_check') THEN
        ALTER TABLE communities ADD CONSTRAINT communities_sepa_pain_version_check
            CHECK (sepa_pain_version IN ('02', '08'));
    END IF;
END$$;

-- Zahlungsstatus je Rechnung: der Abrechnungsprozess ist erst abgeschlossen, wenn jede Rechnung
-- erledigt ist -- positive Salden per SEPA eingezogen, negative Salden (EEG schuldet dem
-- Mitglied) per Überweisung ausgezahlt.
--   offen | eingezogen | ueberwiesen | fehlgeschlagen
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS payment_status TEXT NOT NULL DEFAULT 'offen';
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS paid_at TIMESTAMPTZ;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS prenotified_at TIMESTAMPTZ;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'invoices_payment_status_check') THEN
        ALTER TABLE invoices ADD CONSTRAINT invoices_payment_status_check
            CHECK (payment_status IN ('offen', 'eingezogen', 'ueberwiesen', 'fehlgeschlagen'));
    END IF;
END$$;
