-- Ungelesen-Zähler für Support-Tickets: bisher gab es keine Unterscheidung zwischen "Ticket ist
-- offen" und "Ticket hat eine neue, vom Obmann noch nicht gesehene Nachricht" -- der bestehende
-- Sidebar-Badge zählte nur offene Tickets (Status), nicht ungelesene Nachrichten. manager_read_at
-- wird gesetzt, sobald ein Manager/Platform-Admin die Ticket-Detailseite öffnet (siehe
-- GET /portal/support/:id in webapp/public/index.php); jede Mitglieder-Nachricht, die danach
-- eintrifft (bzw. bei NULL: jede vorhandene), zählt als ungelesen.
ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS manager_read_at TIMESTAMPTZ;
