-- Migration 2026-07-22: Microsoft-Graph-Mailversand + Mitglieder-Profilbild.
--
-- platform_mail_config: Singleton-Tabelle (genau eine Zeile, id=1), Zugangsdaten für den
-- E-Mail-Versand über Microsoft Graph (Tenant-ID/Client-ID/Client-Secret/Absenderadresse).
-- Wird ausschließlich über die Platform-Admin-Oberfläche gepflegt -- diese Werte dürfen NIE
-- im Repo landen (siehe CLAUDE.md).
CREATE TABLE IF NOT EXISTS platform_mail_config (
    id             INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    tenant_id      TEXT,
    client_id      TEXT,
    client_secret  TEXT,
    sender_address TEXT,
    updated_at     TIMESTAMPTZ DEFAULT now()
);
INSERT INTO platform_mail_config (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

-- Profilbild je Mitglied (Pfad im webapp-storage-Volume, analog member_files). NULL = noch
-- kein eigenes Bild hochgeladen -> Default-Avatar (nach Anrede) wird im Frontend verwendet.
ALTER TABLE members ADD COLUMN IF NOT EXISTS photo_path TEXT;
