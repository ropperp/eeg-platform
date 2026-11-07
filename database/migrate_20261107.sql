-- Migration 2026-11-07: Rechtsdokumente/Beitrittserklärung (Zustimmungs-Versionen),
-- P1-Schlüssel je Zählpunkt, Zeitzonen-Korrektur sepa_signed_at.
--
-- Ausführen:
--   docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261107.sql
-- Idempotent (mehrfaches Ausführen schadet nicht).

-- ─────────────────────────────────────────
-- 1. Welche Fassung der sechs Zustimmungen wurde akzeptiert?
--    Texte: webapp/src/consent_texts.php (consentVersions()). Neue Beitrittserklärungen
--    speichern die zum Zeitpunkt der Unterschrift gültige Version (aktuell 'v2').
-- ─────────────────────────────────────────
ALTER TABLE membership_applications ADD COLUMN IF NOT EXISTS consent_version TEXT;
ALTER TABLE members                 ADD COLUMN IF NOT EXISTS consent_version TEXT;

-- Bestand: nur Versionen zuordnen -- niemand wird zu etwas aufgefordert, keine Mails.
-- Alle bisherigen Online-Anträge haben die damalige Online-Kurzfassung gesehen.
UPDATE membership_applications SET consent_version = 'v1-online' WHERE consent_version IS NULL;
-- Mitglieder, die über einen Online-Antrag aufgenommen wurden -> Online-Fassung ...
UPDATE members m SET consent_version = 'v1-online'
 WHERE m.consent_version IS NULL
   AND EXISTS (SELECT 1 FROM membership_applications a WHERE a.member_id = m.id);
-- ... alle übrigen sind per Papier-Beitrittserklärung beigetreten -> Papier-Fassung.
UPDATE members SET consent_version = 'v1-offline' WHERE consent_version IS NULL;

-- ─────────────────────────────────────────
-- 2. P1-Kundenschnittstelle: zählerspezifischer Schlüssel (32 Hex-Zeichen), den die EEG
--    aufgrund der Vollmacht beim Netzbetreiber anfordert oder den das Mitglied bereitstellt.
--    NUR verschlüsselt gespeichert (encryptSecret(), Schlüssel aus APP_SECRET der .env --
--    nie im Repo). Sichtbar/bearbeitbar nur für Obmann/Platform-Admin.
-- ─────────────────────────────────────────
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS p1_key_enc TEXT;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS p1_key_updated_at TIMESTAMPTZ;

-- Beim Austritt (members.status -> 'inactive') den Schlüssel automatisch löschen
-- (Datenschutzerklärung Punkt 9: Löschung mit Ende der Mitgliedschaft). Als Trigger statt nur
-- in der PHP-Route, damit es auch bei jedem anderen Weg greift (App-API, manuelle Korrektur).
-- SECURITY DEFINER: läuft als Besitzer, damit die Row-Level-Security der eingeschränkten
-- Laufzeitrolle (eeg_app) das Löschen nicht stillschweigend auf 0 Zeilen reduziert, falls
-- app.community_id einmal nicht gesetzt ist. Betrifft ausschließlich die Zählpunkte genau
-- dieses einen Mitglieds (NEW.id).
CREATE OR REPLACE FUNCTION clear_p1_keys_on_member_exit() RETURNS trigger
    SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.status = 'inactive' AND OLD.status IS DISTINCT FROM 'inactive' THEN
        UPDATE metering_points
           SET p1_key_enc = NULL, p1_key_updated_at = now()
         WHERE member_id = NEW.id AND p1_key_enc IS NOT NULL;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_clear_p1_keys_on_member_exit ON members;
CREATE TRIGGER trg_clear_p1_keys_on_member_exit
    AFTER UPDATE OF status ON members
    FOR EACH ROW EXECUTE FUNCTION clear_p1_keys_on_member_exit();

-- ─────────────────────────────────────────
-- 3. Zeitzonen-Korrektur: sepa_signed_at wurde bis 06.11.2026 als lokale Uhrzeit OHNE
--    Zeitzone geschrieben (PHP date('Y-m-d H:i:s')) und von Postgres als UTC gelesen -- im
--    PDF stand das SEPA-Mandat dadurch 1 h (Winterzeit) bzw. 2 h (Sommerzeit) zu spät.
--    signed_at (gleicher INSERT, per now()) ist korrekt; beide Unterschriften wurden im
--    selben Absenden geleistet. Nur Zeilen mit genau diesem Versatz (0,5-2,5 h) werden
--    korrigiert, alles andere bleibt unberührt.
-- ─────────────────────────────────────────
UPDATE membership_applications
   SET sepa_signed_at = signed_at
 WHERE sepa_signed_at IS NOT NULL
   AND signed_at IS NOT NULL
   AND abs(extract(epoch FROM (sepa_signed_at - signed_at))) BETWEEN 1800 AND 9000;
