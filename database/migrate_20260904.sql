-- Migration 2026-09-04: Viertelstundenwerte aus dem EDA-Portal für ein Mitglieder-Diagramm
-- (Verbrauch vs. gemeinschaftliche Eigendeckung, viertelstündlich) -- Patrick, 03.09.2026:
-- "vielleicht kannst du mir ja für die Mitglieder [...] die Daten einlesen und den Mitgliedern
-- als Diagramm in der App oder auf der Webseite darstellen lassen".
--
-- Bewusst eine EIGENE Tabelle, NICHT eda_measurements: eda_measurements trägt eine Zeile PRO
-- ZÄHLPUNKT PRO MONAT (Grundlage für Billing::generateDrafts(), das über den Zeitraum
-- SUMMIERT) -- würden zusätzlich Viertelstundenwerte für denselben Zeitraum in dieselbe Tabelle
-- geschrieben, würde jede Abrechnungssumme doppelt gezählt (einmal aus der Monatszeile, einmal
-- aus den ~2.976 Viertelstundenzeilen desselben Monats). Diese Tabelle ist rein für die
-- Diagramm-Anzeige gedacht, hat NICHTS mit der Abrechnung zu tun.
--
-- Quelle: der zweite EDA-Export-Typ "Energiedaten" (Viertelstundenwerte, eigenes Sheet neben
-- dem bereits importierten monatlichen "Gesamtübersicht/Detailübersicht"-Bericht) -- Format
-- anhand einer echten Exportdatei verifiziert (Patrick, 03.09.2026,
-- RC108175_20260701T00_0020260731T23_45.xlsx). Siehe eda-parser/parser_interval.py.

CREATE TABLE IF NOT EXISTS eda_interval_data (
    time               TIMESTAMPTZ NOT NULL,
    community_id       UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    metering_point_id  UUID NOT NULL REFERENCES metering_points(id) ON DELETE CASCADE,
    energy_direction   TEXT NOT NULL CHECK (energy_direction IN ('CONSUMPTION', 'GENERATION')),
    -- CONSUMPTION: "Gesamtverbrauch lt. Messung" · GENERATION: "Gesamte gemeinschaftliche Erzeugung"
    kwh_messung        NUMERIC(10,4),
    -- CONSUMPTION: "Eigendeckung gemeinschaftliche Erzeugung" (= aus der EEG gedeckter Anteil,
    -- der Rest von kwh_messung kam aus dem öffentlichen Netz) · GENERATION: "Erzeugung lt.
    -- Messung entsprechend dem Teilnahmefaktor und EC-ID"
    kwh_gemeinschaft   NUMERIC(10,4),
    quality            TEXT CHECK (quality IN ('L1', 'L2', 'L3'))
);
SELECT create_hypertable('eda_interval_data', 'time', chunk_time_interval => INTERVAL '7 days', if_not_exists => TRUE);
CREATE INDEX IF NOT EXISTS idx_eda_interval_data_mp ON eda_interval_data (community_id, metering_point_id, time DESC);

ALTER TABLE eda_interval_data ENABLE ROW LEVEL SECURITY;
ALTER TABLE eda_interval_data FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS community_isolation ON eda_interval_data;
CREATE POLICY community_isolation ON eda_interval_data
    USING (community_id = current_setting('app.community_id', true)::uuid);

-- Import-Protokoll, analog eda_imports -- eigene, schlankere Tabelle statt eda_imports
-- mitzubenutzen: kein "neu_angelegt"/"status"-Bedarf, dafür können sich Zeiträume hier bewusst
-- ÜBERLAPPEN (Patrick lädt alle paar Tage einen neuen, überlappenden Ausschnitt hoch, siehe
-- Parser-Kommentar zu import_to_db() in parser_interval.py), eda_imports' Logik geht dagegen
-- von exakt einer Datei pro Kalendermonat aus.
CREATE TABLE IF NOT EXISTS eda_interval_imports (
    id                UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id      UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    imported_by       UUID REFERENCES users(id),
    filename          TEXT NOT NULL,
    period_from       TIMESTAMPTZ NOT NULL,
    period_to         TIMESTAMPTZ NOT NULL,
    records_imported  INTEGER DEFAULT 0,
    warnings          JSONB DEFAULT '[]',
    imported_at       TIMESTAMPTZ DEFAULT now()
);

ALTER TABLE eda_interval_imports ENABLE ROW LEVEL SECURITY;
ALTER TABLE eda_interval_imports FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS community_isolation ON eda_interval_imports;
CREATE POLICY community_isolation ON eda_interval_imports
    USING (community_id = current_setting('app.community_id', true)::uuid);
