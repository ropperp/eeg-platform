-- Migration 2026-06-22: zaehler_nr (ESP-Zählernummer) für /power-MQTT-Pfad
-- Idempotent: IF NOT EXISTS / kann mehrfach ausgeführt werden
-- Ausführen: docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20260622.sql

ALTER TABLE metering_points
    ADD COLUMN IF NOT EXISTS zaehler_nr TEXT;

CREATE INDEX IF NOT EXISTS metering_points_zaehler_nr_idx
    ON metering_points (community_id, zaehler_nr)
    WHERE zaehler_nr IS NOT NULL;

COMMENT ON COLUMN metering_points.zaehler_nr IS
    '13-stellige Zählernummer des ESP32-Geräts (MQTT /power-Pfad). '
    'Unabhängig von zaehlpunkt_nr (AT..., EDA-Pfad) und meter_code (EDA-XLSX).';
