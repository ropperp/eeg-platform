-- Migration 2026-10-18 (c): Teilnahme-ab pro Zählpunkt aus den ECHTEN EDA-Zeiträumen statt aus
-- dem Beitrittsdatum des Mitglieds.
--
-- Der EDA-Energiedatenreport liefert pro Zählpunkt und Monat ein "Zeitraum"-Feld
-- ("DD.MM.JJJJ-DD.MM.JJJJ"), aus dem der Parser bisher nur das Enddatum ("bis") als Zeitstempel
-- übernommen hat -- das Anfangsdatum ("von", bei unterjährigem Beitritt z.B. "02.07.2026" statt
-- "01.07.2026") wurde verworfen. Dadurch stand auf der Rechnung ein "Teilnahme ab"-Datum aus dem
-- Beitrittsdatum des Mitglieds statt dem tatsächlichen, teils abweichenden EDA-Zeitraum des
-- jeweiligen Zählpunkts (Patrick, 18.10.2026: 8 von 11 Rechnungen stimmten nicht, u.a. weil ein
-- Mitglied für Bezug und Einspeisung unterschiedliche Zählpunkt-Zeiträume haben kann).
--
-- Bewusst als reines DATE (nicht TIMESTAMPTZ) -- dieselbe Zeitzonen-Falle wie bei
-- eda_imports.period_from/period_to (siehe Billing::missingMonths()) soll von vornherein
-- ausgeschlossen sein.
ALTER TABLE eda_measurements ADD COLUMN IF NOT EXISTS teilnahme_von DATE;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS teilnahme_ab DATE;

-- Rein additiv -- bestehende Zeilen bleiben NULL, bis ein erneuter EDA-Import (für Entwürfe
-- weiterhin möglich, siehe eda-parser/parser.py: _billing_period_finalized()) die Werte
-- nachträglich befüllt. Kein Backfill aus vorhandenen Daten möglich, da das "von"-Datum bisher
-- nirgends gespeichert wurde.
