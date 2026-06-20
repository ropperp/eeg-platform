-- EEG-Plattform — Datenbankschema (nur Struktur, keine Daten)
-- Generiert aus: database/init.sql
-- Aktualisieren: make schema (pg_dump --schema-only aus laufender DB)
--
-- PostgreSQL 16 + TimescaleDB
-- Multi-Tenant: community_id in allen mandantenspezifischen Tabellen
-- Row-Level Security: SET LOCAL app.community_id = '...' vor jeder Abfrage

-- ─────────────────────────────────────────
-- ERWEITERUNGEN
-- ─────────────────────────────────────────

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS timescaledb;

-- ─────────────────────────────────────────
-- KERN-TABELLEN
-- ─────────────────────────────────────────

CREATE TABLE communities (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name            TEXT NOT NULL,
    slug            TEXT NOT NULL UNIQUE,
    marktpartner_id TEXT,
    zvr_number      TEXT,
    address         TEXT,
    logo_path       TEXT,
    iban            TEXT,
    bic             TEXT,
    payment_days    INTEGER DEFAULT 14,
    active          BOOLEAN DEFAULT true,
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE users (
    id                   UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email                TEXT NOT NULL UNIQUE,
    password_hash        TEXT NOT NULL,
    first_name           TEXT NOT NULL,
    last_name            TEXT NOT NULL,
    active               BOOLEAN DEFAULT true,
    created_at           TIMESTAMPTZ DEFAULT now(),
    last_login_at        TIMESTAMPTZ,
    reset_token          TEXT,
    reset_token_expires  TIMESTAMPTZ
);

-- Rollen: platform_admin (community_id = NULL), manager, member
CREATE TABLE user_roles (
    id           UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id UUID REFERENCES communities(id) ON DELETE CASCADE,
    user_id      UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role         TEXT NOT NULL CHECK (role IN ('platform_admin', 'manager', 'member')),
    created_at   TIMESTAMPTZ DEFAULT now(),
    UNIQUE (community_id, user_id, role)
);

CREATE TABLE members (
    id                               UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id                     UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    user_id                          UUID REFERENCES users(id),
    salutation                       TEXT,
    first_name                       TEXT NOT NULL,
    last_name                        TEXT NOT NULL,
    company_name                     TEXT,
    address                          TEXT NOT NULL,
    zip                              TEXT NOT NULL,
    city                             TEXT NOT NULL,
    email                            TEXT NOT NULL,
    phone                            TEXT,
    invoice_name                     TEXT,
    invoice_uid                      TEXT,
    member_iban                      TEXT,
    member_bic                       TEXT,
    member_since                     DATE NOT NULL DEFAULT CURRENT_DATE,
    member_until                     DATE,
    status                           TEXT NOT NULL DEFAULT 'active'
                                     CHECK (status IN ('pending', 'active', 'inactive')),
    contract_bezug_status            TEXT NOT NULL DEFAULT 'none'
                                     CHECK (contract_bezug_status IN ('none', 'created', 'signed')),
    contract_bezug_generated_at      TIMESTAMPTZ,
    contract_einspeisung_status      TEXT NOT NULL DEFAULT 'none'
                                     CHECK (contract_einspeisung_status IN ('none', 'created', 'signed')),
    contract_einspeisung_generated_at TIMESTAMPTZ,
    created_at                       TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE metering_points (
    id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id  UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    member_id     UUID NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    zaehlpunkt_nr TEXT NOT NULL,
    meter_code    TEXT,
    type          TEXT NOT NULL CHECK (type IN ('consumer', 'producer', 'prosumer')),
    active        BOOLEAN DEFAULT true,
    registered_at DATE,
    created_at    TIMESTAMPTZ DEFAULT now(),
    UNIQUE (community_id, zaehlpunkt_nr)
);

-- ─────────────────────────────────────────
-- ZEITREIHENDATEN (TimescaleDB Hypertables)
-- ─────────────────────────────────────────

-- ESP32-Echtzeit (Visualisierung, NICHT Abrechnungsgrundlage)
CREATE TABLE esp_measurements (
    time                  TIMESTAMPTZ NOT NULL,
    community_id          UUID NOT NULL,
    metering_point_id     UUID NOT NULL REFERENCES metering_points(id) ON DELETE CASCADE,
    power_bezug_w         INTEGER DEFAULT 0,
    power_einspeisung_w   INTEGER DEFAULT 0,
    energy_bezug_wh       BIGINT DEFAULT 0,
    energy_einspeisung_wh BIGINT DEFAULT 0,
    znr                   TEXT
);

SELECT create_hypertable('esp_measurements', 'time', chunk_time_interval => INTERVAL '1 day');
CREATE INDEX ON esp_measurements (community_id, metering_point_id, time DESC);

-- EDA-15-Min-Werte (einzige rechtliche Abrechnungsgrundlage)
CREATE TABLE eda_measurements (
    time              TIMESTAMPTZ NOT NULL,
    community_id      UUID NOT NULL,
    metering_point_id UUID NOT NULL REFERENCES metering_points(id) ON DELETE CASCADE,
    meter_code        TEXT NOT NULL,
    kwh_erzeugung     NUMERIC(12,4),
    kwh_teilnahme     NUMERIC(12,4),
    kwh_ueberschuss   NUMERIC(12,4),
    kwh_restueberschuss NUMERIC(12,4),
    quality           TEXT CHECK (quality IN ('L1', 'L2', 'L3')),
    completeness      TEXT CHECK (completeness IN ('COMPLETE', 'INCOMPLETE'))
);

SELECT create_hypertable('eda_measurements', 'time', chunk_time_interval => INTERVAL '7 days');
CREATE INDEX ON eda_measurements (community_id, metering_point_id, time DESC);

CREATE TABLE eda_imports (
    id               UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id     UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    imported_by      UUID REFERENCES users(id),
    filename         TEXT NOT NULL,
    period_from      TIMESTAMPTZ NOT NULL,
    period_to        TIMESTAMPTZ NOT NULL,
    records_imported INTEGER DEFAULT 0,
    warnings         JSONB DEFAULT '[]',
    status           TEXT NOT NULL DEFAULT 'ok' CHECK (status IN ('ok', 'warning', 'error')),
    imported_at      TIMESTAMPTZ DEFAULT now()
);

-- ─────────────────────────────────────────
-- KONFIGURATION (historisiert mit valid_from)
-- ─────────────────────────────────────────

CREATE TABLE tariff_config (
    id                    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id          UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    valid_from            DATE NOT NULL,
    bezug_ct_kwh          NUMERIC(6,4) NOT NULL,
    einspeisung_ct_kwh    NUMERIC(6,4) NOT NULL,
    mitgliedsbeitrag_eur  NUMERIC(8,2) NOT NULL,
    created_at            TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE tax_config (
    id               UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id     UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    valid_from       DATE NOT NULL,
    tax_model        TEXT NOT NULL CHECK (tax_model IN ('kleinunternehmer', 'standard')),
    tax_rate_percent NUMERIC(5,2),
    uid_number       TEXT,
    created_at       TIMESTAMPTZ DEFAULT now()
);

-- ─────────────────────────────────────────
-- ABRECHNUNG
-- ─────────────────────────────────────────

CREATE TABLE billing_runs (
    id                 UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id       UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    quartal            TEXT NOT NULL,
    period_from        DATE NOT NULL,
    period_to          DATE NOT NULL,
    freigabe_nach      DATE NOT NULL,
    status             TEXT NOT NULL DEFAULT 'pending'
                       CHECK (status IN ('pending', 'ready', 'released', 'done')),
    completeness_check JSONB,
    released_by        UUID REFERENCES users(id),
    released_at        TIMESTAMPTZ,
    created_at         TIMESTAMPTZ DEFAULT now(),
    UNIQUE (community_id, quartal)
);

CREATE TABLE invoices (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    billing_run_id  UUID NOT NULL REFERENCES billing_runs(id),
    community_id    UUID NOT NULL,
    member_id       UUID NOT NULL REFERENCES members(id),
    rechnungsnummer TEXT NOT NULL UNIQUE,
    saldo_eur       NUMERIC(10,2) NOT NULL,
    pdf_path        TEXT,
    sent_at         TIMESTAMPTZ,
    created_at      TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE invoice_items (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    invoice_id  UUID NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    type        TEXT NOT NULL CHECK (type IN ('bezug', 'einspeisung', 'mitgliedsbeitrag')),
    kwh         NUMERIC(12,4),
    rate_ct_kwh NUMERIC(6,4),
    months      NUMERIC(4,2),
    amount_eur  NUMERIC(10,2) NOT NULL
);

-- ─────────────────────────────────────────
-- ROW-LEVEL SECURITY
-- ─────────────────────────────────────────

ALTER TABLE members          ENABLE ROW LEVEL SECURITY;
ALTER TABLE metering_points  ENABLE ROW LEVEL SECURITY;
ALTER TABLE esp_measurements ENABLE ROW LEVEL SECURITY;
ALTER TABLE eda_measurements ENABLE ROW LEVEL SECURITY;
ALTER TABLE eda_imports      ENABLE ROW LEVEL SECURITY;
ALTER TABLE tariff_config    ENABLE ROW LEVEL SECURITY;
ALTER TABLE tax_config       ENABLE ROW LEVEL SECURITY;
ALTER TABLE billing_runs     ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoices         ENABLE ROW LEVEL SECURITY;
ALTER TABLE invoice_items    ENABLE ROW LEVEL SECURITY;

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
