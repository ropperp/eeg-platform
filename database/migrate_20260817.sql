-- ESB-Online-Tracking + WLAN-Diagnosefelder (siehe docs/ESB_IDEEN.md, Punkt 1+2).
-- Der ESP32-P1-Smart-Meter-Sketch (esp32-firmware/p1-smart-meter/) sendet bereits einen
-- MQTT-Status-Heartbeat (eeg/{rc}/meter/{zaehler}/status, retained, mit LWT "offline") --
-- der mqtt-subscriber schreibt das ab jetzt hierher. wifi_ssid/wifi_ip/wifi_password_enc sind
-- vorbereitet für Punkt 1 (WLAN-Diagnose), auch wenn der aktuelle Firmware-Stand diese Felder
-- noch nicht mitschickt -- sobald er es tut, greifen sie ohne weitere Migration.
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS esb_online BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS esb_last_seen_at TIMESTAMPTZ;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS wifi_ssid TEXT;
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS wifi_ip TEXT;
-- Verschlüsselt (AES-256-CBC mit APP_SECRET, siehe encryptSecret()/decryptSecret() in
-- functions.php) -- NICHT gehasht, da wir es dem Obmann/Admin wieder anzeigen können müssen,
-- um bei WLAN-Problemen beim Mitglied helfen zu können (siehe ESB_IDEEN.md, Sicherheitshinweis).
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS wifi_password_enc TEXT;
