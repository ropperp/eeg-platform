-- Migration 2026-08-30: app_sessions -- Refresh-Token-Speicher für die künftige Mitglieder-App
-- (native iOS-App, siehe docs/APP_API.md). Grundlage für /api/v1/login, /api/v1/token/refresh,
-- /api/v1/logout: Access-Token sind selbst-signiert (kein DB-Zugriff nötig, siehe
-- webapp/src/AppApiAuth.php), Refresh-Token sind hier als Hash gespeichert, widerrufbar und
-- werden bei jeder Erneuerung rotiert (Standard-Schutz gegen Diebstahl).
--
-- Bewusst OHNE Row-Level Security -- exakt derselbe Grund wie bei member_api_keys (siehe
-- migrate_20260731.sql): der Refresh-Token wird GLOBAL per Hash nachgeschlagen (Login/Refresh/
-- Logout), die Community ist zu diesem Zeitpunkt noch unbekannt -- current_setting(
-- 'app.community_id') wäre leer und eine RLS-Policy würde jede Zeile blockieren. Sicherheit
-- kommt hier aus der WHERE-Klausel (refresh_token_hash = ?), nicht aus RLS.
CREATE TABLE app_sessions (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    member_id           UUID NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    community_id        UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    refresh_token_hash  TEXT NOT NULL UNIQUE,
    device_label        TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at        TIMESTAMPTZ,
    expires_at          TIMESTAMPTZ NOT NULL,
    revoked_at          TIMESTAMPTZ
);
CREATE INDEX idx_app_sessions_member ON app_sessions(member_id);
