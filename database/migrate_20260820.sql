-- Zähler-Erreichbarkeit (P1-Signal) getrennt vom ESP-eigenen Online-Status (siehe
-- docs/ESP_IDEEN.md Punkt 4). Bei Stromausfall/Inselbetrieb beim Mitglied bleibt der ESP32
-- ggf. über WLAN erreichbar, verliert aber die Kommunikation zum Smart Meter -- so lässt sich
-- unterscheiden, ob ein Problem beim Kunden liegt oder an der Plattform/am ESP selbst.
-- meter_last_seen_at wird (analog zu esp_last_seen_at) bewusst nur aktualisiert, wenn der
-- Zähler tatsächlich erreichbar war, siehe mqtt-subscriber/main.py update_status().
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS meter_reachable BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS meter_last_seen_at TIMESTAMPTZ;
