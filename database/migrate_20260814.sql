-- Migration 2026-08-14: Rücklastschrift + Mahnwesen.
--
-- Wird eine per SEPA eingezogene Rechnung von der Bank zurückgebucht (R-Transaktion), setzt der
-- Obmann sie auf 'fehlgeschlagen' (ruecklastschrift_at). Danach lässt sich stufenweise mahnen:
-- Stufe 1 = Zahlungserinnerung, 2 = 1. Mahnung, 3 = 2./letzte Mahnung. Je Mahnung kann eine
-- konfigurierbare Mahngebühr (communities.mahngebuehr_eur) dazukommen, die sich in
-- invoices.mahn_gebuehr_summe_eur aufsummiert.
ALTER TABLE communities ADD COLUMN IF NOT EXISTS mahngebuehr_eur NUMERIC(10,2) NOT NULL DEFAULT 0;

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS mahnstufe INTEGER NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS mahn_gebuehr_summe_eur NUMERIC(10,2) NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS letzte_mahnung_at TIMESTAMPTZ;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS ruecklastschrift_at TIMESTAMPTZ;

-- E-Mail-Vorlage für Zahlungserinnerung/Mahnung. {{mahnstufe_text}} liefert je Stufe die
-- passende Bezeichnung, {{ruecklast_hinweis}}/{{gebuehr_zeile}} werden vom Code je nach Situation
-- gefüllt (oder bleiben leer). {{gesamt}} = offener Brutto-Betrag + aufgelaufene Mahngebühren.
INSERT INTO platform_mail_templates (key, subject, body_html) VALUES
(
    'mahnung',
    '{{mahnstufe_text}}: Rechnung {{rechnungsnummer}} – {{eeg_name}}',
    '<p>{{anrede}} {{nachname}},</p>' ||
    '<p>zu Ihrer Rechnung <strong>{{rechnungsnummer}}</strong> ist bei uns bislang kein vollständiger Zahlungseingang verbucht{{ruecklast_hinweis}}.</p>' ||
    '<p>Offener Betrag: <strong>{{betrag}} €</strong>{{gebuehr_zeile}}<br>Bitte zu überweisen: <strong>{{gesamt}} €</strong></p>' ||
    '<p>Wir bitten Sie, den Betrag bis spätestens <strong>{{frist}}</strong> auf folgendes Konto zu überweisen:<br>' ||
    'IBAN: {{iban}}<br>Verwendungszweck: {{rechnungsnummer}}</p>' ||
    '<p>Sollte sich Ihre Zahlung mit diesem Schreiben überschnitten haben, betrachten Sie es bitte als gegenstandslos.</p>'
)
ON CONFLICT (key) DO NOTHING;
