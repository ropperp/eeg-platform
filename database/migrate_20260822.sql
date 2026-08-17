-- Migration: Row-Level Security greift jetzt auch, falls doch einmal als
-- Tabellenbesitzer verbunden wird (OWASP-Audit, 13.08.2026).
--
-- Der eigentliche Fix ist die neue, eingeschränkte Laufzeit-DB-Rolle (siehe
-- scripts/db_runtime_role_setup.sh) -- RLS-Policies gelten für den Tabellenbesitzer per
-- Postgres-Standardverhalten NIE, unabhängig von FORCE ROW LEVEL SECURITY. FORCE hier trotzdem
-- ergänzt als zusätzliches Sicherheitsnetz, falls die App irgendwann doch wieder (versehentlich
-- oder für einen Wartungszugriff) mit der Besitzer-Rolle verbindet -- dann greifen die Policies
-- wenigstens, statt komplett übergangen zu werden.
--
-- invoice_items hatte ENABLE ROW LEVEL SECURITY, aber NIE eine Policy (in init.sql übersehen)
-- -- eine Tabelle mit aktiviertem RLS, aber ohne jede Policy, liefert für eine Rolle, die
-- überhaupt RLS unterliegt, per Postgres-Standardverhalten GAR KEINE Zeile zurück (impliziter
-- Deny-All). Bislang folgenlos, weil die App bisher immer als Tabellenbesitzer verband (siehe
-- Hauptbefund oben) -- mit der neuen eingeschränkten Rolle (scripts/db_runtime_role_setup.sh)
-- wäre das sonst sofort aufgefallen: Rechnungspositionen wären für jede Rechnung leer
-- angezeigt worden. invoice_items hat kein eigenes community_id-Feld (nur invoice_id), die
-- Zuordnung läuft daher über die übergeordnete Rechnung.
--
-- Live gegen eine native PostgreSQL-16-Instanz getestet (14.08.2026): als Tabellenbesitzer
-- waren trotz gesetztem app.community_id Zeilen BEIDER Communities sichtbar (Bug bestätigt);
-- als neue eingeschränkte Rolle (eeg_app) korrekt nur die Zeilen der eigenen Community,
-- inklusive invoice_items über den JOIN auf invoices.
CREATE POLICY community_isolation ON invoice_items
    USING (EXISTS (
        SELECT 1 FROM invoices i
        WHERE i.id = invoice_items.invoice_id
          AND i.community_id = current_setting('app.community_id', true)::uuid
    ));

ALTER TABLE members            FORCE ROW LEVEL SECURITY;
ALTER TABLE metering_points    FORCE ROW LEVEL SECURITY;
ALTER TABLE esp_measurements   FORCE ROW LEVEL SECURITY;
ALTER TABLE eda_measurements   FORCE ROW LEVEL SECURITY;
ALTER TABLE eda_imports        FORCE ROW LEVEL SECURITY;
ALTER TABLE tariff_config      FORCE ROW LEVEL SECURITY;
ALTER TABLE tax_config         FORCE ROW LEVEL SECURITY;
ALTER TABLE billing_runs       FORCE ROW LEVEL SECURITY;
ALTER TABLE invoices           FORCE ROW LEVEL SECURITY;
ALTER TABLE invoice_items      FORCE ROW LEVEL SECURITY;
