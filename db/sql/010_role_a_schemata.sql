-- Databázové role, oprávnění k databázi a schéma core.
-- Spouští administrátor serveru v cílové databázi:
--   psql -v db_name=mikoucto -v owner_pwd=... -v app_pwd=... -v read_pwd=... -1 -f 010_role_a_schemata.sql
-- Skript je idempotentní; při opakování nastaví hesla podle předaných proměnných.

\set ON_ERROR_STOP on
SET client_min_messages = warning;

-- Role -----------------------------------------------------------------------

SELECT NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mikoucto_owner') AS create_owner \gset
\if :create_owner
    CREATE ROLE mikoucto_owner LOGIN PASSWORD :'owner_pwd';
\else
    ALTER ROLE mikoucto_owner LOGIN PASSWORD :'owner_pwd';
\endif

SELECT NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mikoucto_app') AS create_app \gset
\if :create_app
    CREATE ROLE mikoucto_app LOGIN PASSWORD :'app_pwd';
\else
    ALTER ROLE mikoucto_app LOGIN PASSWORD :'app_pwd';
\endif

SELECT NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'mikoucto_read') AS create_read \gset
\if :create_read
    CREATE ROLE mikoucto_read LOGIN PASSWORD :'read_pwd';
\else
    ALTER ROLE mikoucto_read LOGIN PASSWORD :'read_pwd';
\endif

COMMENT ON ROLE mikoucto_owner IS 'mikoucto: vlastník schémat, jen pro migrace';
COMMENT ON ROLE mikoucto_app   IS 'mikoucto: backend API';
COMMENT ON ROLE mikoucto_read  IS 'mikoucto: pouze čtení (reporty, Fabric/Power BI)';

-- Administrátor serveru smí jednat za vlastníka (migrace, údržba) a přepnout se
-- do ostatních rolí pro kontrolu oprávnění (090_kontrola.sql).
GRANT mikoucto_owner TO CURRENT_USER WITH INHERIT TRUE, SET TRUE;
GRANT mikoucto_app, mikoucto_read TO CURRENT_USER WITH INHERIT FALSE, SET TRUE;

-- Výchozí nastavení relací: ochrana proti dlouhým a visícím transakcím.
ALTER ROLE mikoucto_app  SET statement_timeout = '30s';
ALTER ROLE mikoucto_app  SET lock_timeout = '10s';
ALTER ROLE mikoucto_app  SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE mikoucto_read SET default_transaction_read_only = on;
ALTER ROLE mikoucto_read SET statement_timeout = '120s';
ALTER ROLE mikoucto_owner SET lock_timeout = '30s';

-- Databáze --------------------------------------------------------------------

ALTER DATABASE :"db_name" OWNER TO mikoucto_owner;
REVOKE ALL ON DATABASE :"db_name" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db_name" TO mikoucto_app, mikoucto_read;

-- Schéma public se nepoužívá; aplikace pracuje jen ve vlastních schématech.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

-- Výchozí oprávnění pro objekty, které vlastník vytvoří (migrace) --------------
-- DELETE se plošně nepovoluje; migrace jej udělí jen tabulkám, kde je mazání přípustné.

ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner
    GRANT USAGE ON SCHEMAS TO mikoucto_app, mikoucto_read;
ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner
    GRANT SELECT, INSERT, UPDATE ON TABLES TO mikoucto_app;
ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner
    GRANT SELECT ON TABLES TO mikoucto_read;
ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner
    GRANT USAGE, SELECT ON SEQUENCES TO mikoucto_app;
ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner
    REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;

-- Schéma core -------------------------------------------------------------------

CREATE SCHEMA IF NOT EXISTS core AUTHORIZATION mikoucto_owner;
COMMENT ON SCHEMA core IS 'mikoucto: jádro (uživatelé, role, audit, export a import)';
GRANT USAGE ON SCHEMA core TO mikoucto_app, mikoucto_read;

ALTER ROLE mikoucto_app   SET search_path = core, public;
ALTER ROLE mikoucto_read  SET search_path = core, public;
ALTER ROLE mikoucto_owner SET search_path = core, public;
