-- ─────────────────────────────────────────
-- 13.11.2026: Umschaltbares Portal-Design
-- Patrick: neues Dashboard-Design nach einem Video-Vorbild (Glas-Optik) -- als
-- Plattform-Admin-Einstellung, zum Hin- und Herschalten zwischen neuem Look und dem
-- bisherigen Dashboard. 'klassisch' = bisheriges Design (Standard), 'glas' = neuer Look.
-- Idempotent.
-- ─────────────────────────────────────────
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS portal_design TEXT NOT NULL DEFAULT 'klassisch';
ALTER TABLE platform_settings DROP CONSTRAINT IF EXISTS platform_settings_portal_design_check;
ALTER TABLE platform_settings ADD CONSTRAINT platform_settings_portal_design_check
    CHECK (portal_design IN ('klassisch', 'glas'));
