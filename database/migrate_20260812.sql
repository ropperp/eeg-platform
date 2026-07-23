-- Migration 2026-08-12: Größe (Breite/Höhe in Pixel) für das E-Mail-Signatur-Logo.
--
-- Optional. Ist nur die Breite gesetzt, skaliert die Höhe proportional (und umgekehrt); sind
-- beide gesetzt, gilt exakt Breite x Höhe. Sind beide leer, greift der Standard (max. 64 px hoch).
-- Die Platzierung des Logos steuert der Platzhalter {{logo}} im Signatur-HTML (siehe Mailer::send);
-- ohne Platzhalter wird das Logo wie bisher ans Ende gehängt.
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS signature_logo_width  INTEGER;
ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS signature_logo_height INTEGER;
