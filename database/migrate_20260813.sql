-- Migration 2026-08-13: Anrede-Modus je Mitglied + überarbeitete E-Mail-Vorlagen mit
-- {{anrede}}/{{nachname}} (formelle Anrede statt "Hallo {{vorname}}").
--
-- email_anrede_mode steuert NUR die Anrede in E-Mails, unabhängig vom Geschlecht (salutation),
-- das die Person selbst angibt:
--   auto    = aus dem Geschlecht ableiten (Herr -> "Sehr geehrter Herr", Frau -> "Sehr geehrte Frau")
--   herr    = immer "Sehr geehrter Herr"
--   frau    = immer "Sehr geehrte Frau"
--   familie = immer "Sehr geehrte Familie"
-- Anwendungsfall: der Vertrag läuft auf Herrn Lorenz, die E-Mail-Adresse gehört aber seiner Frau,
-- die den Schriftverkehr macht -> Modus "familie" -> "Sehr geehrte Familie Lorenz". Der Nachname
-- bleibt in jedem Fall der des Vertragspartners.
ALTER TABLE members ADD COLUMN IF NOT EXISTS email_anrede_mode TEXT NOT NULL DEFAULT 'auto';
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'members_email_anrede_mode_check') THEN
        ALTER TABLE members ADD CONSTRAINT members_email_anrede_mode_check
            CHECK (email_anrede_mode IN ('auto', 'herr', 'frau', 'familie'));
    END IF;
END$$;

-- E-Mail-Vorlagen auf die neue, formelle Anrede umstellen (setzt bestehende Fassungen zurück --
-- so gewünscht). {{anrede}} liefert die vollständige Grußformel ("Sehr geehrter Herr" etc.),
-- {{nachname}} den (Titel +) Nachnamen des Vertragspartners.
INSERT INTO platform_mail_templates (key, subject, body_html) VALUES
(
    'invite',
    'Willkommen bei Strom für alle – Zugang einrichten',
    '<p>{{anrede}} {{nachname}},</p>' ||
    '<p>Ihr Zugang zum Mitgliederportal wurde angelegt. Bitte vergeben Sie über folgenden Link innerhalb der nächsten {{gueltigkeit}} Ihr persönliches Passwort:</p>' ||
    '<p><a href="{{link}}">{{link}}</a></p>'
),
(
    'password_reset',
    'Passwort zurücksetzen – Strom für alle',
    '<p>Liebes Mitglied,</p>' ||
    '<p>über folgenden Link können Sie innerhalb der nächsten {{gueltigkeit}} ein neues Passwort vergeben:</p>' ||
    '<p><a href="{{link}}">{{link}}</a></p>' ||
    '<p>Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail einfach.</p>'
),
(
    'member_deactivated',
    'Ihre Mitgliedschaft bei Strom für alle wurde deaktiviert',
    '<p>{{anrede}} {{nachname}},</p>' ||
    '<p>Ihr Zugang zum Mitgliederportal wurde auf Ihren Wunsch deaktiviert. Ihre Daten, Verträge und Dateien bleiben aus rechtlichen Aufbewahrungsgründen weiterhin gespeichert, Sie können sich jedoch ab sofort nicht mehr einloggen.</p>' ||
    '<p>Falls Sie Ihre Mitgliedschaft reaktivieren möchten, wenden Sie sich bitte an Ihre EEG-Verwaltung (Obmann/Kassier) oder direkt an die Plattform-Administration.</p>'
),
(
    'contract_bezug',
    'Ihre Bezugsvereinbarung – {{eeg_name}}',
    '<p>{{anrede}} {{nachname}},</p>' ||
    '<p>Ihre Bezugsvereinbarung mit {{eeg_name}} liegt für Sie bereit. Bitte prüfen Sie die Vereinbarung im Mitgliederportal und unterschreiben Sie dort digital, damit sie gültig wird:</p>' ||
    '<p><a href="{{link}}">{{link}}</a></p>{{hinweis}}'
),
(
    'contract_einspeisung',
    'Ihre Einspeisevereinbarung – {{eeg_name}}',
    '<p>{{anrede}} {{nachname}},</p>' ||
    '<p>Ihre Einspeisevereinbarung mit {{eeg_name}} liegt für Sie bereit. Bitte prüfen Sie die Vereinbarung im Mitgliederportal und unterschreiben Sie dort digital, damit sie gültig wird:</p>' ||
    '<p><a href="{{link}}">{{link}}</a></p>{{hinweis}}'
),
(
    'contract_both',
    'Ihre Vereinbarungen – {{eeg_name}}',
    '<p>{{anrede}} {{nachname}},</p>' ||
    '<p>Ihre Bezugsvereinbarung und Ihre Einspeisevereinbarung mit {{eeg_name}} liegen für Sie bereit. Bitte prüfen Sie beide Vereinbarungen im Mitgliederportal und unterschreiben Sie dort digital, damit sie gültig werden:</p>' ||
    '<p><a href="{{link}}">{{link}}</a></p>{{hinweis}}'
),
(
    'sepa_prenotification',
    'SEPA-Vorabinformation zu Rechnung {{rechnungsnummer}} – {{eeg_name}}',
    '<p>{{anrede}} {{nachname}},</p>' ||
    '<p>Ihre Rechnung <strong>{{rechnungsnummer}}</strong> über <strong>{{betrag}} €</strong> wird im Wege des SEPA-Lastschriftverfahrens am <strong>{{abbuchung}}</strong> von Ihrem Konto eingezogen. Sie müssen nichts weiter veranlassen.</p>' ||
    '<p>Mandatsreferenz: {{mandatsreferenz}}<br>Gläubiger-ID: {{creditor_id}}</p>' ||
    '<p>Diese E-Mail gilt als Vorabankündigung (Pre-Notification) im Sinne des SEPA-Lastschriftverfahrens.</p>'
)
ON CONFLICT (key) DO UPDATE
    SET subject = EXCLUDED.subject, body_html = EXCLUDED.body_html, updated_at = now();
