-- Migration 2026-11-21 (b): ElWG-Rechtstexte (Bürgerenergie ab 01.10.2026).
--
-- Ausführen:
--   docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261121b.sql
-- Idempotent (mehrfaches Ausführen schadet nicht).

-- ─────────────────────────────────────────
-- 1. Festgehaltene Vertragsfassung
--    Verträge werden live aus der LaTeX-Vorlage gerendert. Ein unterschriebener Vertrag ohne
--    festgehaltene Fassung (NULL) nutzt die eingefrorene Altfassung (*_elwog2010.tex); beim
--    Unterschreiben schreibt die Webapp die aktuelle Fassung (CONTRACT_TEMPLATE_VERSION) hinein.
-- ─────────────────────────────────────────
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_template TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_template TEXT;

-- ─────────────────────────────────────────
-- 2. Plattform-Einstellungen (platform_settings, eine Zeile): Angaben, die nicht im Code stehen
--    sollen (öffentliches Repo) und vom Plattform-Admin gepflegt werden.
--    - Betreiber-Anschrift und Rechtsform für das Impressum (§ 5 ECG)
--    - Hosting-Anbieter als Auftragsverarbeiter (leer = eigener Server in Österreich)
--    - Netzgebühr-Ersparnis in Prozent und Stand der Netztarife für Marketing-Texte
-- ─────────────────────────────────────────
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS operator_legal_form TEXT;
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS operator_address TEXT;
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS hosting_provider TEXT;
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS hosting_provider_note TEXT;
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS netz_savings_percent NUMERIC(5,1);
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS netz_tariff_stand DATE;
