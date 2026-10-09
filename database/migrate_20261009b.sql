-- Vorberechnete Live-Kennzahlen je EEG (Patrick, 09.10.2026: "Die Autarkie kann immer
-- vorberechnet werden und beim Aufruf nur aus einem gespeicherten Wert herausgelesen werden").
-- Wird von mqtt-subscriber (live_stats_loop(), main.py) alle ~15s neu befüllt; /api/live/:slug
-- (webapp) liest daraus, statt bei jedem Seitenaufruf selbst über esp_measurements zu
-- aggregieren. Eine Zeile je EEG (PRIMARY KEY = community_id), per UPSERT aktuell gehalten.
CREATE TABLE IF NOT EXISTS community_live_stats (
    community_id   UUID PRIMARY KEY REFERENCES communities(id) ON DELETE CASCADE,
    bezug_w        INTEGER NOT NULL DEFAULT 0,
    einspeisung_w  INTEGER NOT NULL DEFAULT 0,
    today_wh       NUMERIC NOT NULL DEFAULT 0,
    autarkie_pct   INTEGER NOT NULL DEFAULT 0,
    active_meters  INTEGER NOT NULL DEFAULT 0,
    total_meters   INTEGER NOT NULL DEFAULT 0,
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Grob abgetastete Zeitreihe (1 Wert pro Minute statt live aus esp_measurements aggregiert) für
-- den "Verlauf letzte 2 Stunden"-Chart der Live-Anzeige -- Patrick, 09.10.2026: "ob wir da eh
-- alle Minuten vielleicht einen Wert nehmen. Nur dann sind das eh nicht so viele. Dann sind das
-- eh 120 Werte." mqtt-subscriber überschreibt den jeweils aktuellen Minuten-Bucket laufend
-- (letzter Messwert dieser Minute, kein Durchschnitt -- für die grobe Verlaufsanzeige
-- ausreichend) und löscht alles älter als 3 Stunden gleich mit.
CREATE TABLE IF NOT EXISTS community_power_minutely (
    community_id   UUID NOT NULL REFERENCES communities(id) ON DELETE CASCADE,
    bucket         TIMESTAMPTZ NOT NULL,
    bezug_w        INTEGER NOT NULL DEFAULT 0,
    einspeisung_w  INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (community_id, bucket)
);
CREATE INDEX IF NOT EXISTS idx_community_power_minutely_lookup ON community_power_minutely (community_id, bucket DESC);
