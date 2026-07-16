-- Migration 2026-07-27: Verträge nach dem Versand zurücksetzbar machen (nur wenn bereits
-- gesendet) + gemeinsamer Versand von Bezugs- und Einspeisevereinbarung mit differenzierten,
-- im Platform-Admin editierbaren E-Mail-Vorlagen.

ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_sent_at TIMESTAMPTZ;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_bezug_version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_sent_at TIMESTAMPTZ;
ALTER TABLE members ADD COLUMN IF NOT EXISTS contract_einspeisung_version INTEGER NOT NULL DEFAULT 1;

INSERT INTO platform_mail_templates (key, subject, body_html) VALUES
(
    'contract_bezug',
    'Ihre Bezugsvereinbarung – {{eeg_name}}',
    '<p>Hallo {{vorname}},</p>' ||
    '<p>im Anhang finden Sie Ihre Bezugsvereinbarung mit {{eeg_name}}.</p>' ||
    '{{hinweis}}'
),
(
    'contract_einspeisung',
    'Ihre Einspeisevereinbarung – {{eeg_name}}',
    '<p>Hallo {{vorname}},</p>' ||
    '<p>im Anhang finden Sie Ihre Einspeisevereinbarung mit {{eeg_name}}.</p>' ||
    '{{hinweis}}'
),
(
    'contract_both',
    'Ihre Vereinbarungen – {{eeg_name}}',
    '<p>Hallo {{vorname}},</p>' ||
    '<p>im Anhang finden Sie Ihre Bezugsvereinbarung und Ihre Einspeisevereinbarung mit {{eeg_name}}.</p>' ||
    '{{hinweis}}'
)
ON CONFLICT (key) DO NOTHING;
