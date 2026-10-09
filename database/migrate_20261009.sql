-- Ersteller-Admin vs. Unteradmin (Patrick, 09.10.2026): "Meine ist die Ersteller-Admin-Adresse,
-- und dann gibt es noch Unteradmins. Sie können mir nicht die Berechtigung wegnehmen [...]
-- Trotzdem haben sie alle Admin-Berechtigungen sonst. Bis halt auf die Rollenverteilung."
ALTER TABLE users ADD COLUMN IF NOT EXISTS founder_admin BOOLEAN NOT NULL DEFAULT false;

-- Markiert automatisch den/die früheste(n) bestehende(n) Platform-Admin als Ersteller-Admin
-- (auf dem Produktivserver ist das Patrick selbst, der bisher einzige Platform-Admin) -- nur
-- falls noch kein Ersteller-Admin existiert, damit die Migration gefahrlos erneut laufen kann.
UPDATE users SET founder_admin = true
WHERE id = (
    SELECT user_id FROM user_roles WHERE role = 'platform_admin' ORDER BY created_at ASC LIMIT 1
)
AND NOT EXISTS (SELECT 1 FROM users WHERE founder_admin = true);
