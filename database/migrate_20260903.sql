-- Migration 2026-09-03: Push-Benachrichtigungen (APNs) für die Mitglieder-App.
--
-- Drei Auslöser (Patrick, 19.08.2026):
-- 1. Obmann/Admin: neues Postfach-Element (notifications-Tabelle).
-- 2. Mitglied: neue Rechnung verfügbar (invoices.sent_at wird gesetzt).
-- 3. Mitglied: eigene Einspeisung übersteigt eine selbst festgelegte Schwelle, MIT Hysterese
--    (nicht bei jeder 5s-Messung erneut, sondern nur beim (Wieder-)Überschreiten NACH einem
--    zwischenzeitlichen Unterschreiten -- klassische Zwei-Zustands-Hysterese).
--
-- Architektur: statt jeden PHP-/Python-Aufrufer einzeln anzupassen (webapp UND mqtt-subscriber
-- schreiben beide in notifications bzw. esp_measurements, unterschiedliche Sprachen), übernehmen
-- Datenbank-TRIGGER die Entscheidung "hier muss eine Push-Benachrichtigung raus" direkt an der
-- Quelle -- ein einziger Ort pro Auslöser, unabhängig davon, aus welchem Code-Pfad die
-- eigentliche Zeile eingefügt wurde (auch künftige neue Aufrufer brauchen dann nichts extra zu
-- tun). Die eigentliche Zustellung an Apple (APNs, HTTP/2 + JWT) passiert bewusst GETRENNT
-- (siehe webapp/src/Push.php + scripts/send_pending_push.php, Host-Cron) -- Trigger schreiben
-- nur in die Warteschlange (push_notifications_queue), sie sprechen nicht selbst mit dem
-- Internet (Datenbank-Funktionen sollen keine Netzwerk-Aufrufe machen).

-- ─── APNs-Konfiguration (Singleton, wie platform_mail_config) ─────────────────
CREATE TABLE IF NOT EXISTS platform_apns_config (
    id              INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    team_id         TEXT,
    key_id          TEXT,
    bundle_id       TEXT,
    private_key_enc TEXT,   -- .p8-Auth-Key-Inhalt, verschlüsselt wie andere Secrets (encryptSecret())
    sandbox         BOOLEAN NOT NULL DEFAULT false,
    updated_at      TIMESTAMPTZ DEFAULT now()
);
INSERT INTO platform_apns_config (id) VALUES (1) ON CONFLICT DO NOTHING;

-- ─── Geräte-Push-Token ─────────────────────────────────────────────────────
-- Kein RLS -- analog member_api_keys/app_sessions (migrate_20260731.sql/20260830.sql):
-- Zugriff ausschließlich über den eigenen Auth-Kontext (user_id aus dem Access-Token) in der
-- Anwendung selbst, nicht über eine Community gescoped (ein Account kann für mehrere Rollen
-- gleichzeitig Push-Token registriert haben).
CREATE TABLE IF NOT EXISTS app_push_tokens (
    id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id       UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role          TEXT NOT NULL CHECK (role IN ('member', 'manager', 'admin')),
    device_token  TEXT NOT NULL UNIQUE,
    device_label  TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_seen_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at    TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_app_push_tokens_user ON app_push_tokens(user_id) WHERE revoked_at IS NULL;

-- ─── Benachrichtigungs-Einstellungen je Mitglied ───────────────────────────
CREATE TABLE IF NOT EXISTS member_notification_settings (
    member_id                    UUID PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    notify_new_invoice           BOOLEAN NOT NULL DEFAULT true,
    einspeisung_threshold_w      INTEGER,                          -- NULL = deaktiviert
    einspeisung_above_threshold  BOOLEAN NOT NULL DEFAULT false,    -- Hysterese-Zustand
    einspeisung_last_notified_at TIMESTAMPTZ,
    updated_at                   TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ─── Warteschlange ausstehender Push-Benachrichtigungen ────────────────────
CREATE TABLE IF NOT EXISTS push_notifications_queue (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role        TEXT,     -- optional: nur an Geräte mit dieser Rolle zustellen (NULL = alle Rollen dieses Accounts)
    title       TEXT NOT NULL,
    body        TEXT NOT NULL,
    data        JSONB,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    sent_at     TIMESTAMPTZ,
    error       TEXT
);
CREATE INDEX IF NOT EXISTS idx_push_queue_pending ON push_notifications_queue(created_at) WHERE sent_at IS NULL;

-- ─── Trigger 1: Postfach -- neue notifications-Zeile → Push an Obmänner/Admins der Community ──
CREATE OR REPLACE FUNCTION push_on_new_notification() RETURNS TRIGGER AS $$
BEGIN
    INSERT INTO push_notifications_queue (user_id, role, title, body, data)
    SELECT DISTINCT ur.user_id, NULL, NEW.titel,
           COALESCE(NEW.text, ''),
           jsonb_build_object('type', 'postfach', 'notification_id', NEW.id, 'community_id', NEW.community_id)
    FROM user_roles ur
    WHERE ur.role IN ('manager', 'platform_admin')
      AND (ur.community_id = NEW.community_id OR ur.role = 'platform_admin');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_push_on_new_notification ON notifications;
CREATE TRIGGER trg_push_on_new_notification
    AFTER INSERT ON notifications
    FOR EACH ROW EXECUTE FUNCTION push_on_new_notification();

-- ─── Trigger 2: Rechnung versendet → Push ans Mitglied ─────────────────────
CREATE OR REPLACE FUNCTION push_on_invoice_sent() RETURNS TRIGGER AS $$
DECLARE
    v_user_id UUID;
    v_notify  BOOLEAN;
BEGIN
    IF NEW.sent_at IS NOT NULL AND OLD.sent_at IS NULL THEN
        SELECT m.user_id, COALESCE(mns.notify_new_invoice, true)
          INTO v_user_id, v_notify
          FROM members m
          LEFT JOIN member_notification_settings mns ON mns.member_id = m.id
         WHERE m.id = NEW.member_id;
        IF v_user_id IS NOT NULL AND v_notify THEN
            INSERT INTO push_notifications_queue (user_id, role, title, body, data)
            VALUES (v_user_id, 'member', 'Neue Rechnung verfügbar',
                    'Rechnung ' || NEW.rechnungsnummer || ' wurde erstellt.',
                    jsonb_build_object('type', 'invoice', 'invoice_id', NEW.id));
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_push_on_invoice_sent ON invoices;
CREATE TRIGGER trg_push_on_invoice_sent
    AFTER UPDATE OF sent_at ON invoices
    FOR EACH ROW EXECUTE FUNCTION push_on_invoice_sent();

-- ─── Trigger 3: eigene Einspeisung übersteigt die selbst gesetzte Schwelle (mit Hysterese) ──
-- Läuft bei JEDER esp_measurements-Zeile -- die frühen RETURN-Abbrüche (kein Einspeisewert,
-- kein Einspeise-/Prosumer-Zählpunkt, keine gesetzte Schwelle) sorgen dafür, dass die
-- überwiegende Mehrheit der Zeilen (Bezugs-Zählpunkte, Mitglieder ohne gesetzte Schwelle) sehr
-- billig übersprungen wird, bevor irgendein UPDATE versucht wird -- vertretbarer Zusatzaufwand
-- bei den in dieser Größenordnung üblichen Zählpunkt-Zahlen und dem 5s-Sende-Intervall.
CREATE OR REPLACE FUNCTION push_on_einspeisung_threshold() RETURNS TRIGGER AS $$
DECLARE
    v_member_id UUID;
    v_mp_type   TEXT;
    v_threshold INTEGER;
    v_above     BOOLEAN;
BEGIN
    IF NEW.power_einspeisung_w IS NULL OR NEW.power_einspeisung_w <= 0 THEN
        RETURN NEW;
    END IF;
    SELECT mp.member_id, mp.type INTO v_member_id, v_mp_type
      FROM metering_points mp WHERE mp.id = NEW.metering_point_id;
    IF v_member_id IS NULL OR v_mp_type NOT IN ('producer', 'prosumer') THEN
        RETURN NEW;
    END IF;

    SELECT einspeisung_threshold_w, einspeisung_above_threshold
      INTO v_threshold, v_above
      FROM member_notification_settings WHERE member_id = v_member_id;
    IF v_threshold IS NULL THEN
        RETURN NEW;
    END IF;

    IF NEW.power_einspeisung_w > v_threshold THEN
        IF NOT COALESCE(v_above, false) THEN
            -- Steigende Flanke: Schwelle war zuletzt NICHT überschritten, jetzt schon -> genau
            -- EINE Benachrichtigung, keine weitere, solange es oben bleibt (Hysterese).
            UPDATE member_notification_settings
               SET einspeisung_above_threshold = true, einspeisung_last_notified_at = now()
             WHERE member_id = v_member_id;
            INSERT INTO push_notifications_queue (user_id, role, title, body, data)
            SELECT m.user_id, 'member', 'Hohe Einspeisung',
                   'Du speist gerade ' || NEW.power_einspeisung_w || ' W ein -- jetzt wäre ein guter Moment zum Verbrauchen.',
                   jsonb_build_object('type', 'einspeisung_threshold', 'metering_point_id', NEW.metering_point_id, 'power_w', NEW.power_einspeisung_w)
            FROM members m WHERE m.id = v_member_id AND m.user_id IS NOT NULL;
        END IF;
    ELSE
        IF COALESCE(v_above, false) THEN
            -- Fallende Flanke: unter die Schwelle zurückgefallen -> stumm scharf stellen, KEINE
            -- Benachrichtigung (erst die nächste erneute Überschreitung löst wieder aus).
            UPDATE member_notification_settings
               SET einspeisung_above_threshold = false
             WHERE member_id = v_member_id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_push_on_einspeisung_threshold ON esp_measurements;
CREATE TRIGGER trg_push_on_einspeisung_threshold
    AFTER INSERT ON esp_measurements
    FOR EACH ROW EXECUTE FUNCTION push_on_einspeisung_threshold();
