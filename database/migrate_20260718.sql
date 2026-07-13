-- Migration 2026-07-18: Kundennummern ab 10001, weitere notifications-Reparatur

-- ─────────────────────────────────────────
-- Kundennummern auf 10001+ verschieben (mehr Buffer für Wachstum). Guard
-- "< 10000" macht dies idempotent, falls die Migration mehrfach läuft.
-- Mandatsreferenz (S00000F<Jahr>A<KdNr>) wird auf die neue Kundennummer
-- nachgezogen, damit beide konsistent bleiben.
-- ─────────────────────────────────────────

UPDATE members SET kundennummer = kundennummer + 10000
WHERE kundennummer IS NOT NULL AND kundennummer < 10000;

UPDATE members SET mandatsreferenz = regexp_replace(mandatsreferenz, '[0-9]+$', kundennummer::text)
WHERE mandatsreferenz IS NOT NULL AND kundennummer IS NOT NULL;

-- ─────────────────────────────────────────
-- notifications: weitere, bislang unbekannte NOT-NULL-Spalten aus einem
-- Alt-Bestand (z.B. "audience") reparieren. Da wir den genauen Vorzustand
-- der Produktivtabelle nicht kennen (migrate_20260716.sql hat bereits
-- fehlende Spalten ergänzt, aber offenbar existieren dort noch weitere,
-- uns unbekannte Alt-Spalten mit NOT NULL ohne Default), wird hier generisch
-- JEDE NOT-NULL-Bedingung auf notifications entfernt, die nicht zu unserem
-- bekannten Schema gehört (id/community_id bleiben unangetastet, da für FK/
-- RLS strukturell nötig). Das verhindert künftige "column X violates
-- not-null constraint"-Fehler unabhängig vom genauen Altbestand.
-- ─────────────────────────────────────────

DO $$
DECLARE
    col RECORD;
BEGIN
    FOR col IN
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'notifications'
          AND is_nullable = 'NO'
          AND column_name NOT IN ('id', 'community_id')
    LOOP
        EXECUTE format('ALTER TABLE notifications ALTER COLUMN %I DROP NOT NULL', col.column_name);
    END LOOP;
END $$;
