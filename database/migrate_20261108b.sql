-- ─────────────────────────────────────────
-- 08.11.2026 (b): Kontaktseite der Plattform als bearbeitbares Dokument
-- Patrick: unter /kontakt (und den anderen Auswahlseiten) fehlt der allgemeine Teil mit den
-- Daten des Plattformbetreibers -- z. B. für Gruppen, die selbst eine Energiegemeinschaft
-- gründen und auf die Plattform wollen. Der Text wird wie die anderen Plattform-Dokumente
-- unter /portal/dokumente gepflegt (legal_documents, community_id NULL).
-- Idempotent.
-- ─────────────────────────────────────────
ALTER TABLE legal_documents DROP CONSTRAINT IF EXISTS legal_documents_doc_key_check;
ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_doc_key_check
    CHECK (doc_key IN ('agb', 'statuten', 'widerruf', 'impressum', 'datenschutz', 'barrierefreiheit', 'kontakt'));
