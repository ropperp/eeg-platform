-- Migration 2026-07-28: Testmodus/Echtbetrieb-Schalter für die Plattform.
--
-- Im Testmodus (Standard) darf die Kundennummern-Vergabe weiterhin Lücken von gelöschten/
-- deaktivierten Mitgliedern auffüllen (praktisch fürs Testen, damit man nicht schnell
-- durch den Nummernkreis läuft). Im Echtbetrieb wird eine einmal vergebene Kundennummer nie
-- wieder verwendet -- es wird immer MAX(kundennummer)+1 vergeben, egal ob dazwischen Lücken
-- bestehen.

CREATE TABLE IF NOT EXISTS platform_settings (
    id          INTEGER PRIMARY KEY DEFAULT 1,
    test_mode   BOOLEAN NOT NULL DEFAULT true,
    updated_at  TIMESTAMPTZ DEFAULT now(),
    CONSTRAINT platform_settings_single_row CHECK (id = 1)
);

INSERT INTO platform_settings (id, test_mode) VALUES (1, true) ON CONFLICT (id) DO NOTHING;
