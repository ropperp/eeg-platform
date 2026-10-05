-- Manuelle Kalibrierung der Unterschrift-Position auf PDF-Vorlagen (Beitrittserklärung).
-- Patrick, 05.10.2026: "Gib jedem Obmann die Möglichkeit, das Unterschriftsfeld selbst auf der
-- Beitrittserklärung zu platzieren [...] um die am besten zu positionieren." Ergänzt den
-- automatisch berechneten \floatsig-Versatz (signatureRaise() in index.php) um einen manuellen
-- Fein-Korrektur-Wert je EEG -- positiv hebt die Unterschrift zusätzlich an, negativ senkt sie ab.
ALTER TABLE communities ADD COLUMN IF NOT EXISTS signature_offset_cm NUMERIC(4,2) NOT NULL DEFAULT 0;
