-- Konfigurierbare ESP-Offline-Schwelle: bisher galt ein ESP als "online", solange die letzte
-- empfangene Status-Nachricht "online" war (esp_online), ganz ohne Rücksicht darauf, wie lange
-- das her ist. Ein hängengebliebenes Gerät (TCP-Verbindung technisch noch offen, Firmware aber
-- abgestürzt, MQTT-Last-Will-Testament wird dadurch nie ausgelöst) hätte für immer als "online"
-- angezeigt werden können. Jetzt zusätzlich ein Zeitfenster (Minuten seit esp_last_seen_at),
-- ab dem die Plattform ein Gerät trotz esp_online=true als offline behandelt -- siehe
-- espOfflineAfterMinutes() in webapp/public/index.php.
ALTER TABLE platform_settings ADD COLUMN IF NOT EXISTS esp_offline_after_minutes INTEGER NOT NULL DEFAULT 5;
