-- Migration 2026-08-10: E-Mail-Vorlage für die SEPA-Vorabinformation (Pre-Notification).
--
-- Wird bei der Freigabe eines Abrechnungslaufs an jedes einzuziehende Mitglied (saldo > 0 mit
-- gültigem Mandat) verschickt und kündigt das Abbuchungsdatum (= Rechnungsdatum + Vorlauftage
-- der EEG, Default 14) an. Ohne diesen Datensatz greift der im Code hinterlegte Fallback-Text;
-- der Eintrag hier macht die Vorlage im Platform-Admin (/admin -> E-Mail-Vorlagen) editierbar.
-- Platzhalter: {{vorname}} {{eeg_name}} {{rechnungsnummer}} {{betrag}} {{abbuchung}}
--              {{mandatsreferenz}} {{creditor_id}}
INSERT INTO platform_mail_templates (key, subject, body_html) VALUES
(
    'sepa_prenotification',
    'SEPA-Vorabinformation zu Rechnung {{rechnungsnummer}} – {{eeg_name}}',
    '<p>Hallo {{vorname}},</p>' ||
    '<p>Ihre Rechnung <strong>{{rechnungsnummer}}</strong> über <strong>{{betrag}} €</strong> wird im Wege des ' ||
    'SEPA-Lastschriftverfahrens am <strong>{{abbuchung}}</strong> von Ihrem Konto eingezogen. ' ||
    'Sie müssen nichts weiter veranlassen.</p>' ||
    '<p>Mandatsreferenz: {{mandatsreferenz}}<br>Gläubiger-ID: {{creditor_id}}</p>' ||
    '<p>Diese E-Mail gilt als Vorabankündigung (Pre-Notification) im Sinne des SEPA-Lastschriftverfahrens.</p>'
)
ON CONFLICT (key) DO NOTHING;
