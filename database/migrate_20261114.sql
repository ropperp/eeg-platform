-- ─────────────────────────────────────────
-- 14.11.2026: Selbst anordnenbare Startseiten-Kästen ("Widgets")
-- Patrick: jeder soll die Kästen seiner Startseite (Admin, Obmann, Mitglied) selbst anordnen
-- können, gespeichert im Konto. Eine Zeile je Benutzer und Startseite; layout =
-- {"order": [...Kennungen], "hidden": [...Kennungen]}, siehe webapp/src/dashboard_widgets.php.
-- Keine Zeile = Standard-Reihenfolge. Idempotent.
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_dashboard_layouts (
    user_id        UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    dashboard_key  TEXT NOT NULL CHECK (dashboard_key IN ('admin', 'manager', 'member')),
    layout         JSONB NOT NULL,
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (user_id, dashboard_key)
);
