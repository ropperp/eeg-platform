-- Rein informatives Feld: welches Aufteilungsschlüssel-Modell (statisch/dynamisch nach EAG)
-- bei Kärnten Netz für diese Community hinterlegt ist. Die Plattform berechnet den
-- Aufteilungsschlüssel NICHT selbst -- Kärnten Netz wendet ihn an und liefert die bereits
-- aufgeteilten Werte (kwh_teilnahme/kwh_erzeugung) über den EDA-Export, siehe
-- docs/AUFTEILUNGSSCHLUESSEL.md. Dieses Feld dient nur der Dokumentation/Nachvollziehbarkeit
-- (z.B. bei einer Prüfung), hat keinerlei Einfluss auf die Abrechnung.
ALTER TABLE communities ADD COLUMN IF NOT EXISTS aufteilungsschluessel_info TEXT;
