-- Administratorska priprava izolovane testovaci DB na existujicim clusteru.
-- Nemeni globalni role ani jejich hesla a nikdy neodstranuje databazi.
\set ON_ERROR_STOP on
DO $$
BEGIN
    IF NOT (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
        RAISE EXCEPTION 'Priprava testovaci DB vyzaduje administratora postgres.';
    END IF;
    IF current_setting('server_version_num')::integer / 10000 <> 16 THEN
        RAISE EXCEPTION 'Testy vyzaduji PostgreSQL 16, stejne jako vyvojova DB.';
    END IF;
    IF (SELECT count(*) FROM pg_roles WHERE rolname IN ('mikoucto_owner', 'mikoucto_app', 'mikoucto_read')) <> 3 THEN
        RAISE EXCEPTION 'Nejprve inicializujte role vyvojove DB podle README.';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_database WHERE datname='mikoucto_test'
               AND (pg_get_userbyid(datdba) <> 'mikoucto_owner' OR pg_encoding_to_char(encoding) <> 'UTF8')) THEN
        RAISE EXCEPTION 'Existujici mikoucto_test ma jineho vlastnika nebo kodovani. Nebude zmenena.';
    END IF;
END $$;

-- CREATE DATABASE nesmi byt v transakci; gexec spusti prikaz jen pri absenci DB.
SELECT 'CREATE DATABASE mikoucto_test OWNER mikoucto_owner TEMPLATE template0 ENCODING ''UTF8'''
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname='mikoucto_test') \gexec

BEGIN;
REVOKE ALL ON DATABASE mikoucto_test FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE mikoucto_test TO mikoucto_app, mikoucto_read;
COMMENT ON DATABASE mikoucto_test IS 'mikoucto: vyhrazena testovaci DB, bez vyvojovych a produkcnich dat';
COMMIT;
\echo 'Testovaci databaze mikoucto_test je pripravena. Schema vytvori migrace.'
