-- Migration 2026-10-04: Rechnungs-E-Mail (PDF-Anhang) bei der Freigabe.
--
-- Patrick, 02.10.2026: "Ab dem Zeitpunkt, an dem die E-Mail mit den fertigen Rechnungen
-- rausgeschickt wird, gelten die 14 Tage [...] erst, wenn wirklich freigegeben und abgesendet
-- werden." Bisher ging bei der Freigabe nur die SEPA-Vorabinfo an Mitglieder mit einzuziehendem
-- Saldo raus -- Mitglieder mit Gutschrift oder ohne App-Login bekamen GAR KEINE Mail. Diese
-- Migration legt die Spalte für den neuen, allgemeinen Rechnungs-Mail-Versand an (siehe
-- sendInvoiceReleasedEmails() in webapp/public/index.php, aufgerufen direkt nach
-- Billing::finalize() in /portal/billing/release) sowie die zugehörige, im Platform-Admin
-- anpassbare E-Mail-Vorlage.
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS email_sent_at TIMESTAMPTZ;

INSERT INTO platform_mail_templates (key, subject, body_html) VALUES
(
    'invoice_released',
    'Ihre Rechnung {{rechnungsnummer}} – {{eeg_name}}',
    '<p>{{anrede}} {{nachname}},</p>' ||
    '<p>im Anhang finden Sie Ihre Rechnung <strong>{{rechnungsnummer}}</strong> von {{eeg_name}}. ' ||
    'Darin ausgewiesen ist {{betrag_text}}.</p>' ||
    '<p>Sie können die Rechnung außerdem jederzeit im Mitgliederportal einsehen.</p>'
)
ON CONFLICT (key) DO NOTHING;
