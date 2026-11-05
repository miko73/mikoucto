-- Kontrola inicializace: role, oprávnění, audit, řetězení. Vše proběhne v transakci,
-- která se na konci vrátí (ROLLBACK), takže v databázi nic nezůstane.
-- Při chybě skript skončí výjimkou s popisem.

\set ON_ERROR_STOP on
SET client_min_messages = warning;

BEGIN;

SET LOCAL ROLE mikoucto_owner;
CREATE TABLE core._kontrola (
    id      uuid    PRIMARY KEY,
    nazev   text    NOT NULL,
    version integer NOT NULL DEFAULT 1
);
DO $$ BEGIN PERFORM core.audit_attach('core._kontrola'); END $$;
GRANT SELECT, INSERT, UPDATE ON core._kontrola TO mikoucto_app;

-- Jako aplikace: zápis s kontextem uživatele
SET LOCAL ROLE mikoucto_app;
DO $$ BEGIN PERFORM core.set_context('00000000-0000-7000-8000-000000000001', 'kontrola-bootstrap', 'kontrola'); END $$;
INSERT INTO core._kontrola (id, nazev) VALUES ('00000000-0000-7000-8000-0000000000aa', 'první');
UPDATE core._kontrola SET nazev = 'druhý', version = version + 1;
UPDATE core._kontrola SET nazev = 'druhý';   -- beze změny, nemá se zalogovat

DO $$
DECLARE
    v_count integer;
    v_cols  text[];
    v_user  uuid;
BEGIN
    SELECT count(*) INTO v_count FROM core.audit_log WHERE table_name = 'core._kontrola';
    IF v_count <> 2 THEN RAISE EXCEPTION 'Audit: očekávány 2 záznamy, nalezeno %', v_count; END IF;

    SELECT changed_cols, user_id INTO v_cols, v_user
    FROM core.audit_log WHERE table_name = 'core._kontrola' AND operation = 'U';
    IF v_cols IS DISTINCT FROM ARRAY['nazev', 'version'] THEN
        RAISE EXCEPTION 'Audit: chybné changed_cols %', v_cols;
    END IF;
    IF v_user IS DISTINCT FROM '00000000-0000-7000-8000-000000000001'::uuid THEN
        RAISE EXCEPTION 'Audit: chybný user_id %', v_user;
    END IF;

    BEGIN
        INSERT INTO core.audit_log (client, table_name, row_id, operation) VALUES ('x', 'x', 'x', 'I');
        RAISE EXCEPTION 'Aplikace nesmí zapisovat přímo do auditu.';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;

    BEGIN
        DELETE FROM core._kontrola;
        RAISE EXCEPTION 'Aplikace nesmí mazat bez výslovného oprávnění.';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
END
$$;

-- Jako vlastník: zapečetění, ověření a ochrana zapečetěných záznamů
SET LOCAL ROLE mikoucto_owner;
DO $$
DECLARE
    v record;
BEGIN
    PERFORM core.audit_seal();
    SELECT * INTO v FROM core.audit_verify();
    IF NOT v.ok THEN RAISE EXCEPTION 'Řetězení auditu je porušené od záznamu %', v.first_bad_seq; END IF;

    BEGIN
        UPDATE core.audit_log SET client = 'zmena' WHERE table_name = 'core._kontrola';
        RAISE EXCEPTION 'Zapečetěný záznam auditu se nesmí dát změnit.';
    EXCEPTION WHEN raise_exception THEN
        IF SQLERRM NOT LIKE 'Záznamy auditního logu nelze měnit%' THEN RAISE; END IF;
    END;

    IF to_regclass('core.audit_log_' || to_char(now() AT TIME ZONE 'UTC', 'YYYYMM')) IS NULL THEN
        RAISE EXCEPTION 'Chybí partition auditu pro aktuální měsíc.';
    END IF;
    IF EXISTS (SELECT 1 FROM core.audit_log_default) THEN
        RAISE EXCEPTION 'Záchytná partition core.audit_log_default není prázdná.';
    END IF;
END
$$;

ROLLBACK;
\echo '    Kontrola OK: role, oprávnění, audit, řetězení a partitions.'
