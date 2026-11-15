-- 17.11.2026: Passkey-Anmeldung (WebAuthn). Pro Benutzer beliebig viele Passkeys (Handy, Laptop,
-- Sicherheitsschlüssel). Gespeichert wird nur der ÖFFENTLICHE Schlüssel -- der private bleibt im
-- Gerät des Benutzers und verlässt es nie, ein Datenbank-Diebstahl liefert also keine Zugänge.
-- credential_id und public_key_pem als Text (Base64URL bzw. PEM), damit keine bytea-Sonderfälle
-- im Treiber nötig sind. Idempotent.
CREATE TABLE IF NOT EXISTS user_passkeys (
    id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id        UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    credential_id  TEXT NOT NULL UNIQUE,
    public_key_pem TEXT NOT NULL,
    sign_count     BIGINT NOT NULL DEFAULT 0,
    label          TEXT NOT NULL DEFAULT 'Passkey',
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at   TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS user_passkeys_user_idx ON user_passkeys (user_id);
