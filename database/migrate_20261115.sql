-- 15.11.2026: Passwort-vergessen-/Einladungs-Codes liegen ab diesem Update nur noch als
-- SHA-256-Hash in users.reset_token (der Klartext-Code steht nur im Link der E-Mail).
-- Noch offene Codes im alten Klartext-Format passen nicht mehr zur gehashten Abfrage und werden
-- hier entfernt, damit keine verwertbaren Klartext-Codes in der Datenbank zurückbleiben.
-- Betroffen sind nur Links, die gerade offen sind (Passwort vergessen: 1 Stunde, Einladung: 48 Stunden) --
-- wer so einen Link noch nicht benutzt hat, fordert einfach einen neuen an. Idempotent.
UPDATE users SET reset_token = NULL, reset_token_expires = NULL WHERE reset_token IS NOT NULL;
