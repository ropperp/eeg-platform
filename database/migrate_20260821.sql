-- Support-Ticket-System: Mitglieder können Probleme melden oder Feature-Vorschläge machen,
-- statt dass alles per E-Mail hin- und hergeschickt wird. Manager/Platform-Admin antworten im
-- selben Thread (support_ticket_messages). Bei jedem neuen Ticket geht eine Benachrichtigung an
-- die konfigurierte Support-Adresse (platform_mail_config.support_notification_email, siehe
-- notifySupportTicketCreated() in webapp/public/index.php).

CREATE TABLE support_tickets (
    id           UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    community_id UUID NOT NULL REFERENCES communities(id),
    member_id    UUID NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    subject      TEXT NOT NULL,
    category     TEXT NOT NULL DEFAULT 'problem' CHECK (category IN ('problem', 'feature')),
    status       TEXT NOT NULL DEFAULT 'offen' CHECK (status IN ('offen', 'in_bearbeitung', 'geschlossen')),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Kein eigenes community_id/RLS hier (analog invoice_items) -- Nachrichten werden ausschließlich
-- über ticket_id gelesen/geschrieben, nachdem der aufrufende Code bereits geprüft hat, dass das
-- Ticket zur eigenen Community bzw. zum eigenen Mitgliedskonto gehört.
CREATE TABLE support_ticket_messages (
    id           UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    ticket_id    UUID NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
    author_label TEXT NOT NULL,
    is_staff     BOOLEAN NOT NULL DEFAULT false,
    message      TEXT NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX ON support_tickets (community_id, status);
CREATE INDEX ON support_ticket_messages (ticket_id, created_at);

-- FORCE zusätzlich zu ENABLE nötig, siehe Kommentar in migrate_20260714.sql -- sonst wirkungslos,
-- da die App-Verbindung als Tabellenbesitzer läuft.
ALTER TABLE support_tickets ENABLE ROW LEVEL SECURITY;
ALTER TABLE support_tickets FORCE ROW LEVEL SECURITY;
CREATE POLICY community_isolation ON support_tickets
    USING (community_id = current_setting('app.community_id', true)::uuid);

ALTER TABLE platform_mail_config ADD COLUMN IF NOT EXISTS support_notification_email TEXT NOT NULL DEFAULT 'office@stromfueralle.at';
