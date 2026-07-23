-- EDA-Datenqualität als Freigabe-Kriterium für die Abrechnung.
--
-- Bisher durfte ein Abrechnungslauf erst 60 Tage nach Quartalsende freigegeben werden
-- (starres Kalenderfenster, billing_runs.freigabe_nach). Das war nur ein grober Ersatz dafür,
-- dass die Messwerte von Kärnten Netz bis dahin "belastbar" sein sollten. Die eigentliche
-- Aussage darüber liefert aber die EDA selbst über die Datenqualität je Viertelstundenwert
-- (Wertekategorie L1/L2/L3) bzw. den "Status Datenübermittlung" im Monatsbericht des
-- Abrechnungs-Tools (Eder-XLSX):
--   L1 = Echtwert, gemessen             -> belastbar (bester Wert)
--   L2 = Ersatzwert, belastbar          -> belastbar (ändert sich mit hoher Wahrsch. nicht mehr)
--   L3 = Ersatzwert, NICHT belastbar    -> ändert sich sehr wahrscheinlich noch, laut EDA
--                                          ausdrücklich NICHT für die Abrechnung verwenden
--
-- Ab jetzt entscheidet nicht mehr der Kalender, sondern die Datenqualität über die Freigabe:
-- Diese Spalte hält den aus dem EDA-Monatsbericht übernommenen Gesamtstatus des Zeitraums.
-- 'unbekannt' ist der Ausgangswert; Billing::finalize() prüft zusätzlich immer, ob noch
-- L3-Werte im Zeitraum liegen. freigabe_nach bleibt bestehen, dient aber nur noch als
-- informativer Richtwert, nicht mehr als harte Sperre.
ALTER TABLE billing_runs
    ADD COLUMN IF NOT EXISTS eda_status TEXT NOT NULL DEFAULT 'unbekannt';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'billing_runs_eda_status_check'
    ) THEN
        ALTER TABLE billing_runs
            ADD CONSTRAINT billing_runs_eda_status_check
            CHECK (eda_status IN ('unbekannt', 'vollstaendig', 'unvollstaendig'));
    END IF;
END$$;

COMMENT ON COLUMN billing_runs.eda_status IS
    'EDA-Datenqualität des Zeitraums laut Monatsbericht: unbekannt | vollstaendig | unvollstaendig. Steuert zusammen mit dem L3-Check die Freigabe (ersetzt das 60-Tage-Kalenderfenster).';
