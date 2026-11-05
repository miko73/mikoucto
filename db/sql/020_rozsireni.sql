-- Rozšíření PostgreSQL. Jen ta, která nabízejí všichni zvažovaní poskytovatelé.
-- V Azure musí být rozšíření povolené v parametru serveru azure.extensions (01-server.ps1).

\set ON_ERROR_STOP on
SET client_min_messages = warning;

CREATE SCHEMA IF NOT EXISTS ext AUTHORIZATION mikoucto_owner;
COMMENT ON SCHEMA ext IS 'mikoucto: rozšíření PostgreSQL';
GRANT USAGE ON SCHEMA ext TO mikoucto_app, mikoucto_read;

-- digest() pro řetězení auditního logu
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA ext;
