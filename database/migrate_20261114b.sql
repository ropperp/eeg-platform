-- 14.11.2026: EDA-Postfach-Import unabhängig vom Lesestatus.
-- Bisher wurden nur UNGELESENE Mails abgeholt -- wer die EDA-Mail selbst öffnete (oder den
-- Download-Link darin anklickte), hat sie damit unbemerkt vom automatischen Import
-- ausgeschlossen. Jetzt merkt sich die Plattform selbst, welche Mails schon verarbeitet sind.
-- Idempotent.
CREATE TABLE IF NOT EXISTS eda_mail_processed (
    message_id    TEXT PRIMARY KEY,               -- Microsoft-Graph-Nachrichten-ID
    mailbox       TEXT NOT NULL,
    subject       TEXT,
    received_at   TIMESTAMPTZ,
    status        TEXT NOT NULL,                  -- importiert | bereits_importiert | ignoriert | fehler | download_fehler
    attempts      INTEGER NOT NULL DEFAULT 1,
    detail        TEXT,
    processed_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS eda_mail_processed_received_idx ON eda_mail_processed (received_at DESC);

-- Zeitpunkt und Ergebnis des letzten Postfach-Checks -- Admin → Einstellungen → EDA-Automatik
-- zeigt das an; steht dort ein alter Zeitpunkt, läuft der Cron auf dem Server nicht.
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS eda_import_last_run_at TIMESTAMPTZ;
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS eda_import_last_run_result TEXT;

-- Viertelstundenwerte (Detailreport) nie doppelt: Patrick, 14.11.2026 -- "Beim Hochladen eines
-- Detailexports werden sich teilweise Daten überschneiden. Bitte lösch immer auch doppelte Daten."
-- Doppelte Zeilen entstanden z.B., wenn derselbe Zeitraum gleichzeitig zweimal importiert wurde
-- (Upload von Hand + Postfach-Abruf), oder durch die doppelte Stunde bei der Zeitumstellung im
-- Oktober. (1) vorhandene Duplikate entfernen (je Zählpunkt/Richtung/Zeitpunkt bleibt genau eine
-- Zeile), (2) eindeutiger Index -- der Import überschreibt seitdem statt doppelt einzufügen.
DELETE FROM eda_interval_data a
USING eda_interval_data b
WHERE a.tableoid = b.tableoid
  AND a.ctid < b.ctid
  AND a.metering_point_id = b.metering_point_id
  AND a.energy_direction = b.energy_direction
  AND a.time = b.time;
CREATE UNIQUE INDEX IF NOT EXISTS eda_interval_data_unique_idx
    ON eda_interval_data (metering_point_id, energy_direction, time);
