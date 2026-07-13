-- Migration 2026-07-19: Defensive NOT-NULL-Reparatur für alle Phase-1-Tabellen,
-- Unterschrift-Upload für Manager/Obmann

-- ─────────────────────────────────────────
-- Mitglieder-Dateiupload schlägt mit HTTP 500 fehl — gleiches Muster wie bei
-- notifications ("audience"): auf dem Produktivsystem existieren vermutlich
-- weitere, uns unbekannte Alt-Spalten mit NOT NULL ohne Default auf einer
-- oder mehreren der Phase-1-Tabellen. Da wir den genauen Vorzustand nicht
-- kennen, wird hier generisch für ALLE Phase-1-Tabellen jede NOT-NULL-
-- Bedingung entfernt, die nicht zu unserem bekannten Schema gehört
-- (Primary-/Foreign-Keys, die strukturell nötig sind, bleiben unangetastet).
-- ─────────────────────────────────────────

DO $$
DECLARE
    tbl TEXT;
    col RECORD;
    keep_cols TEXT[];
BEGIN
    FOREACH tbl IN ARRAY ARRAY['contracts', 'member_files', 'membership_applications', 'email_settings', 'email_templates']
    LOOP
        CASE tbl
            WHEN 'contracts' THEN keep_cols := ARRAY['id', 'member_id', 'community_id'];
            WHEN 'member_files' THEN keep_cols := ARRAY['id', 'community_id', 'member_id'];
            WHEN 'membership_applications' THEN keep_cols := ARRAY['id', 'community_id'];
            WHEN 'email_settings' THEN keep_cols := ARRAY['id', 'community_id'];
            WHEN 'email_templates' THEN keep_cols := ARRAY['id', 'community_id'];
            ELSE keep_cols := ARRAY['id'];
        END CASE;

        FOR col IN
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = tbl
              AND is_nullable = 'NO'
              AND column_name != ALL(keep_cols)
        LOOP
            EXECUTE format('ALTER TABLE %I ALTER COLUMN %I DROP NOT NULL', tbl, col.column_name);
        END LOOP;
    END LOOP;
END $$;

-- ─────────────────────────────────────────
-- Unterschrift für Verträge: jeder User (i.d.R. Obmann/Obfrau) kann seine
-- eigene Unterschrift hinterlegen; wird beim Erzeugen von Bezugs-/
-- Einspeisevereinbarungen als Bild über der EEG-Unterschriftslinie
-- eingefügt.
-- ─────────────────────────────────────────

ALTER TABLE users ADD COLUMN IF NOT EXISTS signature_image TEXT;
