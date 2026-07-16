-- Migration 2026-07-26: Mitglied-Löschen neu gestaltet.
--
-- Bisher gab es nur "Login löschen" (Login-Konto entfernen, Mitglied bleibt) und einen
-- echten Hard-Delete ("DELETE FROM members ..."). Wegen der Aufbewahrungspflicht für
-- Verträge/Dateien darf ein Mitglied, das die EEG wirklich verlassen möchte, aber nicht
-- mehr hart gelöscht werden -- stattdessen wird es (Login + members.status) deaktiviert,
-- alle Daten/Dateien/Verträge bleiben erhalten, und ein Platform-Admin kann es später über
-- "Freigeben" reaktivieren. members.status = 'inactive' (bereits vorhandener CHECK-Wert)
-- dient dabei als Markierung für "wirklich gelöscht/deaktiviert", der Hard-Delete-Endpunkt
-- entfällt für einzelne Mitglieder (bleibt nur beim kompletten EEG-Löschen bestehen).

INSERT INTO platform_mail_templates (key, subject, body_html) VALUES
(
    'member_deactivated',
    'Ihre Mitgliedschaft bei Strom für alle wurde deaktiviert',
    '<p>Hallo {{vorname}},</p>' ||
    '<p>Ihr Zugang zum Mitgliederportal wurde auf Ihren Wunsch deaktiviert. Ihre Daten, ' ||
    'Verträge und Dateien bleiben aus rechtlichen Aufbewahrungsgründen weiterhin gespeichert, ' ||
    'Sie können sich jedoch ab sofort nicht mehr einloggen.</p>' ||
    '<p>Falls Sie Ihre Mitgliedschaft reaktivieren möchten, wenden Sie sich bitte an Ihre ' ||
    'EEG-Verwaltung (Obmann/Kassier) oder direkt an die Plattform-Administration.</p>'
)
ON CONFLICT (key) DO NOTHING;
