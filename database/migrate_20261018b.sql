-- Migration 2026-10-18 (b): Titel vorgestellt/nachgestellt getrennt.
--
-- Bisher gab es nur EIN Titel-Feld, das in Namen/Rechnungen immer VOR dem Namen ausgegeben
-- wurde -- dadurch stand auf der Rechnung fälschlich "BA Kerstin Huber" statt "Kerstin Huber, BA"
-- (BA ist ein NACHGESTELLTER akademischer Grad). Zwei getrennte Felder je Mitglied
-- (titel_vor/titel_nach) lösen das; siehe formatMemberName() in webapp/src/functions.php für die
-- einheitliche Ausgabe.
--
-- membership_applications bekommt dieselben zwei Felder, damit auch das Online-Beitritts-
-- formular (/anmeldung) beide Dropdowns anbieten kann (siehe beitreten_formular.php).
ALTER TABLE members ADD COLUMN IF NOT EXISTS titel_vor TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS titel_nach TEXT;
ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS titel_vor TEXT;
ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS titel_nach TEXT;

-- Bekannte, eindeutig NACHGESTELLTE akademische Grade (Bologna-Bakk./Bachelor/Master-Grade,
-- Doktorgrade aus dem anglo-amerikanischen Raum) -- alles andere im alten Feld gilt als
-- VORGESTELLT (Dr., Mag., Ing., Prof., ... -- die übliche österreichische Praxis). Case-
-- insensitiv wegen möglicher Schreibvarianten im Altbestand ("ba" statt "BA").
-- Idempotent über "titel_vor IS NULL AND titel_nach IS NULL": ein zweiter Lauf dieser Migration
-- überschreibt keine bereits (z.B. über das Formular) gepflegten Werte.
UPDATE members
SET titel_nach = trim(titel)
WHERE titel IS NOT NULL AND trim(titel) <> ''
  AND titel_vor IS NULL AND titel_nach IS NULL
  AND trim(titel) ~* '^(BA|BSc|BEd|MA|MSc|MEd|MBA|MAS|LL\.B\.|LL\.M\.|PhD|Bakk\.)$';

UPDATE members
SET titel_vor = trim(titel)
WHERE titel IS NOT NULL AND trim(titel) <> ''
  AND titel_vor IS NULL AND titel_nach IS NULL;

UPDATE membership_applications
SET titel_nach = trim(titel)
WHERE titel IS NOT NULL AND trim(titel) <> ''
  AND titel_vor IS NULL AND titel_nach IS NULL
  AND trim(titel) ~* '^(BA|BSc|BEd|MA|MSc|MEd|MBA|MAS|LL\.B\.|LL\.M\.|PhD|Bakk\.)$';

UPDATE membership_applications
SET titel_vor = trim(titel)
WHERE titel IS NOT NULL AND trim(titel) <> ''
  AND titel_vor IS NULL AND titel_nach IS NULL;

-- Explizite Sicherstellung für Kerstin Huber (KdNr 10013, Patrick 18.10.2026: "muss 'BA' als
-- NACHGESTELLTER Titel gespeichert sein") -- unabhängig vom generischen Muster oben, falls ihr
-- Altbestand z.B. mit abweichender Schreibweise ("B.A." o.ä.) nicht gematcht hätte. Idempotent:
-- betrifft nur sie (kundennummer ist plattformweit eindeutig, siehe migrate_20260723.sql), wirkt
-- nur, wenn titel_nach noch nicht exakt "BA" ist bzw. titel_vor fälschlich "BA" enthält.
UPDATE members SET titel_nach = 'BA' WHERE kundennummer = 10013 AND COALESCE(titel_nach, '') <> 'BA';
UPDATE members SET titel_vor = NULL WHERE kundennummer = 10013 AND titel_vor = 'BA';
