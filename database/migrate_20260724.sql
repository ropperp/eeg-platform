-- Migration 2026-07-24: Bearbeitbare Texte für System-E-Mails (Passwort-Reset, Erstlogin-
-- Einladung) im Platform-Admin, statt hartcodierter Texte in index.php.
--
-- Plattformweit statt je EEG (wie das ungenutzte email_templates aus migrate_20260714.sql),
-- weil diese Mails aus der zentralen stromfueralle-Plattform kommen, nicht von einer
-- einzelnen EEG -- passend zu platform_mail_config aus migrate_20260722.sql.

CREATE TABLE IF NOT EXISTS platform_mail_templates (
    key         TEXT PRIMARY KEY,
    subject     TEXT NOT NULL,
    body_html   TEXT NOT NULL,
    updated_at  TIMESTAMPTZ DEFAULT now()
);

INSERT INTO platform_mail_templates (key, subject, body_html) VALUES
(
    'password_reset',
    'Passwort zurücksetzen – Strom für alle',
    '<p>Hallo {{vorname}},</p>' ||
    '<p>über folgenden Link können Sie innerhalb der nächsten {{gueltigkeit}} ein neues Passwort vergeben:</p>' ||
    '<p><a href="{{link}}">{{link}}</a></p>' ||
    '<p>Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail einfach.</p>'
),
(
    'invite',
    'Willkommen bei Strom für alle – Zugang einrichten',
    '<p>Hallo {{vorname}},</p>' ||
    '<p>Ihr Zugang zum Mitgliederportal wurde angelegt. Bitte vergeben Sie über folgenden Link ' ||
    'innerhalb der nächsten {{gueltigkeit}} Ihr persönliches Passwort:</p>' ||
    '<p><a href="{{link}}">{{link}}</a></p>'
)
ON CONFLICT (key) DO NOTHING;
