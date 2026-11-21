-- Migration 2026-11-21 (d): Statuten-Generator (Einladungen per Einmal-Link, Entwürfe, PDFs).
--
-- Ausführen:
--   docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261121d.sql
-- Idempotent (mehrfaches Ausführen schadet nicht).
--
-- Plattformweit, NICHT mandantengebunden (der Interessent hat noch keine Gemeinschaft) -- daher
-- ohne RLS, wie platform_settings. Zugriff nur über Admin-Routen (isPlatformAdmin) bzw. einen
-- gültigen Token; die Webapp filtert im Repository. Fachliche Beschreibung: docs/STATUTEN_GENERATOR.md,
-- Entscheidung: docs/adr/0008-statuten-generator.md.

CREATE TABLE IF NOT EXISTS statute_invites (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    token_hash  TEXT NOT NULL UNIQUE,              -- SHA-256 (hex) des Links; der Link selbst wird nie gespeichert
    label       TEXT NOT NULL,                     -- Bezeichnung des Interessenten (frei, intern)
    prefill     JSONB NOT NULL DEFAULT '{}'::jsonb,
    status      TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'generated', 'revoked')),
    -- "abgelaufen" wird NICHT gespeichert, sondern aus expires_at berechnet. Nach dem Erzeugen
    -- setzt die Webapp expires_at = generated + 90 Tage (Download-Frist).
    expires_at  TIMESTAMPTZ NOT NULL,
    created_by  UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    used_at     TIMESTAMPTZ,                       -- erste Statuten-Erzeugung über den Link
    revoked_at  TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS statute_invites_created_idx ON statute_invites (created_at DESC);

CREATE TABLE IF NOT EXISTS statute_drafts (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    invite_id     UUID NOT NULL REFERENCES statute_invites(id) ON DELETE CASCADE,
    answers       JSONB NOT NULL DEFAULT '{}'::jsonb,
    -- 0 = Arbeitsstand (Zwischenspeichern), 1..n = erzeugte, eingefrorene Fassungen
    version       INTEGER NOT NULL DEFAULT 0 CHECK (version >= 0),
    pdf_path      TEXT,                            -- Ordner unter storage/statuten/ (statuten/anzeige/protokoll.pdf)
    -- Vorbereitet für später: aus einem Entwurf eine Gemeinschaft (Mandant) anlegen. Noch ungenutzt.
    community_id  UUID REFERENCES communities(id) ON DELETE SET NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    generated_at  TIMESTAMPTZ,
    generated_by  UUID REFERENCES users(id) ON DELETE SET NULL,  -- NULL = über den Link erzeugt
    -- Freigabe durch den Admin nach fachlicher Prüfung: PDFs werden ohne Kopfzeile
    -- "Entwurf – keine Rechtsberatung" neu erzeugt. NULL = Entwurf.
    released_at   TIMESTAMPTZ,
    released_by   UUID REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE (invite_id, version)
);
CREATE INDEX IF NOT EXISTS statute_drafts_generated_idx ON statute_drafts (generated_at);
