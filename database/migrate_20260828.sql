-- Migration 2026-08-28: ESP-Firmwareversion sichtbar machen (Patrick, 12.08.2026: "wäre aber
-- doch noch cool, wenn der ESP alle paar Stunden die aktuelle Firmwareversion hochlädt und das
-- auch in der App ... zeigt: Hat sich der ESP schon abgedatet? Hat er sich noch nicht
-- abgedatet?").
--
-- esp_firmware_version: vom ESP im periodischen Status-Heartbeat mitgeschickte
-- FIRMWARE_VERSION (siehe sketch_ESP32_P1_Smart_Meter.ino, Feld "fw"), analog zu den
-- bestehenden wifi_ssid/wifi_ip-Feldern aus demselben Heartbeat.
ALTER TABLE metering_points ADD COLUMN IF NOT EXISTS esp_firmware_version TEXT;

-- Cache der neuesten verfügbaren Firmware-Version (aus den GitHub Releases dieses Repos,
-- gleiche Quelle wie checkForFirmwareUpdate() im ESP selbst) -- damit nicht jeder Aufruf der
-- Mitglied-Detailseite einen eigenen GitHub-API-Request auslöst (Rate-Limit), siehe
-- latestFirmwareVersion() in public/index.php.
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS latest_firmware_version TEXT;
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS latest_firmware_checked_at TIMESTAMPTZ;
