-- Migration 2026-08-29: MQTT-Fernkonfiguration der ESP-Geräte (Patrick, 12.08.2026: "Ja machen
-- wir. Das mit dem Remote Reconfig." -- nachdem geklärt war, dass dafür KEINE offenen Ports
-- beim Mitglied nötig sind, weil jedes Gerät die MQTT-Verbindung selbst ausgehend aufbaut).
--
-- Platform-Admin -> E-Mail-Einstellungen -> "MQTT-Fernkonfiguration (Geräte)" speichert hier
-- den gewünschten Host/Port/Benutzer/Passwort als JSON; mqtt-subscriber (main.py,
-- reconfig_broadcast_loop()) pollt periodisch, ob device_reconfig_requested_at neuer ist als
-- device_reconfig_sent_at, und published den Payload dann retained auf das cmd-Topic jedes
-- bekannten Zählpunkts (siehe onMqttMessage() im ESP32-Sketch).
ALTER TABLE platform_mqtt_config ADD COLUMN IF NOT EXISTS device_reconfig_payload JSONB;
ALTER TABLE platform_mqtt_config ADD COLUMN IF NOT EXISTS device_reconfig_requested_at TIMESTAMPTZ;
ALTER TABLE platform_mqtt_config ADD COLUMN IF NOT EXISTS device_reconfig_sent_at TIMESTAMPTZ;
ALTER TABLE platform_mqtt_config ADD COLUMN IF NOT EXISTS device_reconfig_sent_count INTEGER;
