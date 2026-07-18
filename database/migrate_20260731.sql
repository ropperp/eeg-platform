-- Migration 2026-07-31: member_api_keys braucht KEINE Community-RLS.
--
-- migrate_20260730.sql hat member_api_keys mit FORCE ROW LEVEL SECURITY + einer
-- community_id-Policy angelegt, wie die übrigen mandantengebundenen Tabellen. Das übersieht
-- aber den eigentlichen Zweck der Tabelle: Die künftige API-Authentifizierung (siehe
-- /api/v1/me) muss einen Key GLOBAL per Hash nachschlagen -- die Community ist zu diesem
-- Zeitpunkt ja noch unbekannt, das Suchergebnis verrät sie erst. current_setting(
-- 'app.community_id') ist vor dieser Suche naturgemäß leer, wodurch die Policy JEDE Zeile
-- blockiert hätte -- der Login-Mechanismus der eigenen API wäre nie funktionsfähig gewesen.
--
-- Sicherheit kommt hier stattdessen wie bei users/user_roles (ebenfalls ohne RLS) aus der
-- WHERE-Klausel der Anwendung selbst (member_id = ? beim Auflisten/Widerrufen im Portal,
-- key_hash = ? bei der Authentifizierung), nicht aus RLS.

DROP POLICY IF EXISTS community_isolation ON member_api_keys;
ALTER TABLE member_api_keys NO FORCE ROW LEVEL SECURITY;
ALTER TABLE member_api_keys DISABLE ROW LEVEL SECURITY;
