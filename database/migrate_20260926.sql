-- Migration 2026-09-26: Gutschriften-Tracking (manuelle Überweisung durchgeführt) + Erinnerung,
-- sobald die SEPA-Vorabinformationsfrist eines freigegebenen Abrechnungslaufs abgelaufen ist.
--
-- Patrick, 26.09.2026: "Erst wenn's [eine Gutschrift] durchgeführt ist, soll es dann irgendwo weg
-- sein" -- invoices.gutschrift_ausgezahlt_at markiert eine manuell überwiesene Gutschrift
-- (saldo_eur < 0). Solange NULL, gilt sie als offen (siehe /portal/billing/gutschriften und die
-- Login-Erinnerung für Obmann/Platform-Admin).
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS gutschrift_ausgezahlt_at TIMESTAMPTZ;

-- "möchte gerne per E-Mail verständigt werden, wenn die Pre-Notification-Zeit vorbei ist [...]
-- damit ich jetzt die SEPA-Lastschrift-XML-Datei [...] hochladen [...] kann." --
-- sepa_xml_heruntergeladen_at wird beim ersten Download der Sammellastschrift-XML gesetzt
-- (/portal/billing/:id/sepa-xml) und verhindert, dass scripts/sepa_faelligkeit_check.php danach
-- weiter an denselben Lauf erinnert. sepa_faelligkeit_erinnerung_gesendet_at verhindert eine
-- doppelte Erinnerungs-Mail bei mehrfachem Cron-Lauf.
ALTER TABLE billing_runs ADD COLUMN IF NOT EXISTS sepa_xml_heruntergeladen_at TIMESTAMPTZ;
ALTER TABLE billing_runs ADD COLUMN IF NOT EXISTS sepa_faelligkeit_erinnerung_gesendet_at TIMESTAMPTZ;
