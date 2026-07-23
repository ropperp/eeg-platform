-- Migration 2026-08-16: Optionale Zwei-Faktor-Authentifizierung (TOTP) je Benutzer.
--
-- totp_secret: Base32-Schlüssel (in Apple Passwörter/Authenticator hinterlegt). totp_enabled
-- schaltet die Abfrage beim Login an/aus -- bewusst pro Benutzer selbst ein-/ausschaltbar, damit
-- ein häufiger Account-Wechsel während der Entwicklung nicht ausgebremst wird. Ist es aus, bleibt
-- das Secret erhalten, muss zum Wiedereinschalten aber neu bestätigt werden (Code-Eingabe).
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret  TEXT;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_enabled BOOLEAN NOT NULL DEFAULT false;
