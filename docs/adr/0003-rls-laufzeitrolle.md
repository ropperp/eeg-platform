# ADR 0003: Eingeschränkte DB-Rollen, RLS in Produktion Pflicht

**Status:** angenommen (16.11.2026)

## Kontext
Die Mandantentrennung beruht auf PostgreSQL Row-Level Security. Der Schema-Besitzer `eeg` ist im
Postgres-Image Superuser und umgeht RLS immer. Bis 16.11.2026 fiel die Webapp still auf diese Rolle
zurück, wenn `APP_DB_USER` fehlte; mqtt-subscriber und EDA-Parser liefen dauerhaft als Superuser.

## Entscheidung
- Drei Rollen: `eeg` (Superuser, nur Migrationen/Backup), `eeg_app` (Webapp, nur Daten, RLS greift),
  `eeg_worker` (Hintergrunddienste, nur Daten, `BYPASSRLS`, kein Superuser).
  SQL: `scripts/sql/runtime_roles.sql`, eingerichtet durch `scripts/db_runtime_role_setup.sh`.
- `APP_ENV=production` (Standard): ohne `APP_DB_USER` startet die Webapp nicht.
- Der Healthcheck prüft die tatsächlich verbundene Rolle (Superuser, BYPASSRLS, Tabellenbesitz).
- Integrationstest: zwei Communities, Lesen/Ändern/Löschen/Anlegen über Grenzen hinweg schlägt fehl.

## Folgen
- Ein vergessener `DB::setCommunity()`-Aufruf zeigt leere Listen statt fremder Daten.
- Hintergrunddienste können weiterhin alle Communities verarbeiten, aber kein Schema ändern.
- Rückfall ohne Code-Änderung: `WORKER_DB_USER` leeren (Dienste wieder als `eeg`), `APP_ENV=development`
  nur als kurzfristiger Notbehelf.
