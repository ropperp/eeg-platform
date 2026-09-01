-- Migration 2026-09-11: Spaltenkommentar für eda_interval_data.kwh_erzeugung_gesamt korrigiert
--
-- Reine Doku-Korrektur, keine Struktur-/Datenänderung. migrate_20260907.sql beschrieb die Spalte
-- als aus der EDA-Spalte "Gesamt-/Überschusserzeugung" stammend -- Fund 31.08.2026 anhand echter
-- Exportdateien (siehe eda-parser/parser_interval.py, TARGET_LABELS-Kommentar): diese Spalte ist
-- in JEDEM geprüften Export leer. kwh_erzeugung_gesamt wird seither stattdessen direkt aus der
-- "Erzeugung lt. Messung entsprechend dem Teilnahmefaktor"-Spalte übernommen (die entgegen ihrem
-- Namen bereits die GESAMTE Erzeugung ist, nicht ein reduzierter Anteil), kwh_gemeinschaft wird
-- um den separat ausgewiesenen "Restüberschuss bei EG und je ZP" reduziert, um den tatsächlich
-- gemeinschaftlich genutzten Anteil zu erhalten.
COMMENT ON COLUMN eda_interval_data.kwh_erzeugung_gesamt IS
    'Nur GENERATION: eigene Gesamterzeugung des Zählpunkts, abgeleitet aus der EDA-Spalte "Erzeugung lt. Messung entsprechend dem Teilnahmefaktor" (siehe eda-parser/parser_interval.py, Fund 31.08.2026 -- die ursprünglich angenommene Quellspalte "Gesamt-/Überschusserzeugung" ist in echten Exporten immer leer). NULL bei CONSUMPTION und bei vor migrate_20260907.sql importierten Zeilen.';
