-- Migration 2026-09-07: Gesamterzeugung je Einspeise-Zählpunkt für das Einspeisung-Diagramm
-- (Patrick, 06.09.2026 [Folgetermin]: "Gleich wie bei den Verbrauchern zu den Einspeisern
-- darstellen, wie viel sie einspeisen und wie viel davon in der Energiegemeinschaft verwendet
-- wurde" -- bisher zeigte /portal/my/einspeisung NUR den gemeinschaftlich genutzten Anteil
-- (kwh_gemeinschaft bei GENERATION), nicht die eigene GESAMTE Erzeugung. Genau wie beim
-- Verbrauchs-Diagramm (Gesamtverbrauch vs. Eigendeckung) soll jetzt auch hier die Gesamtgröße
-- als Kontext sichtbar sein.
--
-- Quelle: eine dritte, bisher nicht importierte Kennzahl-Spalte im "Energiedaten"-Sheet des
-- EDA-Exports ("Gesamt-/Überschusserzeugung", siehe eda-parser/parser_interval.py) -- laut
-- Spaltenbeschriftung die GESAMTE eigene Erzeugung des Zählpunkts (Gesamterzeugung bei einem
-- reinen Einspeiser, Überschusserzeugung nach Eigenverbrauch bei einem Prosumer), im Gegensatz
-- zu kwh_messung bei GENERATION (gemeinschaftsweite Summe über ALLE Einspeiser) und
-- kwh_gemeinschaft (nur der über den Teilnahmefaktor der Community zugeteilte Anteil).
--
-- NULL für CONSUMPTION-Zeilen (dort nicht relevant) UND für bereits vor dieser Migration
-- importierte GENERATION-Zeilen (die Spalte wurde vorher nicht gelesen) -- erst ein erneuter
-- Import derselben Zeiträume füllt sie rückwirkend. /portal/my/einspeisung fällt für Tage ohne
-- diesen Wert auf die bisherige Einzel-Linien-Ansicht zurück (siehe dortiger Code-Kommentar).
ALTER TABLE eda_interval_data ADD COLUMN IF NOT EXISTS kwh_erzeugung_gesamt NUMERIC(10,4);
COMMENT ON COLUMN eda_interval_data.kwh_erzeugung_gesamt IS
    'Nur GENERATION: eigene Gesamterzeugung des Zählpunkts lt. EDA-Spalte "Gesamt-/Überschusserzeugung" (NULL bei CONSUMPTION und bei vor dieser Migration importierten Zeilen).';
