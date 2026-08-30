-- Migration 2026-09-09: audit_log fehlten neben "aktion" (siehe migrate_20260908.sql) auch noch
-- "entity_typ", "beschreibung" und "ist_fehler".
--
-- Patrick, nach dem Deploy von migrate_20260908.sql: derselbe Server zeigte per \d audit_log eine
-- KOMPLETT andere, ältere Tabellenstruktur (action/entity_type/details/actor_label/ip/aenderungen
-- statt aktion/entity_typ/beschreibung/ist_fehler) -- vermutlich aus einer frühen, später verworfenen
-- Schema-Fassung, die nie durch migrate_20260716.sql (CREATE TABLE IF NOT EXISTS, greift bei
-- bereits bestehender Tabelle nicht) korrigiert wurde. migrate_20260908.sql hat nur "aktion"
-- ergänzt, weil INSERT-Statements ihre Ziel-Spaltenliste von links nach rechts prüfen und beim
-- ERSTEN unbekannten Namen abbrechen ("aktion" steht als drittes in der Liste, noch vor
-- entity_typ/beschreibung/ist_fehler) -- die dahinterliegenden fehlenden Spalten kamen deshalb nie
-- zum Vorschein. logAudit()/logAuditDiff() (webapp/public/index.php) brauchen aber alle vier.
-- Idempotent: auf einem Server, wo die Spalten schon existieren (Normalfall), reines No-Op.
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS entity_typ TEXT;

ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS beschreibung TEXT;
UPDATE audit_log SET beschreibung = 'unbekannt (vor diesem Fix protokolliert)' WHERE beschreibung IS NULL;
ALTER TABLE audit_log ALTER COLUMN beschreibung SET NOT NULL;

ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS ist_fehler BOOLEAN;
UPDATE audit_log SET ist_fehler = false WHERE ist_fehler IS NULL;
ALTER TABLE audit_log ALTER COLUMN ist_fehler SET DEFAULT false;
ALTER TABLE audit_log ALTER COLUMN ist_fehler SET NOT NULL;
