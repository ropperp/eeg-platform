-- Eigene Datenbank und Rolle für Keycloak (docs/adr/0007-keycloak.md), aufgerufen von
-- scripts/keycloak_setup.sh und tests/e2e/keycloak_run.sh. Variable: kc_pass. Idempotent.
-- Die Rolle ist kein Superuser und besitzt nur die Datenbank "keycloak"; auf die Plattform-Daten
-- hat sie keinen Zugriff.
SELECT format('CREATE ROLE keycloak LOGIN PASSWORD %L', :'kc_pass')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'keycloak') \gexec
ALTER ROLE keycloak WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD :'kc_pass';
SELECT 'CREATE DATABASE keycloak OWNER keycloak'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'keycloak') \gexec
REVOKE ALL ON DATABASE keycloak FROM PUBLIC;
GRANT CONNECT ON DATABASE keycloak TO keycloak;
