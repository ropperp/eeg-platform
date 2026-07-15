-- Migration 2026-07-23: Kundennummer plattformweit eindeutig statt nur je EEG.
--
-- Bisher garantierte nur ein UNIQUE-Index auf (community_id, kundennummer) Eindeutigkeit
-- INNERHALB einer EEG -- zwei Mitglieder verschiedener Energiegemeinschaften konnten dieselbe
-- Kundennummer bekommen (z.B. beide "10001"). Da stromfueralle als Plattform gemeinsam für
-- alle EEGs abrechnet, muss die Kundennummer über die ganze Plattform eindeutig sein, weil sie
-- oben auf der Rechnung steht.
--
-- Kollisionsauflösung bewusst zurückhaltend: nur Mitglieder, deren Kundennummer tatsächlich
-- in einer ANDEREN EEG doppelt vergeben ist, bekommen eine neue Nummer (das zuerst angelegte
-- Mitglied behält seine bisherige Nummer). Nicht kollidierende Kundennummern bleiben unverändert,
-- damit bereits verschickte Rechnungen/SEPA-Mandatsreferenzen nicht rückwirkend ihre Nummer ändern.

DO $$
DECLARE
    dup RECORD;
    next_free INTEGER;
BEGIN
    FOR dup IN (
        SELECT id FROM (
            SELECT id, ROW_NUMBER() OVER (PARTITION BY kundennummer ORDER BY created_at) AS rn
            FROM members WHERE kundennummer IS NOT NULL
        ) x WHERE rn > 1
    ) LOOP
        SELECT COALESCE(MAX(kundennummer), 10000) + 1 INTO next_free FROM members;
        UPDATE members
        SET kundennummer = next_free,
            mandatsreferenz = CASE
                WHEN mandatsreferenz IS NOT NULL THEN regexp_replace(mandatsreferenz, '[0-9]+$', next_free::text)
                ELSE NULL
            END
        WHERE id = dup.id;
    END LOOP;
END $$;

DROP INDEX IF EXISTS uq_members_community_kundennummer;
CREATE UNIQUE INDEX IF NOT EXISTS uq_members_kundennummer
    ON members (kundennummer) WHERE kundennummer IS NOT NULL;
