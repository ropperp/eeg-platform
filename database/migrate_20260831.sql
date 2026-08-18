-- Migration 2026-08-31: app_sessions um Obmann-Logins und Konto-Endpunkte erweitern.
--
-- App-Login unterstützt jetzt neben Mitgliedern auch Obleute/Manager (z.B. um von unterwegs ein
-- neues Mitglied anzulegen) -- ein reiner Obmann-Account hat aber nicht zwingend einen eigenen
-- Mitgliedsdatensatz in der EEG, member_id muss deshalb NULLABLE werden. Zusätzlich wird die
-- Rolle mitgespeichert, damit ein erneuertes Zugriffstoken (POST /api/v1/token/refresh) wieder
-- mit derselben Rolle ausgestellt wird, ohne erneut nachfragen zu müssen.
--
-- user_id wird zusätzlich gespeichert für die neuen Konto-Endpunkte (Profil/Passwort/2FA,
-- /api/v1/profile & co.) -- die betreffen den Login-Account (users-Tabelle), nicht den
-- Mitgliedsdatensatz. Bei role='member' ließe sich user_id zwar über members.user_id
-- herleiten, bei role='manager' gibt es aber keinen member_id, über den das ginge -- deshalb
-- wird sie hier für BEIDE Rollen einheitlich direkt mitgeführt (keine Sonderfall-Logik nötig,
-- ein Feld weniger Nachschlagen pro Request).
ALTER TABLE app_sessions ALTER COLUMN member_id DROP NOT NULL;
ALTER TABLE app_sessions ADD COLUMN role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('member', 'manager'));
ALTER TABLE app_sessions ALTER COLUMN role DROP DEFAULT;
ALTER TABLE app_sessions ADD COLUMN user_id UUID REFERENCES users(id) ON DELETE CASCADE;
