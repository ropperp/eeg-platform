-- Anmeldedienst Keycloak, Schritt 2 (docs/adr/0007-keycloak.md): automatischer Abgleich Portal -> Keycloak.
--
-- Jede Änderung an einem Login-Konto (neu, E-Mail/Name, Sperre, Passwort, 2FA, Löschen, Platform-Admin)
-- landet per Trigger in keycloak_sync_queue. Die Webapp arbeitet die Warteschlange nach jeder Anfrage ab
-- (App\Infrastructure\Auth\KeycloakSync), ein Cron holt Liegengebliebenes nach. Trigger statt Aufrufen an
-- den rund 15 Stellen im Code: so wird keine Stelle vergessen, auch nicht in Skripten.
--
-- keycloak_state: was zuletzt an Keycloak übertragen wurde (Fingerabdrücke), damit nur Änderungen gehen.
-- Idempotent (mehrfach ausführbar).

ALTER TABLE users ADD COLUMN IF NOT EXISTS keycloak_state JSONB;

CREATE TABLE IF NOT EXISTS keycloak_sync_queue (
    id          BIGSERIAL PRIMARY KEY,
    user_id     UUID,                       -- NULL bei 'delete' (Benutzer gibt es nicht mehr)
    keycloak_id UUID,                       -- nur bei 'delete'
    op          TEXT NOT NULL CHECK (op IN ('upsert', 'delete')),
    attempts    INT  NOT NULL DEFAULT 0,
    last_error  TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS keycloak_sync_queue_created_idx ON keycloak_sync_queue (created_at);

CREATE OR REPLACE FUNCTION keycloak_queue_user() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.keycloak_id IS NOT NULL THEN
            INSERT INTO keycloak_sync_queue (keycloak_id, op) VALUES (OLD.keycloak_id, 'delete');
        END IF;
        RETURN OLD;
    END IF;
    -- Neue Konten immer; Änderungen nur, wenn das Konto schon in Keycloak ist (ohne eingerichtetes
    -- Keycloak wächst die Warteschlange so nur um neue Konten, nicht um jede Änderung).
    IF TG_OP = 'INSERT' OR NEW.keycloak_id IS NOT NULL THEN
        INSERT INTO keycloak_sync_queue (user_id, op) VALUES (NEW.id, 'upsert');
    END IF;
    RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS users_keycloak_sync ON users;
CREATE TRIGGER users_keycloak_sync
    AFTER INSERT OR DELETE OR UPDATE OF email, first_name, last_name, active, password_hash, totp_enabled, totp_secret
    ON users FOR EACH ROW EXECUTE FUNCTION keycloak_queue_user();

CREATE OR REPLACE FUNCTION keycloak_queue_role() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r user_roles%ROWTYPE;
BEGIN
    r := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    IF r.role = 'platform_admin' AND EXISTS (SELECT 1 FROM users WHERE id = r.user_id AND keycloak_id IS NOT NULL) THEN
        INSERT INTO keycloak_sync_queue (user_id, op) VALUES (r.user_id, 'upsert');
    END IF;
    RETURN r;
END $$;

DROP TRIGGER IF EXISTS user_roles_keycloak_sync ON user_roles;
CREATE TRIGGER user_roles_keycloak_sync
    AFTER INSERT OR DELETE ON user_roles FOR EACH ROW EXECUTE FUNCTION keycloak_queue_role();

DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'eeg_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON keycloak_sync_queue TO eeg_app;
        GRANT USAGE, SELECT ON SEQUENCE keycloak_sync_queue_id_seq TO eeg_app;
    END IF;
END $$;
