-- Demo-Accounts für Präsentation/Diplomarbeit-Review (Patrick, 05.09.2026): EIN Login soll
-- zwischen vier Rollen wechseln können -- Plattform-Admin, Obmann, UND zwei getrennte, unabhängig
-- wählbare Mitglied-Identitäten (Verbraucher 1, Einspeiser 1) in derselben Community. Bisher war
-- pro (community_id, user_id) höchstens EINE 'member'-Rolle möglich (siehe UNIQUE-Constraint
-- unten) -- das reicht für jeden bisherigen echten Account, aber nicht für dieses Demo-Login.
--
-- Lösung: user_roles bekommt eine optionale member_id-Spalte, die eine 'member'-Zeile auf eine
-- KONKRETE members-Zeile festnagelt statt (wie bisher implizit) über members.user_id aufgelöst zu
-- werden. Für alle bisherigen echten Accounts bleibt member_id NULL -- ihr Verhalten ändert sich
-- dadurch nicht (Auth.php/currentMemberFull() fallen bei member_id=NULL weiterhin auf die alte
-- user_id-Suche zurück).

ALTER TABLE user_roles ADD COLUMN IF NOT EXISTS member_id UUID REFERENCES members(id) ON DELETE CASCADE;

ALTER TABLE user_roles DROP CONSTRAINT IF EXISTS user_roles_community_id_user_id_role_key;

-- Nicht-Mitglied-Rollen (platform_admin, manager): unverändertes Verhalten, höchstens eine Zeile
-- je (community_id, user_id, role).
CREATE UNIQUE INDEX IF NOT EXISTS user_roles_unique_nonmember
    ON user_roles (community_id, user_id, role)
    WHERE role <> 'member';

-- 'member'-Rollen ohne member_id (der Normalfall -- ein echtes Mitglied hat genau einen
-- members-Datensatz je Community): weiterhin höchstens eine Zeile.
CREATE UNIQUE INDEX IF NOT EXISTS user_roles_unique_member_implicit
    ON user_roles (community_id, user_id)
    WHERE role = 'member' AND member_id IS NULL;

-- 'member'-Rollen MIT member_id (Demo-Logins mit mehreren Mitglied-Identitäten): höchstens eine
-- Zeile je konkreter Mitglied-Identität, aber mehrere verschiedene member_id je (community_id,
-- user_id) sind jetzt erlaubt.
CREATE UNIQUE INDEX IF NOT EXISTS user_roles_unique_member_explicit
    ON user_roles (community_id, user_id, member_id)
    WHERE role = 'member' AND member_id IS NOT NULL;

-- Demo-Kennzeichnung: users.is_demo sperrt platformweit JEDEN schreibenden Request dieses Logins
-- (siehe Router.php/AppApiAuth::requireAppAuth()) -- die vom Nutzer geforderten "Read-only"-
-- Accounts. members.is_demo schließt fiktive Mitglied-Identitäten explizit von echten
-- Abrechnungsläufen und Mitglieder-Statistiken aus (siehe Billing.php), unabhängig davon, ob/wie
-- sie gerade über user_roles mit einem Login verknüpft sind.
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_demo BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE members ADD COLUMN IF NOT EXISTS is_demo BOOLEAN NOT NULL DEFAULT false;
