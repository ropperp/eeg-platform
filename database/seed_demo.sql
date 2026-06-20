-- Demo-Datensatz für Screenshots und Beispiele in der Diplomarbeit
-- ALLE DATEN SIND FIKTIV (Fantasienamen, keine echten IBANs, keine echten Zählpunktnummern)
-- Ausführen auf einer frischen (leeren) DB nach init.sql
-- NICHT auf der Produktions-DB ausführen!

-- Demo-EEG
INSERT INTO communities (id, name, slug, marktpartner_id, zvr_number, address, iban, bic, payment_days)
VALUES (
    'c1000000-0000-0000-0000-000000000001',
    'EEG Sonnental Musterdorf',
    'sonnental-musterdorf',
    'RC999001',
    '9876543210',
    'Musterstraße 1, 9000 Musterdorf',
    'AT12 3456 7890 1234 5678',
    'BKAUATWW',
    14
);

-- Demo-Tarif
INSERT INTO tariff_config (community_id, valid_from, bezug_ct_kwh, einspeisung_ct_kwh, mitgliedsbeitrag_eur)
VALUES ('c1000000-0000-0000-0000-000000000001', '2026-01-01', 11.50, 7.80, 24.00);

-- Demo-Steuerkonfiguration (Kleinunternehmer)
INSERT INTO tax_config (community_id, valid_from, tax_model)
VALUES ('c1000000-0000-0000-0000-000000000001', '2026-01-01', 'kleinunternehmer');

-- Demo-User: Manager
INSERT INTO users (id, email, password_hash, first_name, last_name)
VALUES (
    'u1000000-0000-0000-0000-000000000001',
    'manager@demo.local',
    '$2y$12$demoHashPlaceholderXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
    'Maria',
    'Musterfrau'
);

INSERT INTO user_roles (community_id, user_id, role)
VALUES ('c1000000-0000-0000-0000-000000000001', 'u1000000-0000-0000-0000-000000000001', 'manager');

-- Demo-User: Mitglied 1
INSERT INTO users (id, email, password_hash, first_name, last_name)
VALUES (
    'u1000000-0000-0000-0000-000000000002',
    'mitglied1@demo.local',
    '$2y$12$demoHashPlaceholderXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
    'Hans',
    'Beispiel'
);

INSERT INTO user_roles (community_id, user_id, role)
VALUES ('c1000000-0000-0000-0000-000000000001', 'u1000000-0000-0000-0000-000000000002', 'member');

-- Demo-Mitglied 1 (Bezieher)
INSERT INTO members (id, community_id, user_id, first_name, last_name, address, zip, city, email, member_since, status)
VALUES (
    'm1000000-0000-0000-0000-000000000001',
    'c1000000-0000-0000-0000-000000000001',
    'u1000000-0000-0000-0000-000000000002',
    'Hans',
    'Beispiel',
    'Demoweg 5',
    '9000',
    'Musterdorf',
    'hans.beispiel@demo.local',
    '2026-01-01',
    'active'
);

-- Demo-Zählpunkt Bezug
INSERT INTO metering_points (id, community_id, member_id, zaehlpunkt_nr, meter_code, type, active, registered_at)
VALUES (
    'mp100000-0000-0000-0000-000000000001',
    'c1000000-0000-0000-0000-000000000001',
    'm1000000-0000-0000-0000-000000000001',
    'AT0070000000000000000000000000001',
    'DEMO-001',
    'consumer',
    true,
    '2026-01-01'
);

-- Demo-Mitglied 2 (Einspeiser)
INSERT INTO users (id, email, password_hash, first_name, last_name)
VALUES (
    'u1000000-0000-0000-0000-000000000003',
    'mitglied2@demo.local',
    '$2y$12$demoHashPlaceholderXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
    'Anna',
    'Sonnenschein'
);

INSERT INTO user_roles (community_id, user_id, role)
VALUES ('c1000000-0000-0000-0000-000000000001', 'u1000000-0000-0000-0000-000000000003', 'member');

INSERT INTO members (id, community_id, user_id, first_name, last_name, address, zip, city, email, member_since, status)
VALUES (
    'm1000000-0000-0000-0000-000000000002',
    'c1000000-0000-0000-0000-000000000001',
    'u1000000-0000-0000-0000-000000000003',
    'Anna',
    'Sonnenschein',
    'Solarweg 12',
    '9000',
    'Musterdorf',
    'anna.sonnenschein@demo.local',
    '2026-01-01',
    'active'
);

-- Demo-Zählpunkt Einspeisung
INSERT INTO metering_points (id, community_id, member_id, zaehlpunkt_nr, meter_code, type, active, registered_at)
VALUES (
    'mp100000-0000-0000-0000-000000000002',
    'c1000000-0000-0000-0000-000000000001',
    'm1000000-0000-0000-0000-000000000002',
    'AT0070000000000000000000000000002',
    'DEMO-002',
    'producer',
    true,
    '2026-01-01'
);

-- Demo-EDA-Messdaten (Q1 2026, 15-Min-Werte, nur wenige Beispielzeilen)
SET LOCAL app.community_id = 'c1000000-0000-0000-0000-000000000001';

INSERT INTO eda_measurements (time, community_id, metering_point_id, meter_code, kwh_erzeugung, kwh_teilnahme, quality, completeness)
VALUES
    ('2026-01-01 00:00:00+01', 'c1000000-0000-0000-0000-000000000001', 'mp100000-0000-0000-0000-000000000001', 'DEMO-001', 0.250, 0.250, 'L3', 'COMPLETE'),
    ('2026-01-01 00:15:00+01', 'c1000000-0000-0000-0000-000000000001', 'mp100000-0000-0000-0000-000000000001', 'DEMO-001', 0.280, 0.280, 'L3', 'COMPLETE'),
    ('2026-01-01 00:00:00+01', 'c1000000-0000-0000-0000-000000000001', 'mp100000-0000-0000-0000-000000000002', 'DEMO-002', 0.000, 0.000, 'L3', 'COMPLETE'),
    ('2026-01-01 12:00:00+01', 'c1000000-0000-0000-0000-000000000001', 'mp100000-0000-0000-0000-000000000002', 'DEMO-002', 1.250, 1.250, 'L3', 'COMPLETE');

-- Demo-EDA-Import-Protokoll
INSERT INTO eda_imports (community_id, imported_by, filename, period_from, period_to, records_imported, status)
VALUES (
    'c1000000-0000-0000-0000-000000000001',
    'u1000000-0000-0000-0000-000000000001',
    'RC999001_2026-01-01T00_00-2026-03-31T23_45.xlsx',
    '2026-01-01 00:00:00+01',
    '2026-03-31 23:45:00+01',
    17376,
    'ok'
);
