-- 16.11.2026: Messwert-Speicherung für den Dauerbetrieb.
--
-- 1) esp_15min: Zusammenfassung der ESP-Livewerte in 15-Minuten-Fenster je Zählpunkt (Mittel/Spitze der
--    Leistung, letzter Zählerstand je Richtung). Wird vom mqtt-subscriber laufend gefüllt (rollup_loop)
--    und überlebt das Aufräumen der Rohdaten (2). Normale Tabelle, braucht kein TimescaleDB.
-- 2) TimescaleDB-Richtlinien für esp_measurements (Rohdaten im 5-Sekunden-Takt, nur Visualisierung,
--    KEINE Abrechnungsgrundlage): Komprimierung nach 7 Tagen und Löschen der Rohdaten nach 400 Tagen.
--    Beides in abgesicherten Blöcken: schlägt eine Anweisung fehl (z.B. weil diese TimescaleDB-Version
--    Komprimierung zusammen mit Row-Level-Security nicht erlaubt), gibt es nur eine NOTICE-Meldung und
--    die Migration läuft weiter -- es wird nie etwas gelöscht oder umgebaut, was nicht klappt.
-- Idempotent.

CREATE TABLE IF NOT EXISTS esp_15min (
    bucket                    TIMESTAMPTZ NOT NULL,        -- Beginn des 15-Minuten-Fensters
    community_id              UUID NOT NULL,
    metering_point_id         UUID NOT NULL REFERENCES metering_points(id) ON DELETE CASCADE,
    samples                   INTEGER NOT NULL,            -- Anzahl der Rohwerte im Fenster
    bezug_w_avg               INTEGER,
    einspeisung_w_avg         INTEGER,
    bezug_w_max               INTEGER,
    einspeisung_w_max         INTEGER,
    energy_bezug_wh_end       BIGINT,                      -- letzter Zählerstand im Fenster (Wh)
    energy_einspeisung_wh_end BIGINT,
    PRIMARY KEY (metering_point_id, bucket)
);
CREATE INDEX IF NOT EXISTS idx_esp_15min_community_bucket ON esp_15min (community_id, bucket DESC);

-- Bis wohin schon zusammengefasst wurde (ein Eintrag). Eigener Merkpunkt statt "letzte vorhandene
-- Zeile", damit auch ein Zeitraum OHNE Messwerte (Stromausfall, Pause) als erledigt gilt.
CREATE TABLE IF NOT EXISTS esp_rollup_state (
    id         INTEGER PRIMARY KEY CHECK (id = 1),
    done_until TIMESTAMPTZ NOT NULL
);

ALTER TABLE esp_15min ENABLE ROW LEVEL SECURITY;
ALTER TABLE esp_15min FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS community_isolation ON esp_15min;
CREATE POLICY community_isolation ON esp_15min
    USING (community_id = current_setting('app.community_id', true)::uuid);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'timescaledb') THEN
        RAISE NOTICE 'TimescaleDB nicht vorhanden -- Komprimierung/Aufbewahrung übersprungen.';
        RETURN;
    END IF;

    BEGIN
        PERFORM add_retention_policy('esp_measurements', INTERVAL '400 days', if_not_exists => true);
        RAISE NOTICE 'Aufbewahrung: Rohdaten esp_measurements werden nach 400 Tagen gelöscht (Zusammenfassung esp_15min bleibt).';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'Aufbewahrungsregel nicht gesetzt: %', SQLERRM;
    END;

    BEGIN
        ALTER TABLE esp_measurements SET (
            timescaledb.compress,
            timescaledb.compress_segmentby = 'metering_point_id',
            timescaledb.compress_orderby = 'time DESC'
        );
        PERFORM add_compression_policy('esp_measurements', INTERVAL '7 days', if_not_exists => true);
        RAISE NOTICE 'Komprimierung: Chunks älter als 7 Tage werden komprimiert.';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'Komprimierung nicht aktiviert (bleibt wie bisher): %', SQLERRM;
    END;
END $$;
