-- Migration 2026-10-01: Monatsgenaue EDA-Datenqualität je Zählpunkt (aus der "Detailübersicht"-
-- Sheet des EDA-Energiedatenreports, Abschnitt "Energiedaten je Zählpunkt").
--
-- Patrick, 01.10.2026: "Sieht man in der Monatsreport von den drei Monaten nicht auch die
-- einzelnen Monate [...] wo man heraussehen kann, welcher Monat [...] jetzt wirklich schuld ist
-- via L3?" -- Antwort: ja, die Detailübersicht liefert pro Zählpunkt bereits eine eigene Zeile
-- JE KALENDERMONAT mit eigener Datenqualität, unabhängig davon, ob ein Einzelmonat oder ein
-- ganzes Quartal auf einmal exportiert wurde. Bisher wertete der Parser nur die Gesamtübersicht
-- aus (eine Zeile für den KOMPLETTEN angefragten Zeitraum je Zählpunkt) -- diese Tabelle hält
-- zusätzlich die monatsgenaue Aufschlüsselung, damit /portal/eda/upload exakt zeigen kann,
-- welcher Monat (nicht nur "irgendwo im Zeitraum") noch L3 ist.
CREATE TABLE IF NOT EXISTS eda_measurement_quality_monthly (
    id                 UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id       UUID NOT NULL,
    metering_point_id  UUID NOT NULL REFERENCES metering_points(id) ON DELETE CASCADE,
    month              DATE NOT NULL,  -- erster Tag des Kalendermonats
    quality            TEXT CHECK (quality IN ('L1', 'L2', 'L3')),
    completeness       TEXT CHECK (completeness IN ('COMPLETE', 'INCOMPLETE')),
    updated_at         TIMESTAMPTZ DEFAULT now(),
    UNIQUE (community_id, metering_point_id, month)
);
CREATE INDEX IF NOT EXISTS idx_eda_quality_monthly_lookup
    ON eda_measurement_quality_monthly (community_id, month);

-- RLS wie bei allen anderen mandantenspezifischen Tabellen (siehe database/init.sql).
ALTER TABLE eda_measurement_quality_monthly ENABLE ROW LEVEL SECURITY;
CREATE POLICY community_isolation ON eda_measurement_quality_monthly
    USING (community_id = current_setting('app.community_id', true)::uuid);
