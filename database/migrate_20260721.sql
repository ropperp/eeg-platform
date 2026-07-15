-- Migration 2026-07-21: Dashboard-URL pro EEG konfigurierbar machen.
-- Bisher war der Link im Bezugsvereinbarung-Vertrag (Verweis auf die Erzeugungsanlagen-Liste
-- im Mitgliederportal) hardcodiert auf https://portal.stromfueralle.at/portal/login. Jeder
-- Obmann/Manager soll das in den Einstellungen selbst pflegen können, falls sich die
-- Verlinkung ändert. NULL/leer faellt in index.php weiterhin auf den bisherigen Standard-Link zurück.

ALTER TABLE communities ADD COLUMN IF NOT EXISTS dashboard_url TEXT;
