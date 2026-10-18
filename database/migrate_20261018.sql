-- Migration 2026-10-18: optionales Mandats-Unterschriftsdatum je Mitglied.
--
-- Für den SEPA-Export (DtOfSgntr in pain.008, siehe sepaCollectionData() in
-- webapp/public/index.php) wurde bisher immer vom Registrierungsdatum (beitrittsdatum)
-- ausgegangen. Ein neues, bewusst optionales Feld erlaubt es, das tatsächliche
-- Mandats-Unterschriftsdatum einzutragen, falls es davon abweicht (z.B. offline unterschriebene
-- Beitrittserklärung mit abweichendem Datum).
--
-- Hinweis: members.sepa_mandate_date (siehe migrate_20260808.sql) hat seit seiner Einführung
-- denselben Zweck, bekam aber nie ein Formularfeld/einen Schreibpfad -- ist also auf jedem
-- bestehenden Server weiterhin NULL. Bewusst NICHT wiederverwendet/umbenannt, um nicht
-- ungefragt die Bedeutung eines bestehenden Felds zu ändern -- stattdessen ein eigenes Feld mit
-- deutschem Namen (passend zum Formular). sepaCollectionData() prüft trotzdem beide Felder
-- (neues zuerst), falls sepa_mandate_date auf einem Server doch einmal von Hand gesetzt wurde.
ALTER TABLE members ADD COLUMN IF NOT EXISTS mandat_unterschrift_datum DATE;
COMMENT ON COLUMN members.mandat_unterschrift_datum IS
  'Tatsächliches Unterschriftsdatum des SEPA-Mandats, NUR wenn abweichend vom Registrierungsdatum (beitrittsdatum). NULL = Registrierungsdatum gilt als DtOfSgntr im SEPA-Export.';
