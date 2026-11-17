-- Eingeschränkte Laufzeit-Rollen (Webapp: RLS greift; Dienstkonto: BYPASSRLS, kein Superuser).
-- Aufruf nur über scripts/db_runtime_role_setup.sh bzw. tests/integration/prepare_db.sh (psql-Variablen
-- app_user, app_pass, worker_user, worker_pass, dbname). Sicher erneut ausführbar.
-- Hinweis: psql interpoliert :'var'/:"var" NICHT innerhalb von dollar-quotierten Strings
-- ($do$...$do$) -- ein ursprünglicher DO-Block-Ansatz hier scheiterte deshalb live mit einem
-- Syntaxfehler (leerer, nicht ersetzter Doppelpunkt). \gexec (Server liefert einen fertigen
-- SQL-Text als Ergebniszeile zurück, den psql anschließend ausführt) umgeht das, weil die
-- Interpolation dabei außerhalb jeder Dollar-Quotierung passiert -- live gegen echtes
-- PostgreSQL 16 getestet (14.08.2026).
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION', :'app_user', :'app_pass')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user')
\gexec

SELECT format('ALTER ROLE %I PASSWORD %L', :'app_user', :'app_pass')
WHERE EXISTS (SELECT FROM pg_roles WHERE rolname = :'app_user')
\gexec

-- Dienstkonto (BYPASSRLS, aber kein Superuser) -- gleiches \gexec-Muster wie oben.
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE BYPASSRLS NOREPLICATION', :'worker_user', :'worker_pass')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'worker_user')
\gexec

SELECT format('ALTER ROLE %I PASSWORD %L NOSUPERUSER BYPASSRLS', :'worker_user', :'worker_pass')
WHERE EXISTS (SELECT FROM pg_roles WHERE rolname = :'worker_user')
\gexec

GRANT CONNECT ON DATABASE :"dbname" TO :"app_user";
GRANT USAGE ON SCHEMA public TO :"app_user";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"app_user";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"app_user";
-- Damit künftige Migrationen (weiterhin als Schema-Besitzer ausgeführt) neue Tabellen/Sequenzen
-- automatisch für die App-Rolle freigeben, ohne dass jedes Mal an ein manuelles GRANT gedacht
-- werden muss.
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app_user";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO :"app_user";
-- Öffentlicher Unterschrifts-Link der Versammlungsprotokolle (migrate_20261119.sql): einzige
-- Funktion, die die App-Rolle ausdrücklich braucht (PUBLIC ist dort entzogen).
SELECT format('GRANT EXECUTE ON FUNCTION assembly_signer_by_token(TEXT) TO %I', :'app_user')
WHERE EXISTS (SELECT FROM pg_proc WHERE proname = 'assembly_signer_by_token')
\gexec

GRANT CONNECT ON DATABASE :"dbname" TO :"worker_user";
GRANT USAGE ON SCHEMA public TO :"worker_user";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"worker_user";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"worker_user";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"worker_user";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO :"worker_user";
