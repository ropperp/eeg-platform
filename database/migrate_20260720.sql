-- Migration 2026-07-20: Nachträglich editierbare Zählpunkt-Detaildaten
-- (Jahresverbrauch für Bezugs-Zählpunkte, geplante Einspeisung für Einspeise-Zählpunkte).
-- engpassleistung_kw (PV-Anlagenleistung) existiert bereits seit migrate_20260714.sql.

ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS jahresverbrauch_kwh NUMERIC;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS geplante_einspeisung_kwh NUMERIC;
