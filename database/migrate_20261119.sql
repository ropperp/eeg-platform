-- Protokolle von Mitgliederversammlungen (Vereinsrecht: mindestens einmal jährlich).
-- Obmann/Manager erstellen das Protokoll in der Plattform, die Unterzeichner (Obmann, Schriftführer,
-- weitere) unterschreiben vor Ort am Gerät oder per Einmal-Link am eigenen Handy. Erst wenn alle
-- unterschrieben haben, wird das Protokoll gesperrt und als PDF abgelegt (status = 'final').
-- Alternativ kann ein fertiges PDF (z. B. eingescanntes Papierprotokoll) hochgeladen werden.
-- Idempotent (mehrfach ausführbar).

CREATE TABLE IF NOT EXISTS assemblies (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id        UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    title               TEXT NOT NULL DEFAULT 'Ordentliche Mitgliederversammlung',
    kind                TEXT NOT NULL DEFAULT 'ordentlich' CHECK (kind IN ('ordentlich', 'ausserordentlich')),
    held_on             DATE NOT NULL,          -- Datum der Versammlung (steht im Kopf, nicht das Druckdatum)
    time_from           TEXT,                   -- "18:00" (frei, nur Anzeige)
    time_to             TEXT,
    location            TEXT,
    attendees_count     INT CHECK (attendees_count IS NULL OR attendees_count >= 0),
    voting_count        INT CHECK (voting_count IS NULL OR voting_count >= 0),
    chair_name          TEXT,                   -- Vorsitz
    minutes_taker       TEXT,                   -- Protokollführung
    quorum              BOOLEAN,                -- beschlussfähig?
    notes               TEXT,
    source              TEXT NOT NULL DEFAULT 'editor' CHECK (source IN ('editor', 'upload')),
    status              TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'signing', 'final')),
    visible_to_members  BOOLEAN NOT NULL DEFAULT true,
    pdf_path            TEXT,                   -- unter /var/www/html/storage/assemblies
    created_by          UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    finalized_at        TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS assemblies_community_idx ON assemblies (community_id, held_on DESC);

CREATE TABLE IF NOT EXISTS assembly_items (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    assembly_id     UUID NOT NULL REFERENCES assemblies(id) ON DELETE CASCADE,
    community_id    UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    position        INT NOT NULL DEFAULT 0,
    title           TEXT NOT NULL,
    body            TEXT,                       -- Besprochenes (Klartext, Absätze durch Leerzeile)
    resolution      TEXT,                       -- Beschlusstext (optional)
    votes_yes       INT CHECK (votes_yes IS NULL OR votes_yes >= 0),
    votes_no        INT CHECK (votes_no IS NULL OR votes_no >= 0),
    votes_abstain   INT CHECK (votes_abstain IS NULL OR votes_abstain >= 0),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS assembly_items_assembly_idx ON assembly_items (assembly_id, position);

CREATE TABLE IF NOT EXISTS assembly_signers (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    assembly_id         UUID NOT NULL REFERENCES assemblies(id) ON DELETE CASCADE,
    community_id        UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    position            INT NOT NULL DEFAULT 0,
    name                TEXT NOT NULL,
    role_label          TEXT NOT NULL,          -- "Obmann", "Schriftführer", ...
    email               TEXT,                   -- nur für den Unterschrifts-Link
    sign_token_hash     TEXT UNIQUE,            -- SHA-256 des Einmal-Links (der Link selbst wird nie gespeichert)
    sign_token_expires  TIMESTAMPTZ,
    signature_image     TEXT,                   -- data:image/png;base64,...
    signed_at           TIMESTAMPTZ,
    signed_via          TEXT CHECK (signed_via IS NULL OR signed_via IN ('vor_ort', 'link')),
    signer_ip           TEXT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS assembly_signers_assembly_idx ON assembly_signers (assembly_id, position);

-- Mandantentrennung wie bei den übrigen Tabellen.
DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['assemblies', 'assembly_items', 'assembly_signers'] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
        EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
        EXECUTE format('DROP POLICY IF EXISTS community_isolation ON %I', t);
        EXECUTE format('CREATE POLICY community_isolation ON %I USING (community_id = current_setting(''app.community_id'', true)::uuid)', t);
    END LOOP;
END $$;

-- Der öffentliche Unterschrifts-Link kennt noch keine Community (app.community_id ist nicht gesetzt,
-- RLS liefert dann nichts). Diese Funktion löst NUR einen gültigen, nicht abgelaufenen und noch nicht
-- benutzten Token-Hash zur Community auf; danach setzt die Webapp app.community_id und arbeitet
-- normal mit RLS weiter. SECURITY DEFINER, damit die Suche über alle Communities geht.
CREATE OR REPLACE FUNCTION assembly_signer_by_token(p_hash TEXT)
    RETURNS TABLE (signer_id UUID, assembly_id UUID, community_id UUID)
    SECURITY DEFINER SET search_path = public
    LANGUAGE sql STABLE AS $$
    SELECT s.id, s.assembly_id, s.community_id
      FROM assembly_signers s
      JOIN assemblies a ON a.id = s.assembly_id
     WHERE s.sign_token_hash = p_hash
       AND s.sign_token_expires > now()
       AND s.signed_at IS NULL
       AND a.status = 'signing'
$$;
REVOKE ALL ON FUNCTION assembly_signer_by_token(TEXT) FROM PUBLIC;

-- Rechte für die Laufzeit-Rollen, falls es sie schon gibt (sonst setzt sie
-- scripts/db_runtime_role_setup.sh bzw. ALTER DEFAULT PRIVILEGES).
DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'eeg_app') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON assemblies, assembly_items, assembly_signers TO eeg_app;
        GRANT EXECUTE ON FUNCTION assembly_signer_by_token(TEXT) TO eeg_app;
    END IF;
END $$;
