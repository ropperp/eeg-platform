-- Migration 2026-10-18 (d): Ausführungsdatum-Auswahl für SEPA-XML-Downloads.
--
-- Speichert je Abrechnungslauf, welches Ausführungsdatum beim letzten Download der
-- Sammellastschrift (pain.008) bzw. der Sammelüberweisung (pain.001) gewählt wurde -- Grundlage
-- für die Vorauswahl im Ausführungsdatum-Modal bei einem erneuten Download desselben Laufs
-- (siehe sepaExecutionDateTiles() in webapp/src/functions.php) und für die Nachvollziehbarkeit
-- (zusätzlich zum ohnehin geschriebenen Audit-Log-Eintrag).
ALTER TABLE billing_runs ADD COLUMN IF NOT EXISTS sepa_collection_date_used DATE;
ALTER TABLE billing_runs ADD COLUMN IF NOT EXISTS sepa_transfer_date_used DATE;
