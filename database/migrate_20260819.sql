-- EDA-Import-Zählpunkt-Abgleich (docs/ESB_IDEEN.md Punkt 3 / Platform-Task #70): Zählpunkte,
-- die im EDA-Export auftauchen, aber noch keinem Mitglied zugeordnet sind, werden automatisch
-- angelegt statt nur übersprungen -- member_id muss dafür NULL sein dürfen. Der Obmann ordnet
-- sie danach manuell einem Mitglied zu (siehe /portal/metering-points/unassigned). Bis dahin
-- bleiben sie inaktiv (active=false) und nehmen an keiner Abrechnung teil (Billing::
-- generateDrafts() joint über member_id, ein NULL matcht dort ohnehin nie).
ALTER TABLE metering_points ALTER COLUMN member_id DROP NOT NULL;

-- Persistiert je Import, welche Zählpunkte automatisch angelegt wurden (Zählpunktnummer +
-- Zählernummer) -- für die Nachvollziehbarkeit, zusätzlich zum Audit-Log-Eintrag je Zählpunkt.
ALTER TABLE eda_imports ADD COLUMN IF NOT EXISTS neu_angelegt JSONB DEFAULT '[]';
