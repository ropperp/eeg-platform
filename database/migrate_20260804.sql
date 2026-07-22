-- Zählpunktnummer pro Rechnungsposition, damit Bezugs-/Einspeisungszeilen auf der Rechnung
-- den zugehörigen Zählpunkt ausweisen können (Mitglieder mit mehreren Zählpunkten bekommen
-- pro Zählpunkt eine eigene Zeile). Billing::release() erzeugt ohnehin bereits ein
-- invoice_items pro (Mitglied, Zählpunkt, Typ) -- bisher fehlte nur die Zählpunkt-Referenz.
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS zaehlpunkt_nr TEXT;
