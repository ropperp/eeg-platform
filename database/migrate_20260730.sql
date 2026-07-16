-- Migration 2026-07-30: API-Keys für Mitglieder (Grundlage für die künftige Smart-Home-API).
--
-- Legt nur die Verwaltung an (Erstellen/Benennen/Ablaufdatum/Widerrufen im Portal) -- die
-- eigentlichen Live-Energiedaten-Endpoints (eigene Bezugs-/Einspeiseleistung, Community-
-- Autarkie in Echtzeit) kommen erst, sobald das Zählerdaten-Setup fürs Mitglied-Dashboard
-- produktionsreif ist (siehe "in Bearbeitung"-Platzhalter in member_dashboard.php). So können
-- Mitglieder ihre Zugänge aber schon vorbereiten, ohne dass bei der späteren Freischaltung
-- noch UI-Arbeit nötig ist.
--
-- key_hash speichert einen einfachen sha256-Hash statt bcrypt: API-Keys sind selbst schon
-- hochentropische Zufallstoken (32 Byte), die auf JEDEM API-Request geprüft werden müssten --
-- ein bewusst langsamer Passwort-Hash wäre hier nur unnötige Serverlast, ohne die Sicherheit
-- zu erhöhen (die Entropie steckt im Token, nicht im Hash).

CREATE TABLE IF NOT EXISTS member_api_keys (
    id           UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    member_id    UUID NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    name         TEXT NOT NULL,
    key_prefix   TEXT NOT NULL,
    key_hash     TEXT NOT NULL,
    created_at   TIMESTAMPTZ DEFAULT now(),
    expires_at   TIMESTAMPTZ,
    last_used_at TIMESTAMPTZ,
    revoked_at   TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_member_api_keys_member ON member_api_keys(member_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_member_api_keys_hash ON member_api_keys(key_hash);

ALTER TABLE member_api_keys ENABLE ROW LEVEL SECURITY;
ALTER TABLE member_api_keys FORCE ROW LEVEL SECURITY;
CREATE POLICY community_isolation ON member_api_keys
    USING (community_id = current_setting('app.community_id', true)::uuid);
