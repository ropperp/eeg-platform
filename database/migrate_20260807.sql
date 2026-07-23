-- Verträge (Bezugsvereinbarung/Einspeisevertrag) pro EEG abschaltbar machen.
-- Hintergrund: Bei manchen EEGs ist die Beitrittserklärung zugleich der Vertrag UND das
-- SEPA-Mandat; ein zusätzlicher mehrseitiger Vertrag ist dann unnötiger Papierkram. Ist der
-- Schalter aus, werden im Mitglieder-Portal und in den Obmann-Ansichten alle Vertragsfunktionen
-- (Bezugsvereinbarung, Einspeisevertrag: Ansehen, Unterschreiben, Senden) ausgeblendet und die
-- zugehörigen Routen gesperrt. Standard: true (rückwärtskompatibel).
ALTER TABLE communities ADD COLUMN IF NOT EXISTS contracts_enabled BOOLEAN NOT NULL DEFAULT true;
