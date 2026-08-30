-- Migration 2026-09-08: Fix für "column \"aktion\" of relation \"audit_log\" does not exist"
--
-- Patrick, per docker-compose-Logs: jeder logAudit()-Aufruf schlägt auf seinem Server mit
-- genau diesem Fehler fehl. logAudit() ist bewusst fehlertolerant (try/catch, siehe
-- webapp/public/index.php) -- die eigentliche Aktion (Upload, Speichern, ...) läuft dadurch
-- trotzdem normal durch, nur der Aktivitätslog-Eintrag geht seit jeher verloren.
--
-- database/migrate_20260716.sql legt audit_log per "CREATE TABLE IF NOT EXISTS" MIT der Spalte
-- aktion an -- auf Servern, wo die Tabelle schon VOR diesem Commit (z.B. manuell/anders) angelegt
-- wurde, greift "IF NOT EXISTS" nicht mehr und die Spalte fehlt bis heute. Idempotent: auf einem
-- Server, wo die Spalte bereits existiert (Normalfall bei den meisten Installationen), ist diese
-- Migration ein reines No-Op.
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS aktion TEXT;
UPDATE audit_log SET aktion = 'unbekannt (vor diesem Fix protokolliert)' WHERE aktion IS NULL;
ALTER TABLE audit_log ALTER COLUMN aktion SET NOT NULL;
