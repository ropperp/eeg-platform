-- Migration 2026-08-11: Logo/Bild für die E-Mail-Signatur.
--
-- Wird als Base64 direkt in der DB gehalten (kein Dateisystem-Pfad), damit es beim Versand
-- ohne Storage-Zugriff als Inline-Anhang (Content-ID "signaturelogo") in jede Mail eingebettet
-- werden kann und einen Geräteumzug ohne separate Datei-Migration übersteht. signature_logo_type
-- ist der MIME-Typ (z. B. image/png), signature_logo_base64 die reinen Base64-Bytes ohne Präfix.
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS signature_logo_base64 TEXT;
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS signature_logo_type   TEXT;
