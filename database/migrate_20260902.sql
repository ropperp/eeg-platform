-- Migration 2026-09-02: App-Login für Plattform-Admin-Funktionen (dritte App-Rolle "admin").
--
-- Bisher kannten App-Zugriffstoken nur 'member' und 'manager' (Platform-Admins bekamen dabei
-- bewusst dasselbe 'manager'-Token wie ein normaler Obmann, siehe migrate_20260831.sql). Patrick
-- möchte jetzt volle Feature-Parität inkl. der Plattform-Admin-exklusiven Funktionen (EEG-
-- Verwaltung plattformweit, Nutzer & Rollen, Aktivitätslog, Mail-/MQTT-Einstellungen, Backups)
-- auch in der App -- dafür braucht es einen eigenen, dritten Rollenwert 'admin', der zusätzlich
-- zum bisherigen 'manager'-Zugang ausgestellt werden kann (ein Platform-Admin-Account bleibt
-- weiterhin auch ganz normaler Obmann für seine eigene(n) EEG(s), siehe resolveAppRoleOptions()).
--
-- community_id wird NULLABLE, weil ein 'admin'-Token bewusst NICHT an eine einzelne EEG gebunden
-- ist (Plattform-Admin-Funktionen wirken plattformweit über alle EEGs hinweg) -- exakt dasselbe
-- Muster wie member_id in migrate_20260831.sql für den umgekehrten Fall (manager ohne eigene
-- Mitgliedschaft).
ALTER TABLE app_sessions ALTER COLUMN community_id DROP NOT NULL;
ALTER TABLE app_sessions DROP CONSTRAINT IF EXISTS app_sessions_role_check;
ALTER TABLE app_sessions ADD CONSTRAINT app_sessions_role_check CHECK (role IN ('member', 'manager', 'admin'));
