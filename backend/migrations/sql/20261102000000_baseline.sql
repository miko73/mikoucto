DO $mikoucto_baseline$
BEGIN
-- Nemenny snapshot prvni migrace, odvozeny z db/sql/010-030 dne 2. 11. 2026.
-- Jen databazove objekty; globalni role a hesla spravuje instalace clusteru.
CREATE SCHEMA core AUTHORIZATION mikoucto_owner;
CREATE SCHEMA ext AUTHORIZATION mikoucto_owner;
GRANT USAGE ON SCHEMA core, ext TO mikoucto_app, mikoucto_read;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner GRANT USAGE ON SCHEMAS TO mikoucto_app, mikoucto_read;
ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner GRANT SELECT, INSERT, UPDATE ON TABLES TO mikoucto_app;
ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner GRANT SELECT ON TABLES TO mikoucto_read;
ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner GRANT USAGE, SELECT ON SEQUENCES TO mikoucto_app;
ALTER DEFAULT PRIVILEGES FOR ROLE mikoucto_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
CREATE EXTENSION pgcrypto WITH SCHEMA ext;
-- Auditní log změn dat (viz analyza/techstack_navrh.md, kap. 7).
--
-- Použití z backendu na začátku každé zapisující transakce:
--   SELECT core.set_context(:user_id, :request_id, 'web');
-- Připojení tabulky k auditu (v migraci, jako vlastník):
--   SELECT core.audit_attach('ucto.zapis');            -- klíč ve sloupci id
--   SELECT core.audit_attach('core.nastaveni', 'kod');  -- jiný klíčový sloupec
-- Pravidelná údržba (např. denně z backendu):
--   PERFORM core.audit_ensure_partitions(3);   -- partitions na 3 měsíce dopředu
--   SELECT core.audit_seal();                 -- doplní řetězení kontrolních součtů
--   SELECT * FROM core.audit_verify();        -- ověří neporušenost řetězce

SET client_min_messages = warning;


-- Tabulky -----------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS core.audit_log (
    id            bigint GENERATED ALWAYS AS IDENTITY,
    occurred_at   timestamptz NOT NULL DEFAULT clock_timestamp(),
    tx_id         bigint      NOT NULL DEFAULT txid_current(),
    request_id    text,
    user_id       uuid,
    client        text        NOT NULL,
    table_name    text        NOT NULL,
    row_id        text        NOT NULL,
    operation     char(1)     NOT NULL CHECK (operation IN ('I', 'U', 'D')),
    old_data      jsonb,
    new_data      jsonb,
    changed_cols  text[],
    sealed_seq    bigint,
    prev_hash     bytea,
    row_hash      bytea,
    PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);

COMMENT ON TABLE  core.audit_log IS 'Auditní log změn řádků; zapisuje jen trigger core.audit_trigger()';
COMMENT ON COLUMN core.audit_log.client     IS 'web / python / import / system; bez kontextu db:<přihlášená role>';
COMMENT ON COLUMN core.audit_log.sealed_seq IS 'Pořadí v řetězci kontrolních součtů (core.audit_seal)';

-- Záchytná partition pro případ, že by chyběla měsíční. Má zůstat prázdná.
CREATE TABLE IF NOT EXISTS core.audit_log_default PARTITION OF core.audit_log DEFAULT;

CREATE INDEX IF NOT EXISTS audit_log_row_idx      ON core.audit_log (table_name, row_id, occurred_at);
CREATE INDEX IF NOT EXISTS audit_log_user_idx     ON core.audit_log (user_id, occurred_at);
CREATE INDEX IF NOT EXISTS audit_log_tx_idx       ON core.audit_log (tx_id);
CREATE INDEX IF NOT EXISTS audit_log_unsealed_idx ON core.audit_log (occurred_at, id) WHERE row_hash IS NULL;
CREATE INDEX IF NOT EXISTS audit_log_sealed_idx   ON core.audit_log (sealed_seq) WHERE sealed_seq IS NOT NULL;

CREATE TABLE IF NOT EXISTS core.audit_seal_state (
    id         integer PRIMARY KEY CHECK (id = 1),
    last_seq   bigint  NOT NULL,
    last_hash  bytea   NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO core.audit_seal_state (id, last_seq, last_hash)
VALUES (1, 0, ext.digest('mikoucto-audit-genesis', 'sha256'))
ON CONFLICT (id) DO NOTHING;

-- Kontext relace ------------------------------------------------------------------

CREATE OR REPLACE FUNCTION core.set_context(p_user_id uuid, p_request_id text DEFAULT NULL, p_client text DEFAULT NULL)
RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
    -- Hodnoty platí jen do konce aktuální transakce.
    PERFORM set_config('app.user_id',    coalesce(p_user_id::text, ''), true);
    PERFORM set_config('app.request_id', coalesce(p_request_id, ''),    true);
    PERFORM set_config('app.client',     coalesce(p_client, ''),        true);
END
$$;

-- Trigger auditu --------------------------------------------------------------------

CREATE OR REPLACE FUNCTION core.audit_trigger()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    v_key  text := coalesce(TG_ARGV[0], 'id');
    v_old  jsonb;
    v_new  jsonb;
    v_cols text[];
    v_user text := nullif(current_setting('app.user_id', true), '');
BEGIN
    -- Backend musí v každé transakci nastavit, kdo změnu provádí.
    IF v_user IS NULL AND session_user = 'mikoucto_app' THEN
        RAISE EXCEPTION 'Chybí kontext uživatele: zavolejte core.set_context() na začátku transakce.';
    END IF;

    IF TG_OP IN ('UPDATE', 'DELETE') THEN v_old := to_jsonb(OLD); END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN v_new := to_jsonb(NEW); END IF;

    IF TG_OP = 'UPDATE' THEN
        SELECT array_agg(n.key ORDER BY n.key) INTO v_cols
        FROM jsonb_each(v_new) AS n
        WHERE n.value IS DISTINCT FROM v_old -> n.key;
        IF v_cols IS NULL THEN
            RETURN NULL;   -- zápis bez věcné změny se neloguje
        END IF;
    END IF;

    INSERT INTO core.audit_log (request_id, user_id, client, table_name, row_id,
                                operation, old_data, new_data, changed_cols)
    VALUES (nullif(current_setting('app.request_id', true), ''),
            v_user::uuid,
            coalesce(nullif(current_setting('app.client', true), ''), 'db:' || session_user),
            TG_TABLE_SCHEMA || '.' || TG_TABLE_NAME,
            coalesce(v_new ->> v_key, v_old ->> v_key),
            left(TG_OP, 1), v_old, v_new, v_cols);
    RETURN NULL;
END
$$;

CREATE OR REPLACE FUNCTION core.audit_block_truncate()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'TRUNCATE tabulky %.% není povolen (auditovaná tabulka).', TG_TABLE_SCHEMA, TG_TABLE_NAME;
END
$$;

CREATE OR REPLACE FUNCTION core.audit_attach(p_table regclass, p_key_column text DEFAULT 'id')
RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_attribute
                   WHERE attrelid = p_table AND attname = p_key_column AND NOT attisdropped) THEN
        RAISE EXCEPTION 'Tabulka % nemá sloupec %.', p_table, p_key_column;
    END IF;
    EXECUTE format('DROP TRIGGER IF EXISTS zz_audit ON %s', p_table);
    EXECUTE format('CREATE TRIGGER zz_audit AFTER INSERT OR UPDATE OR DELETE ON %s
                    FOR EACH ROW EXECUTE FUNCTION core.audit_trigger(%L)', p_table, p_key_column);
    EXECUTE format('DROP TRIGGER IF EXISTS zz_audit_truncate ON %s', p_table);
    EXECUTE format('CREATE TRIGGER zz_audit_truncate BEFORE TRUNCATE ON %s
                    FOR EACH STATEMENT EXECUTE FUNCTION core.audit_block_truncate()', p_table);
END
$$;

-- Ochrana samotného logu: mazat nelze, měnit lze jen jednou při zapečetění. ---------

CREATE OR REPLACE FUNCTION core.audit_log_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    v_seal_cols text[] := ARRAY['sealed_seq', 'prev_hash', 'row_hash'];
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Záznamy auditního logu nelze mazat.';
    END IF;
    IF OLD.row_hash IS NOT NULL
       OR (to_jsonb(NEW) - v_seal_cols) IS DISTINCT FROM (to_jsonb(OLD) - v_seal_cols) THEN
        RAISE EXCEPTION 'Záznamy auditního logu nelze měnit.';
    END IF;
    RETURN NEW;
END
$$;

DROP TRIGGER IF EXISTS audit_log_guard ON core.audit_log;
CREATE TRIGGER audit_log_guard BEFORE UPDATE OR DELETE ON core.audit_log
    FOR EACH ROW EXECUTE FUNCTION core.audit_log_guard();

DROP TRIGGER IF EXISTS audit_log_truncate ON core.audit_log;
CREATE TRIGGER audit_log_truncate BEFORE TRUNCATE ON core.audit_log
    FOR EACH STATEMENT EXECUTE FUNCTION core.audit_block_truncate();

-- Měsíční partitions ----------------------------------------------------------------

CREATE OR REPLACE FUNCTION core.audit_ensure_partitions(p_months_ahead integer DEFAULT 3)
RETURNS integer
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    v_month   timestamp;
    v_name    text;
    v_created integer := 0;
BEGIN
    FOR i IN 0..p_months_ahead LOOP
        v_month := date_trunc('month', now() AT TIME ZONE 'UTC') + make_interval(months => i);
        v_name  := 'audit_log_' || to_char(v_month, 'YYYYMM');
        IF to_regclass('core.' || v_name) IS NULL THEN
            EXECUTE format('CREATE TABLE core.%I PARTITION OF core.audit_log FOR VALUES FROM (%L) TO (%L)',
                           v_name, v_month AT TIME ZONE 'UTC', (v_month + interval '1 month') AT TIME ZONE 'UTC');
            -- Partition nesmí převzít výchozí oprávnění; přístup jen přes nadřazenou tabulku.
            EXECUTE format('REVOKE ALL ON core.%I FROM PUBLIC, mikoucto_app, mikoucto_read', v_name);
            v_created := v_created + 1;
        END IF;
    END LOOP;
    RETURN v_created;
END
$$;

-- Řetězení kontrolních součtů -------------------------------------------------------

CREATE OR REPLACE FUNCTION core.audit_row_digest(p_prev bytea, r core.audit_log)
RETURNS bytea
LANGUAGE sql
STABLE
AS $$
    SELECT ext.digest(
        p_prev || convert_to(jsonb_build_array(
            r.id, extract(epoch FROM r.occurred_at)::text, r.tx_id, r.request_id, r.user_id, r.client,
            r.table_name, r.row_id, r.operation, r.old_data, r.new_data)::text, 'UTF8'),
        'sha256')
$$;

CREATE OR REPLACE FUNCTION core.audit_seal(p_limit integer DEFAULT 10000)
RETURNS integer
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    r      core.audit_log;
    v_seq  bigint;
    v_hash bytea;
    v_prev bytea;
    v_n    integer := 0;
BEGIN
    SELECT last_seq, last_hash INTO v_seq, v_hash
    FROM core.audit_seal_state WHERE id = 1 FOR UPDATE;

    FOR r IN SELECT * FROM core.audit_log
             WHERE row_hash IS NULL
             ORDER BY occurred_at, id
             LIMIT p_limit
    LOOP
        v_prev := v_hash;
        v_seq  := v_seq + 1;
        v_hash := core.audit_row_digest(v_prev, r);
        UPDATE core.audit_log
           SET sealed_seq = v_seq, prev_hash = v_prev, row_hash = v_hash
         WHERE id = r.id AND occurred_at = r.occurred_at;
        v_n := v_n + 1;
    END LOOP;

    IF v_n > 0 THEN
        UPDATE core.audit_seal_state SET last_seq = v_seq, last_hash = v_hash, updated_at = now() WHERE id = 1;
    END IF;
    RETURN v_n;
END
$$;

CREATE OR REPLACE FUNCTION core.audit_verify()
RETURNS TABLE (sealed_rows bigint, ok boolean, first_bad_seq bigint)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
    r         core.audit_log;
    v_hash    bytea := ext.digest('mikoucto-audit-genesis', 'sha256');
    v_expect  bigint := 0;
BEGIN
    sealed_rows := 0; ok := true; first_bad_seq := NULL;
    FOR r IN SELECT * FROM core.audit_log WHERE sealed_seq IS NOT NULL ORDER BY sealed_seq LOOP
        v_expect := v_expect + 1;
        IF r.sealed_seq <> v_expect
           OR r.prev_hash IS DISTINCT FROM v_hash
           OR r.row_hash IS DISTINCT FROM core.audit_row_digest(v_hash, r) THEN
            ok := false; first_bad_seq := v_expect;
            sealed_rows := v_expect - 1;
            RETURN NEXT;
            RETURN;
        END IF;
        v_hash := r.row_hash;
        sealed_rows := v_expect;
    END LOOP;
    IF v_hash IS DISTINCT FROM (SELECT last_hash FROM core.audit_seal_state WHERE id = 1) THEN
        ok := false; first_bad_seq := v_expect + 1;
    END IF;
    RETURN NEXT;
END
$$;

-- Oprávnění ---------------------------------------------------------------------------

-- Výchozí oprávnění by aplikaci dovolila do logu zapisovat; zápis jde jen přes trigger.
REVOKE ALL ON core.audit_log, core.audit_log_default, core.audit_seal_state FROM PUBLIC, mikoucto_app, mikoucto_read;
GRANT SELECT ON core.audit_log TO mikoucto_app, mikoucto_read;

REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA core FROM PUBLIC;
GRANT EXECUTE ON FUNCTION core.set_context(uuid, text, text)         TO mikoucto_app;
GRANT EXECUTE ON FUNCTION core.audit_ensure_partitions(integer)      TO mikoucto_app;
GRANT EXECUTE ON FUNCTION core.audit_seal(integer)                   TO mikoucto_app;
GRANT EXECUTE ON FUNCTION core.audit_verify()                        TO mikoucto_app, mikoucto_read;

PERFORM core.audit_ensure_partitions(3);

END
$mikoucto_baseline$;