-- Konfigurierbare Empfängeradressen für den Backup-Fehler-Alarm (scripts/backup.sh /
-- backup-storage.sh / sync-to-nas.sh -> scripts/backup_alert.php). In der DB gepflegt
-- (Platform-Admin -> E-Mail-Einstellungen), damit die Alarmierung auch nach einem Umzug auf
-- ein anderes Gerät ohne Code-Änderung weiterläuft. Beide optional; ist keine gesetzt, geht
-- der Alarm an den ersten aktiven Platform-Admin.
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS backup_alert_email_1 TEXT;
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS backup_alert_email_2 TEXT;
