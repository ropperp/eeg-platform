-- Migration 2026-07-14: Verträge, Dateien, Online-Anmeldung, Postfach, E-Mail-Konfiguration
-- Phase 1 aus "Claude-Code-Anweisung (konsolidiert): EEG-Plattform stromfueralle.at"
-- Baut auf dem bestehenden Schema auf (members, metering_points, communities, users) —
-- ersetzt NICHT die bestehenden contract_bezug_status/contract_einspeisung_status-Spalten
-- auf members; das Umstellen der Vertragserzeugung auf die neue contracts-Tabelle
-- passiert erst in einer späteren Phase (Vertragsgenerierung/E-Signatur).

-- ─────────────────────────────────────────
-- contracts
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS contracts (
    id                    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    member_id             UUID NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    community_id          UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    typ                   TEXT NOT NULL CHECK (typ IN ('bezug', 'einspeisung')),
    vertragsnummer        TEXT NOT NULL UNIQUE,
    vertragsbeginn        DATE NOT NULL,
    preis_snapshot        JSONB NOT NULL,
    status                TEXT NOT NULL DEFAULT 'erstellt'
                          CHECK (status IN ('erstellt', 'versendet', 'unterschrieben', 'unterschrieben_upload', 'storniert')),
    pdf_pfad              TEXT,
    pdf_sha256            TEXT,
    signed_pdf_pfad       TEXT,
    signed_pdf_sha256     TEXT,
    sign_token            TEXT UNIQUE,
    sign_token_expires_at TIMESTAMPTZ,
    signature_image       TEXT,
    signed_at             TIMESTAMPTZ,
    signer_ip             TEXT,
    signer_user_agent     TEXT,
    storno_grund          TEXT,
    storniert_am          TIMESTAMPTZ,
    erstellt_von          UUID REFERENCES users(id),
    created_at            TIMESTAMPTZ DEFAULT now()
);

-- Nur ein aktiver (nicht stornierter) Vertrag je Mitglied+Typ
CREATE UNIQUE INDEX IF NOT EXISTS uq_contracts_member_typ_active
    ON contracts (member_id, typ) WHERE status != 'storniert';

CREATE INDEX IF NOT EXISTS idx_contracts_community ON contracts(community_id);
CREATE INDEX IF NOT EXISTS idx_contracts_member ON contracts(member_id);

-- ─────────────────────────────────────────
-- member_files
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS member_files (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id    UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    member_id       UUID NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,
    pfad            TEXT NOT NULL,
    mime            TEXT,
    sha256          TEXT,
    hochgeladen_von UUID REFERENCES users(id),
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_member_files_member ON member_files(member_id);

-- ─────────────────────────────────────────
-- membership_applications (Online-Anmeldung /anmeldung)
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS membership_applications (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id        UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    status              TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),

    -- Stammdaten (Felder wie Papier-Beitrittserklärung)
    salutation          TEXT,
    titel               TEXT,
    first_name          TEXT NOT NULL,
    last_name           TEXT NOT NULL,
    geburtsdatum        DATE,
    address             TEXT,
    zip                 TEXT,
    city                TEXT,
    phone               TEXT,
    email               TEXT NOT NULL,
    stromlieferant      TEXT,

    -- Bezug
    bezug_gewuenscht            BOOLEAN NOT NULL DEFAULT false,
    bezug_jahresverbrauch_kwh   NUMERIC,
    bezug_zaehlpunkt            TEXT,

    -- Einspeisung
    einspeisung_gewuenscht          BOOLEAN NOT NULL DEFAULT false,
    einspeisung_kwp                 NUMERIC,
    einspeisung_geplante_kwh        NUMERIC,
    einspeisung_zaehlpunkt          TEXT,

    -- Weitere Informationen
    speicher_status     TEXT CHECK (speicher_status IN ('ja', 'nein', 'geplant')),
    speicher_kwh        NUMERIC,
    andere_eeg          BOOLEAN NOT NULL DEFAULT false,
    andere_eeg_name     TEXT,

    -- SEPA
    iban                TEXT,
    bic                 TEXT,
    kontoinhaber        TEXT,
    konto_adresse       TEXT,

    -- Rechtliche Zustimmungen (alle 6, siehe Beitrittserklärung.tex)
    zustimmung_mitgliedschaft       BOOLEAN NOT NULL DEFAULT false,
    zustimmung_vollmacht            BOOLEAN NOT NULL DEFAULT false,
    zustimmung_widerrufsfrist       BOOLEAN NOT NULL DEFAULT false,
    zustimmung_email_kommunikation  BOOLEAN NOT NULL DEFAULT false,
    zustimmung_datenschutz          BOOLEAN NOT NULL DEFAULT false,
    zustimmung_agb                  BOOLEAN NOT NULL DEFAULT false,

    signature_image     TEXT,
    signed_at           TIMESTAMPTZ,
    signer_ip           TEXT,

    pdf_pfad            TEXT,

    -- Freigabe-Workflow
    member_id           UUID REFERENCES members(id),   -- erst nach Freigabe gesetzt
    bearbeitet_von      UUID REFERENCES users(id),
    bearbeitet_am       TIMESTAMPTZ,
    ablehnungsgrund     TEXT,

    created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_membership_applications_community ON membership_applications(community_id);
CREATE INDEX IF NOT EXISTS idx_membership_applications_status ON membership_applications(community_id, status);

-- ─────────────────────────────────────────
-- notifications (internes Postfach)
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS notifications (
    id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id   UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    typ            TEXT NOT NULL,
    titel          TEXT NOT NULL,
    text           TEXT,
    referenz_typ   TEXT,
    referenz_id    UUID,
    status         TEXT NOT NULL DEFAULT 'offen' CHECK (status IN ('offen', 'erledigt')),
    created_at     TIMESTAMPTZ DEFAULT now(),
    erledigt_am    TIMESTAMPTZ,
    erledigt_von   UUID REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_notifications_community_status ON notifications(community_id, status);

-- ─────────────────────────────────────────
-- email_settings & email_templates
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS email_settings (
    id                 UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id       UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE UNIQUE,
    tenant_id          TEXT,
    client_id          TEXT,
    client_secret      TEXT,
    sender_email       TEXT,
    secret_ablaufdatum DATE,
    updated_at         TIMESTAMPTZ DEFAULT now(),
    updated_by         UUID REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS email_templates (
    id             UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id   UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    key            TEXT NOT NULL,   -- 'vertrag_versand' | 'rechnung_versand' | 'anmeldung_bestaetigung' | ...
    betreff        TEXT NOT NULL,
    body_html      TEXT NOT NULL,
    updated_at     TIMESTAMPTZ DEFAULT now(),
    updated_by     UUID REFERENCES users(id),
    UNIQUE (community_id, key)
);

-- ─────────────────────────────────────────
-- members: neue Felder
-- ─────────────────────────────────────────

ALTER TABLE members ADD COLUMN IF NOT EXISTS kundennummer INTEGER;
ALTER TABLE members ADD COLUMN IF NOT EXISTS mandatsreferenz TEXT UNIQUE;
ALTER TABLE members ADD COLUMN IF NOT EXISTS beitrittsdatum DATE;
ALTER TABLE members ADD COLUMN IF NOT EXISTS titel TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS geburtsdatum DATE;
ALTER TABLE members ADD COLUMN IF NOT EXISTS stromlieferant TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS speicher_status TEXT CHECK (speicher_status IN ('ja', 'nein', 'geplant'));
ALTER TABLE members ADD COLUMN IF NOT EXISTS speicher_kwh NUMERIC;
ALTER TABLE members ADD COLUMN IF NOT EXISTS andere_eeg BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE members ADD COLUMN IF NOT EXISTS andere_eeg_name TEXT;

-- Bestehende Mitglieder rückwirkend mit KdNr nummerieren (fortlaufend je Community,
-- nach Beitrittsdatum sortiert). ANNAHME: Start bei 1 je Community — falls bereits eine
-- andere Nummerierung (z.B. ab 10001) aus Papier/Excel existiert, bitte Startwert nachreichen.
WITH numbered AS (
    SELECT id, row_number() OVER (PARTITION BY community_id ORDER BY member_since, created_at) AS rn
    FROM members
    WHERE kundennummer IS NULL
)
UPDATE members m SET kundennummer = numbered.rn
FROM numbered WHERE m.id = numbered.id;

UPDATE members SET beitrittsdatum = member_since WHERE beitrittsdatum IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_members_community_kundennummer
    ON members (community_id, kundennummer) WHERE kundennummer IS NOT NULL;

-- ─────────────────────────────────────────
-- metering_points: neue Felder für Einspeiser-Vereinbarung
-- ─────────────────────────────────────────

ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS engpassleistung_kw NUMERIC;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS erzeugungsart TEXT DEFAULT 'Photovoltaik';
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS gst_nr TEXT;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS katastralgemeinde TEXT;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS anlagenadresse TEXT;

-- ─────────────────────────────────────────
-- Row-Level Security für die neuen Mandanten-Tabellen
--
-- WICHTIG: FORCE ROW LEVEL SECURITY ist zusätzlich zu ENABLE nötig, weil
-- Postgres RLS-Policies für den Tabellenbesitzer (hier: DB_USER, der auch
-- die App-Verbindung nutzt) sonst automatisch ignoriert — ENABLE allein
-- schützt in dieser Konstellation NICHT. Ohne FORCE wäre die Isolation
-- nur Theater, weil die App immer als Tabellenbesitzer verbindet.
-- ─────────────────────────────────────────

ALTER TABLE contracts ENABLE ROW LEVEL SECURITY;
ALTER TABLE contracts FORCE ROW LEVEL SECURITY;
ALTER TABLE member_files ENABLE ROW LEVEL SECURITY;
ALTER TABLE member_files FORCE ROW LEVEL SECURITY;
ALTER TABLE membership_applications ENABLE ROW LEVEL SECURITY;
ALTER TABLE membership_applications FORCE ROW LEVEL SECURITY;
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications FORCE ROW LEVEL SECURITY;
ALTER TABLE email_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE email_settings FORCE ROW LEVEL SECURITY;
ALTER TABLE email_templates ENABLE ROW LEVEL SECURITY;
ALTER TABLE email_templates FORCE ROW LEVEL SECURITY;

CREATE POLICY community_isolation ON contracts
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON member_files
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON membership_applications
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON notifications
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON email_settings
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON email_templates
    USING (community_id = current_setting('app.community_id', true)::uuid);
