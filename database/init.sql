-- EEG SaaS-Plattform — Datenbankschema
-- PostgreSQL 16 + TimescaleDB
-- Multi-Tenant: jede Tabelle hat community_id
-- Row-Level Security isoliert Mandanten voneinander

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS timescaledb;

-- ─────────────────────────────────────────
-- KERN-TABELLEN
-- ─────────────────────────────────────────

CREATE TABLE communities (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name            TEXT NOT NULL,
    slug            TEXT NOT NULL UNIQUE,          -- URL-freundlicher Name, z.B. "strompool-feldkirchen"
    marktpartner_id TEXT,                          -- RC108175
    zvr_number      TEXT,                          -- 1778816746
    address         TEXT,
    logo_path       TEXT,
    dashboard_url   TEXT,                          -- Vertrags-Verweis "Mitgliederportal" (frei konfigurierbar je EEG)
    iban            TEXT,
    bic             TEXT,
    payment_days    INTEGER DEFAULT 14,
    active          BOOLEAN DEFAULT true,
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE users (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email           TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    first_name      TEXT NOT NULL,
    last_name       TEXT NOT NULL,
    active          BOOLEAN DEFAULT true,
    created_at      TIMESTAMPTZ DEFAULT now(),
    last_login_at   TIMESTAMPTZ,
    reset_token     TEXT,
    reset_token_expires TIMESTAMPTZ
);

-- Rollen: platform_admin = Patrick/Fabian/Alexander (über alle EEGs)
--         manager = Obmann/Kassier einer EEG
--         member = normales Mitglied
CREATE TABLE user_roles (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id    UUID REFERENCES communities(id) ON DELETE CASCADE,
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role            TEXT NOT NULL CHECK (role IN ('platform_admin', 'manager', 'member')),
    created_at      TIMESTAMPTZ DEFAULT now(),
    UNIQUE (community_id, user_id, role)
);

-- Singleton (id=1): Zugangsdaten für den E-Mail-Versand über Microsoft Graph
-- (Tenant-ID/Client-ID/Client-Secret/Absenderadresse), nur über Platform-Admin gepflegt.
-- Werte dürfen NIE im Repo landen (siehe CLAUDE.md).
CREATE TABLE platform_mail_config (
    id             INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    tenant_id      TEXT,
    client_id      TEXT,
    client_secret  TEXT,
    sender_address TEXT,
    updated_at     TIMESTAMPTZ DEFAULT now()
);
INSERT INTO platform_mail_config (id) VALUES (1);

CREATE TABLE members (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id    UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    user_id         UUID REFERENCES users(id),
    -- Stammdaten aus Beitrittserklärung
    salutation      TEXT,
    first_name      TEXT NOT NULL,
    last_name       TEXT NOT NULL,
    company_name    TEXT,                          -- wenn Firmenmitglied
    address         TEXT NOT NULL,
    zip             TEXT NOT NULL,
    city            TEXT NOT NULL,
    email           TEXT NOT NULL,
    phone           TEXT,
    -- Rechnungsangaben
    invoice_name    TEXT,                          -- abweichender Rechnungsname
    invoice_uid     TEXT,                          -- UID wenn Firma
    -- Bankverbindung
    member_iban     TEXT,
    member_bic      TEXT,
    -- Mitgliedschaft
    member_since    DATE NOT NULL DEFAULT CURRENT_DATE,
    member_until    DATE,
    status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('pending', 'active', 'inactive')),
    -- Vertragsstatus
    contract_bezug_status           TEXT NOT NULL DEFAULT 'none' CHECK (contract_bezug_status IN ('none','created','signed')),
    contract_bezug_generated_at     TIMESTAMPTZ,
    contract_einspeisung_status     TEXT NOT NULL DEFAULT 'none' CHECK (contract_einspeisung_status IN ('none','created','signed')),
    contract_einspeisung_generated_at TIMESTAMPTZ,
    photo_path      TEXT,                          -- eigenes Profilbild; NULL = Default-Avatar nach Anrede
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE metering_points (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id    UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    member_id       UUID NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    zaehlpunkt_nr   TEXT NOT NULL,                 -- AT0070000956010000000000000689442
    meter_code      TEXT,                          -- MeterCode aus EDA-XLSX
    type            TEXT NOT NULL CHECK (type IN ('consumer', 'producer', 'prosumer')),
    active          BOOLEAN DEFAULT true,
    registered_at   DATE,
    created_at      TIMESTAMPTZ DEFAULT now(),
    UNIQUE (community_id, zaehlpunkt_nr)
);

-- ─────────────────────────────────────────
-- ZEITREIHENDATEN (TimescaleDB Hypertables)
-- STRIKT GETRENNT: ESP ≠ EDA
-- ─────────────────────────────────────────

-- ESP32-Echtzeit-Daten (nur Visualisierung, KEINE Abrechnungsgrundlage)
CREATE TABLE esp_measurements (
    time            TIMESTAMPTZ NOT NULL,
    community_id    UUID NOT NULL,
    metering_point_id UUID NOT NULL REFERENCES metering_points(id) ON DELETE CASCADE,
    power_bezug_w   INTEGER DEFAULT 0,            -- Momentanleistung Bezug (W)
    power_einspeisung_w INTEGER DEFAULT 0,        -- Momentanleistung Einspeisung (W)
    energy_bezug_wh BIGINT DEFAULT 0,             -- Zählerstand Bezug (Wh)
    energy_einspeisung_wh BIGINT DEFAULT 0,       -- Zählerstand Einspeisung (Wh)
    znr             TEXT                          -- Zählernummer vom Gerät
);

SELECT create_hypertable('esp_measurements', 'time', chunk_time_interval => INTERVAL '1 day');
CREATE INDEX ON esp_measurements (community_id, metering_point_id, time DESC);

-- EDA-Abrechnungsdaten (15-Min-Werte vom Netzbetreiber — einzige rechtliche Grundlage)
CREATE TABLE eda_measurements (
    time            TIMESTAMPTZ NOT NULL,
    community_id    UUID NOT NULL,
    metering_point_id UUID NOT NULL REFERENCES metering_points(id) ON DELETE CASCADE,
    meter_code      TEXT NOT NULL,
    kwh_erzeugung   NUMERIC(12,4),                -- Gesamterzeugung lt. Messung
    kwh_teilnahme   NUMERIC(12,4),                -- Anteil gemeinschaftliche Erzeugung (Teilnahmefaktor)
    kwh_ueberschuss NUMERIC(12,4),                -- Überschuss
    kwh_restueberschuss NUMERIC(12,4),
    quality         TEXT CHECK (quality IN ('L1', 'L2', 'L3')),  -- vorläufig → korrigiert → final
    completeness    TEXT CHECK (completeness IN ('COMPLETE', 'INCOMPLETE'))
);

SELECT create_hypertable('eda_measurements', 'time', chunk_time_interval => INTERVAL '7 days');
CREATE INDEX ON eda_measurements (community_id, metering_point_id, time DESC);

CREATE TABLE eda_imports (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id    UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    imported_by     UUID REFERENCES users(id),
    filename        TEXT NOT NULL,
    period_from     TIMESTAMPTZ NOT NULL,
    period_to       TIMESTAMPTZ NOT NULL,
    records_imported INTEGER DEFAULT 0,
    warnings        JSONB DEFAULT '[]',
    status          TEXT NOT NULL DEFAULT 'ok' CHECK (status IN ('ok', 'warning', 'error')),
    imported_at     TIMESTAMPTZ DEFAULT now()
);

-- ─────────────────────────────────────────
-- KONFIGURATION (historisiert mit valid_from)
-- NIEMALS HARDCODEN
-- ─────────────────────────────────────────

CREATE TABLE tariff_config (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id    UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    valid_from      DATE NOT NULL,
    bezug_ct_kwh    NUMERIC(6,4) NOT NULL,         -- Bezugstarif in ct/kWh
    einspeisung_ct_kwh NUMERIC(6,4) NOT NULL,      -- Einspeisevergütung in ct/kWh
    mitgliedsbeitrag_eur NUMERIC(8,2) NOT NULL,    -- Jahresbeitrag in EUR
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE tax_config (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id    UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    valid_from      DATE NOT NULL,
    -- 'kleinunternehmer' = § 6 Abs 1 Z 27 UStG, kein USt-Ausweis
    -- 'standard' = normaler USt-Ausweis
    tax_model       TEXT NOT NULL CHECK (tax_model IN ('kleinunternehmer', 'standard')),
    tax_rate_percent NUMERIC(5,2),                 -- NULL wenn Kleinunternehmer, sonst z.B. 20.00
    uid_number      TEXT,                          -- UID der EEG (wenn USt-pflichtig)
    created_at      TIMESTAMPTZ DEFAULT now()
);

-- ─────────────────────────────────────────
-- ABRECHNUNG
-- ─────────────────────────────────────────

CREATE TABLE billing_runs (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id    UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    quartal         TEXT NOT NULL,                 -- z.B. '2026-Q2'
    period_from     DATE NOT NULL,
    period_to       DATE NOT NULL,
    -- 60-Tage-Korrekturfenster: freigabe erst NACH diesem Datum möglich
    freigabe_nach   DATE NOT NULL,
    status          TEXT NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'ready', 'released', 'done')),
    completeness_check JSONB,                      -- Ergebnis der Vollständigkeitsprüfung
    released_by     UUID REFERENCES users(id),
    released_at     TIMESTAMPTZ,
    created_at      TIMESTAMPTZ DEFAULT now(),
    UNIQUE (community_id, quartal)
);

CREATE TABLE invoices (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    billing_run_id  UUID NOT NULL REFERENCES billing_runs(id),
    community_id    UUID NOT NULL,
    member_id       UUID NOT NULL REFERENCES members(id),
    rechnungsnummer TEXT NOT NULL UNIQUE,          -- RC108175-2026-Q2-001
    saldo_eur       NUMERIC(10,2) NOT NULL,        -- positiv = Mitglied zahlt, negativ = EEG zahlt
    pdf_path        TEXT,
    sent_at         TIMESTAMPTZ,
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE invoice_items (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    invoice_id      UUID NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    type            TEXT NOT NULL CHECK (type IN ('bezug', 'einspeisung', 'mitgliedsbeitrag')),
    kwh             NUMERIC(12,4),
    rate_ct_kwh     NUMERIC(6,4),
    months          NUMERIC(4,2),
    amount_eur      NUMERIC(10,2) NOT NULL         -- negativ = Gutschrift
);

-- ─────────────────────────────────────────
-- ROW-LEVEL SECURITY
-- Mandanten können sich nie gegenseitig sehen
-- ─────────────────────────────────────────

ALTER TABLE members ENABLE ROW LEVEL SECURITY;
ALTER TABLE metering_points ENABLE ROW LEVEL SECURITY;
ALTER TABLE esp_measurements ENABLE ROW LEVEL SECURITY;
ALTER TABLE eda_measurements ENABLE ROW LEVEL SECURITY;
ALTER TABLE eda_imports ENABLE ROW LEVEL SECURITY;
ALTER TABLE tariff_config ENABLE ROW LEVEL SECURITY;
ALTER TABLE tax_config ENABLE ROW LEVEL SECURITY;
ALTER TABLE billing_runs ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoices ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoice_items ENABLE ROW LEVEL SECURITY;

-- Policy: nur Zugriff auf eigene community_id
-- Die App setzt vor jeder Abfrage: SET LOCAL app.community_id = '...';
CREATE POLICY community_isolation ON members
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON metering_points
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON esp_measurements
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON eda_measurements
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON eda_imports
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON tariff_config
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON tax_config
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON billing_runs
    USING (community_id = current_setting('app.community_id', true)::uuid);
CREATE POLICY community_isolation ON invoices
    USING (community_id = current_setting('app.community_id', true)::uuid);

-- ─────────────────────────────────────────
-- PILOT-DATEN: Strompool Feldkirchen Süd-West
-- ─────────────────────────────────────────

INSERT INTO communities (id, name, slug, marktpartner_id, zvr_number, address)
VALUES (
    'a0000000-0000-0000-0000-000000000001',
    'EEG Strompool Feldkirchen Süd-West',
    'strompool-feldkirchen',
    'RC108175',
    '1778816746',
    'Eichenweg 2, 9560 St. Nikolai'
);

-- Pilot-Tarif (Stand Juni 2026)
INSERT INTO tariff_config (community_id, valid_from, bezug_ct_kwh, einspeisung_ct_kwh, mitgliedsbeitrag_eur)
VALUES ('a0000000-0000-0000-0000-000000000001', '2026-05-26', 12.00, 8.00, 24.00);

-- Steuerkonfiguration: Kleinunternehmer (§ 6 Abs 1 Z 27 UStG)
INSERT INTO tax_config (community_id, valid_from, tax_model)
VALUES ('a0000000-0000-0000-0000-000000000001', '2026-05-26', 'kleinunternehmer');

-- Platform-Admin: Patrick Ropper
-- Passwort wird beim ersten Start gesetzt (siehe SETUP.md)
INSERT INTO users (id, email, password_hash, first_name, last_name)
VALUES (
    'b0000000-0000-0000-0000-000000000001',
    'patrick.ropper@gmail.com',
    '$2y$12$PLACEHOLDER_CHANGE_ON_FIRST_LOGIN',   -- wird beim Setup ersetzt
    'Patrick',
    'Ropper'
);

INSERT INTO user_roles (community_id, user_id, role)
VALUES (
    'a0000000-0000-0000-0000-000000000001',
    'b0000000-0000-0000-0000-000000000001',
    'platform_admin'
);
INSERT INTO user_roles (community_id, user_id, role)
VALUES (
    'a0000000-0000-0000-0000-000000000001',
    'b0000000-0000-0000-0000-000000000001',
    'manager'
);
