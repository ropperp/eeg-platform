-- Testdaten für tests/e2e (NUR Wegwerf-Datenbank). Passwort aller Konten: E2e-Test-123!
-- (reines Testkonto, existiert nirgends produktiv). Community A = Pilot-EEG aus init.sql (RC108175).
INSERT INTO communities (id, name, slug, marktpartner_id) VALUES
  ('e2e00000-0000-0000-0000-00000000000b', 'E2E Fremd-EEG', 'e2e-fremd', 'RC999998') ON CONFLICT DO NOTHING;

INSERT INTO members (id, community_id, first_name, last_name, address, zip, city, email, status) VALUES
  ('e2e00000-0000-0000-0000-0000000000a1', 'a0000000-0000-0000-0000-000000000001', 'Mia', 'Mitglied', 'Weg 1', '9560', 'Feldkirchen', 'e2e-member@example.invalid', 'active'),
  ('e2e00000-0000-0000-0000-0000000000b1', 'e2e00000-0000-0000-0000-00000000000b', 'Fritz', 'Fremd', 'Weg 2', '9020', 'Klagenfurt', 'e2e-fremd@example.invalid', 'active')
  ON CONFLICT DO NOTHING;

INSERT INTO users (id, email, password_hash, first_name, last_name, last_active_community_id, last_active_role, last_active_member_id, totp_enabled, totp_secret) VALUES
  ('e2e00000-0000-0000-0000-0000000000f1', 'e2e-manager@example.invalid', '$argon2id$v=19$m=65536,t=4,p=1$MDkxTTF1V05mUzNvWi4vTA$PZL0kvrkotjTfclTS92UmqwIODAQ15bUpCGCdT43dnc', 'Otto', 'Obmann', 'a0000000-0000-0000-0000-000000000001', 'manager', NULL, false, NULL),
  ('e2e00000-0000-0000-0000-0000000000f2', 'e2e-member@example.invalid',  '$argon2id$v=19$m=65536,t=4,p=1$MDkxTTF1V05mUzNvWi4vTA$PZL0kvrkotjTfclTS92UmqwIODAQ15bUpCGCdT43dnc', 'Mia', 'Mitglied', 'a0000000-0000-0000-0000-000000000001', 'member', 'e2e00000-0000-0000-0000-0000000000a1', false, NULL),
  ('e2e00000-0000-0000-0000-0000000000f3', 'e2e-admin@example.invalid',   '$argon2id$v=19$m=65536,t=4,p=1$MDkxTTF1V05mUzNvWi4vTA$PZL0kvrkotjTfclTS92UmqwIODAQ15bUpCGCdT43dnc', 'Ada', 'Admin', NULL, 'platform_admin', NULL, false, NULL),
  ('e2e00000-0000-0000-0000-0000000000f4', 'e2e-totp@example.invalid',    '$argon2id$v=19$m=65536,t=4,p=1$MDkxTTF1V05mUzNvWi4vTA$PZL0kvrkotjTfclTS92UmqwIODAQ15bUpCGCdT43dnc', 'Tom', 'Totp', 'a0000000-0000-0000-0000-000000000001', 'manager', NULL, true, 'JBSWY3DPEHPK3PXP')
  ON CONFLICT DO NOTHING;

INSERT INTO user_roles (user_id, community_id, role, member_id) VALUES
  ('e2e00000-0000-0000-0000-0000000000f1', 'a0000000-0000-0000-0000-000000000001', 'manager', NULL),
  ('e2e00000-0000-0000-0000-0000000000f2', 'a0000000-0000-0000-0000-000000000001', 'member', 'e2e00000-0000-0000-0000-0000000000a1'),
  ('e2e00000-0000-0000-0000-0000000000f3', NULL, 'platform_admin', NULL),
  ('e2e00000-0000-0000-0000-0000000000f4', 'a0000000-0000-0000-0000-000000000001', 'manager', NULL);

-- Ticket der fremden EEG (darf für Mitglied/Obmann der Pilot-EEG nie sichtbar sein)
INSERT INTO support_tickets (id, community_id, member_id, subject, category) VALUES
  ('e2e00000-0000-0000-0000-0000000000c9', 'e2e00000-0000-0000-0000-00000000000b', 'e2e00000-0000-0000-0000-0000000000b1', 'Fremdes Ticket', 'problem')
  ON CONFLICT DO NOTHING;

-- Glas-Design wie auf dem Produktivserver: die Login-Tests laufen damit gegen login_glass.php
-- (Vorfall 17.11.2026: dort fehlte der Passkey-Button, im klassischen Design nicht).
UPDATE platform_settings SET portal_design = 'glas' WHERE id = 1;
