-- Reply-To-Adresse für den Mailversand konfigurierbar machen (Platform-Admin ->
-- E-Mail-Einstellungen), statt sie im Code hart zu verdrahten. Anwendungsfall: Absender ist
-- eine unüberwachte Shared Mailbox (noreply@...), Antworten der Kunden sollen aber an ein
-- tatsächlich gelesenes Postfach (office@...) gehen.
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS reply_to TEXT;
