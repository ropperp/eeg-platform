-- ESP-Online-Tracking + WLAN-Diagnosefelder (siehe docs/ESP_IDEEN.md, Punkt 1+2).
-- Der ESP32-P1-Smart-Meter-Sketch (esp32-firmware/p1-smart-meter/) sendet bereits einen
-- MQTT-Status-Heartbeat (eeg/{rc}/meter/{zaehler}/status, retained, mit LWT "offline") --
-- der mqtt-subscriber schreibt das ab jetzt hierher. wifi_ssid/wifi_ip/wifi_password_enc sind
-- vorbereitet für Punkt 1 (WLAN-Diagnose), auch wenn der aktuelle Firmware-Stand diese Felder
-- noch nicht mitschickt -- sobald er es tut, greifen sie ohne weitere Migration.
--
-- Falls diese Migration bereits mit den ursprünglichen esb_*-Spaltennamen eingespielt wurde
-- (vor der ESB→ESP-Umbenennung, "ESB" war schlicht falsch -- die Ausleseeinheit heißt ESP32
-- wie auch die bestehende Tabelle esp_measurements), vorhandene Spalten umbenennen statt
-- Duplikate anzulegen.
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'metering_points' AND column_name = 'esb_online') THEN
    ALTER TABLE metering_points RENAME COLUMN esb_online TO esp_online;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'metering_points' AND column_name = 'esb_last_seen_at') THEN
    ALTER TABLE metering_points RENAME COLUMN esb_last_seen_at TO esp_last_seen_at;
  END IF;
END $$;

ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS esp_online BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS esp_last_seen_at TIMESTAMPTZ;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS wifi_ssid TEXT;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS wifi_ip TEXT;
-- Verschlüsselt (AES-256-CBC mit APP_SECRET, siehe encryptSecret()/decryptSecret() in
-- functions.php) -- NICHT gehasht, da wir es dem Obmann/Admin wieder anzeigen können müssen,
-- um bei WLAN-Problemen beim Mitglied helfen zu können (siehe ESP_IDEEN.md, Sicherheitshinweis).
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS wifi_password_enc TEXT;
