-- Migration 2026-08-15: Audit-Log um strukturierte Vorher/Nachher-Werte erweitern.
--
-- Bisher hielt audit_log nur einen Freitext (beschreibung). Für die lückenlose
-- Nachvollziehbarkeit ("wer hat wo welchen Wert von X auf Y geändert") kommt eine JSONB-Spalte
-- dazu: pro geändertem Feld { label, von, auf }. Sensible Werte (Passwörter, Client-Secrets)
-- werden bewusst NICHT protokolliert (siehe auditDiff()/Ignore-Liste im Code).
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS aenderungen JSONB;
