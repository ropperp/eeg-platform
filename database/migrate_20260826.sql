-- Migration 2026-08-26: MQTT-Zugangsdaten im Platform-Admin sichtbar/änderbar machen.
--
-- Bisher lagen MQTT_USER/MQTT_PASSWORD ausschließlich in .env auf dem Server (Klartext, von
-- scripts/mqtt_secure_setup.sh zufällig als 24-stelliger Hex-String generiert) -- nirgends auf
-- der Plattform selbst sichtbar oder änderbar, und der Webapp-Container hat gar keinen Zugriff
-- auf .env. platform_mqtt_config (Singleton, wie platform_mail_config) macht die DB stattdessen
-- zur Quelle der Wahrheit: Platform-Admin kann hier ein leichter merkbares Passwort eintragen,
-- ./scripts/mqtt_secure_setup.sh --apply liest diese Werte anschließend aus und wendet sie auf
-- den echten Broker an (Passwort-Datei neu erzeugen, .env aktualisieren, mosquitto +
-- mqtt-subscriber neu starten) -- der Webapp-Container selbst kann Docker/Dateisystem des Hosts
-- nicht direkt anfassen, das Anwenden bleibt deshalb ein Kommandozeilen-Schritt auf dem Server.
-- Bewusst Klartext (keine Verschlüsselung): entspricht exakt dem bisherigen Stand in .env (auch
-- dort nie verschlüsselt) -- keine Verschlechterung, aber auch kein Vorwand, dieses Feld absichtlich
-- schwerer lesbar zu machen, wenn "sichtbar auf der Plattform" ausdrücklich der Zweck ist.
CREATE TABLE IF NOT EXISTS platform_mqtt_config (
    id            INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    mqtt_user     TEXT,
    mqtt_password TEXT,
    updated_at    TIMESTAMPTZ DEFAULT now()
);
INSERT INTO platform_mqtt_config (id) VALUES (1) ON CONFLICT (id) DO NOTHING;
