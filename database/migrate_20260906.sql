-- Migration 2026-09-06: Live-ESP-Spiegelung für Demo-Mitglied-Identitäten (Patrick, 05./06.09.2026:
-- "du sollst bitte die Echtzeit-Werte zum Einspeisen von Daniel Ropper synchronisieren und die
-- Echtzeit-Daten von Stefanie Schwaiger für den Verbraucher verwenden. Aber bitte in Echtzeit.").
--
-- "Verbraucher 1"/"Einspeiser 1" (siehe migrate_20260905.sql) haben keine eigene ESP32-Hardware,
-- sollen aber trotzdem eine LIVE "Aktuelle Leistung"-Anzeige haben -- KEINE synthetische
-- Simulation, sondern echte, laufend gespiegelte Live-Messwerte der beiden echten
-- Vorlage-Mitglieder (Stefanie Schwaiger -> Verbraucher 1, Daniel Ropper -> Einspeiser 1).
--
-- Jeder (Demo-)Zählpunkt kann über mirror_source_metering_point_id auf einen ECHTEN Zählpunkt
-- zeigen, dessen Live-Messwerte er 1:1 übernehmen soll. Ein Trigger auf esp_measurements sorgt
-- dafür, dass JEDE neue Live-Messung des echten Zählpunkts (mqtt-subscriber schreibt alle ~5s,
-- siehe migrate_20260903.sql) sofort auch für den gespiegelten Zählpunkt geschrieben wird --
-- echtes Echtzeit-Spiegeln, keine periodische Kopie mit Verzögerung. metering_points.esp_online/
-- esp_last_seen_at/meter_reachable/meter_last_seen_at werden dabei genauso mitgezogen wie beim
-- normalen mqtt-subscriber-Insert (siehe dortiges main.py), damit der gespiegelte Zählpunkt auch
-- in der "ESP online: X von Y"-Zählung und den Live-Leistungs-Summen ganz normal mitzählt.
ALTER TABLE metering_points
    ADD COLUMN IF NOT EXISTS mirror_source_metering_point_id UUID REFERENCES metering_points(id) ON DELETE SET NULL;

COMMENT ON COLUMN metering_points.mirror_source_metering_point_id IS
    'Falls gesetzt: dieser Zählpunkt spiegelt live die ESP-Echtzeitdaten des referenzierten '
    'Zählpunkts (siehe Trigger trg_mirror_esp_measurement) -- für Demo-Mitglieder ohne eigene '
    'Hardware (siehe scripts/create_demo_members.php).';

-- Rekursionssicher von selbst: die hier eingefügte Zeile für den Ziel-Zählpunkt löst den
-- Trigger erneut aus, aber da NICHTS auf einen Demo-Zählpunkt als mirror_source zeigt, findet
-- die Schleife beim zweiten Durchlauf keine Treffer mehr und bricht ohne weitere Einfügung ab.
CREATE OR REPLACE FUNCTION mirror_esp_measurement() RETURNS TRIGGER AS $$
DECLARE
    target RECORD;
BEGIN
    FOR target IN
        SELECT id FROM metering_points WHERE mirror_source_metering_point_id = NEW.metering_point_id
    LOOP
        INSERT INTO esp_measurements
            (time, community_id, metering_point_id, power_bezug_w, power_einspeisung_w,
             energy_bezug_wh, energy_einspeisung_wh, znr)
        VALUES
            (NEW.time, NEW.community_id, target.id, NEW.power_bezug_w, NEW.power_einspeisung_w,
             NEW.energy_bezug_wh, NEW.energy_einspeisung_wh, NEW.znr);

        UPDATE metering_points
           SET esp_online = true, esp_last_seen_at = NEW.time,
               meter_reachable = true, meter_last_seen_at = NEW.time
         WHERE id = target.id;
    END LOOP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_mirror_esp_measurement ON esp_measurements;
CREATE TRIGGER trg_mirror_esp_measurement
    AFTER INSERT ON esp_measurements
    FOR EACH ROW EXECUTE FUNCTION mirror_esp_measurement();
