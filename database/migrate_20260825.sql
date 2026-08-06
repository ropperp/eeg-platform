-- Migration 2026-08-25: EDA-Portal-Zugangsdaten je EEG + Postfach für automatischen Datenimport.
--
-- Bisher wird der monatliche EDA-Energiedatenreport von Hand aus dem EDA-Anwenderportal
-- heruntergeladen und über /portal/eda/upload hochgeladen. Ab jetzt kann pro EEG ein eigener
-- EDA-Anwenderportal-Zugang (E-Mail + Passwort, für den Export angelegt) hinterlegt werden --
-- rein zur zentralen Aufbewahrung (wie WLAN-Passwörter, siehe encryptSecret()/decryptSecret()
-- in functions.php), NICHT für einen automatisierten Login/Export-Trigger.
--
-- Der EDA-Exportlink wird an die Login-Adresse des jeweiligen Portal-Users gemailt -- dafür ist
-- ein eigenes, zentrales Postfach eda@stromfueralle.at vorgesehen (Microsoft-365-Shared-Mailbox,
-- gleiche App-Registrierung/Graph-Anbindung wie platform_mail_config, zusätzlich Mail.Read
-- benötigt). scripts/eda_auto_import.php liest dieses Postfach regelmäßig aus, lädt eine im
-- Mail-Text gefundene Download-Adresse herunter und übergibt die Datei automatisch an
-- eda-parser/parser.py -- die Community wird dabei anhand der Marktpartner-ID im Dateinamen
-- zugeordnet (siehe EdaAutoImporter::run()).
ALTER TABLE communities ADD COLUMN IF NOT EXISTS eda_login_email TEXT;
ALTER TABLE communities ADD COLUMN IF NOT EXISTS eda_login_password_enc TEXT;

ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS eda_import_mailbox_address TEXT;
