-- Migration 2026-11-08: EEG-eigene Rechtsdokumente und Zustimmungstexte, EEG ohne Ausleseeinheit.
--
-- Ausführen:
--   docker compose exec -T timescaledb psql -U eeg -d eeg_platform < database/migrate_20261108.sql
-- Idempotent (mehrfaches Ausführen schadet nicht).
--
-- Patrick, 08.11.2026: Dokumente, die pro Energiegemeinschaft verschieden sind (AGB, Statuten,
-- Widerruf, Impressum, EEG-Teil der Datenschutzerklärung, Zustimmungstexte, Infoblatt), soll der
-- Obmann selbst pflegen -- nicht nur der Plattform-Admin. Der allgemeine Teil der
-- Datenschutzerklärung und die Barrierefreiheitserklärung gelten für die ganze Plattform.

-- ─────────────────────────────────────────
-- 1. Rechtsdokumente: jede Speicherung ist eine NEUE Zeile (Verlauf, Nachweis welcher Text wann
--    galt). Aktuell gültig = jüngste Zeile je (community_id, doc_key). community_id NULL =
--    plattformweit (nur Plattform-Admin). Ohne Zeile zeigt die Website die im Code hinterlegte
--    Standardfassung (bisherige Texte, siehe webapp/src/views/legal_defaults/).
--    Öffentlich lesbar (wie communities) -> bewusst KEINE Row-Level-Security, Schreibrechte
--    prüft die Anwendung (Obmann der jeweiligen EEG bzw. Plattform-Admin).
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS legal_documents (
    id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id  UUID REFERENCES communities(id) ON DELETE CASCADE,
    doc_key       TEXT NOT NULL CHECK (doc_key IN ('agb', 'statuten', 'widerruf', 'impressum', 'datenschutz', 'barrierefreiheit')),
    content_html  TEXT NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by    UUID REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_legal_documents_lookup
    ON legal_documents (community_id, doc_key, created_at DESC);

-- ─────────────────────────────────────────
-- 2. Zustimmungstexte je EEG: eigene Fassungen zusätzlich zu den Plattform-Fassungen in
--    webapp/src/consent_texts.php. version_key ist plattformweit eindeutig (z.B. "rc108175-1"),
--    weil membership_applications/members.consent_version nur den Schlüssel speichern.
--    texts = {"zustimmung_...": {"kurz": "...", "lang": "..."}, ...}
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS consent_versions (
    id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id  UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    version_key   TEXT NOT NULL UNIQUE,
    texts         JSONB NOT NULL,
    gueltig_ab    DATE NOT NULL DEFAULT CURRENT_DATE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by    UUID REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_consent_versions_community
    ON consent_versions (community_id, created_at DESC);

-- ─────────────────────────────────────────
-- 3. EEG ohne Ausleseeinheit: "normale" Energiegemeinschaft ohne ESP32/P1. Ist das aus,
--    blendet die Plattform alles rund um Ausleseeinheit, P1-Schlüssel und Live-Daten aus und
--    verwendet die Zustimmungs-Fassung ohne P1-Passus (v2-ohne-ae).
--    Bestand: true (bisheriges Verhalten unverändert).
-- ─────────────────────────────────────────
ALTER TABLE communities ADD COLUMN IF NOT EXISTS uses_ausleseeinheit BOOLEAN NOT NULL DEFAULT true;
