-- Migration 2026-07-29: Digitale Unterschrift für Bezugs-/Einspeisevereinbarung.
--
-- Bisher war "signed" (contract_*_status) ein rein manuell vom Manager gesetzter Status (z.B.
-- nachdem eine unterschriebene Papierfassung per Post zurückkam) -- es gab keine tatsächliche
-- Unterschriftserfassung. Jetzt kann das Mitglied im Portal per Maus/Finger auf einem Zeichenfeld
-- unterschreiben; das Bild wird hier gespeichert und bei jeder PDF-Ansicht in den Vertrag
-- eingebettet (analog zu users.signature_image für die Unterschrift der EEG-Seite).

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_customer_signature TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_signed_at TIMESTAMPTZ;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_signer_ip TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_customer_signature TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_signed_at TIMESTAMPTZ;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_signer_ip TEXT;
