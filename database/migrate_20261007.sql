-- Horizontale Ergänzung zur manuellen Unterschrift-Fein-Korrektur (siehe migrate_20261006.sql).
-- Patrick, 05.10.2026: "Was ich aber dort noch gern hätte, ist auch ein Links- und
-- Rechtsverschieben, bitte." -- positiv verschiebt nach rechts, negativ nach links.
ALTER TABLE communities ADD COLUMN IF NOT EXISTS signature_offset_x_cm NUMERIC(4,2) NOT NULL DEFAULT 0;
