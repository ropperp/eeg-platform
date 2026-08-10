-- Migration 2026-08-27: MQTT-Zugangsdaten-Änderung per Knopfdruck automatisch anwenden.
--
-- Bisher musste nach dem Speichern eines neuen MQTT-Passworts (platform_mqtt_config, siehe
-- migrate_20260826.sql) noch von Hand `./scripts/mqtt_secure_setup.sh --apply` auf dem Server
-- ausgeführt werden. pending_apply markiert eine gespeicherte, aber noch nicht auf den echten
-- Broker angewendete Änderung -- scripts/mqtt_apply_pending.sh (als Host-Cron, gleiches Muster
-- wie scripts/health_monitor.sh) prüft dieses Flag regelmäßig und wendet eine anstehende
-- Änderung automatisch an, danach wird applied_at gesetzt und pending_apply zurückgesetzt.
ALTER TABLE platform_mqtt_config ADD COLUMN IF NOT EXISTS pending_apply BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE platform_mqtt_config ADD COLUMN IF NOT EXISTS applied_at TIMESTAMPTZ;
