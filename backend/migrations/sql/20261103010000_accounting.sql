DO $ucto_migration$
BEGIN
-- btree_gist is a trusted extension on PostgreSQL 16. Installation is performed
-- by the migration owner, never by the runtime application role.
CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA ext;
CREATE SCHEMA ucto AUTHORIZATION mikoucto_owner;
REVOKE ALL ON SCHEMA ucto FROM PUBLIC;
GRANT USAGE ON SCHEMA ucto TO mikoucto_app,mikoucto_read;

INSERT INTO core.permissions(code,name) VALUES
 ('core.ucto.accounts.read','Cist ucty'), ('core.ucto.accounts.write','Spravovat ucty'), ('core.ucto.accounts.history','Historie uctu'),
 ('core.ucto.periods.read','Cist obdobi'), ('core.ucto.periods.write','Spravovat obdobi'), ('core.ucto.periods.history','Historie obdobi');
INSERT INTO core.role_permissions(role_code,permission_code)
 SELECT r.code,p.code FROM core.roles r CROSS JOIN core.permissions p
 WHERE p.code LIKE 'core.ucto.%' AND
 (r.code='administrator' OR (r.code IN ('accountant','auditor','reader') AND p.code LIKE '%.read')
 OR (r.code='auditor' AND p.code LIKE '%.history'));

CREATE TABLE ucto.accounts (
 id uuid PRIMARY KEY,
 accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id) ON DELETE RESTRICT,
 code varchar(20) NOT NULL CHECK (code ~ '^[0-9]{1,20}$'),
 name text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 200),
 active boolean NOT NULL DEFAULT true, postable boolean NOT NULL DEFAULT true,
 parent_id uuid,
 version integer NOT NULL DEFAULT 1 CHECK (version>0),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(accounting_unit_id,id), CONSTRAINT accounts_code_unique UNIQUE(accounting_unit_id,code),
 CONSTRAINT accounts_parent_fk FOREIGN KEY(accounting_unit_id,parent_id) REFERENCES ucto.accounts(accounting_unit_id,id) ON DELETE RESTRICT,
 CONSTRAINT accounts_not_own_parent CHECK(parent_id IS DISTINCT FROM id)
);
CREATE INDEX accounts_parent_idx ON ucto.accounts(accounting_unit_id,parent_id);
CREATE INDEX accounts_name_idx ON ucto.accounts(accounting_unit_id,name,id);

-- Explicit operator classes make this independent of extension search_path.
DO $$
DECLARE extension_schema text;
BEGIN
 SELECT n.nspname INTO extension_schema FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace WHERE e.extname='btree_gist';
 EXECUTE format($sql$
 CREATE TABLE ucto.periods (
  id uuid PRIMARY KEY,
  accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id) ON DELETE RESTRICT,
  name text NOT NULL CHECK(length(btrim(name)) BETWEEN 1 AND 200),
  starts_on date NOT NULL, ends_on date NOT NULL,
  status text NOT NULL DEFAULT 'open' CHECK(status='open'),
  version integer NOT NULL DEFAULT 1 CHECK(version>0),
  created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  UNIQUE(accounting_unit_id,id),
  CONSTRAINT periods_valid_dates CHECK(starts_on<=ends_on AND isfinite(starts_on) AND isfinite(ends_on)
    AND starts_on>=DATE '0001-01-01' AND ends_on<=DATE '9999-12-31'),
  CONSTRAINT periods_no_overlap EXCLUDE USING gist
   (accounting_unit_id %I.gist_uuid_ops WITH =, daterange(starts_on,ends_on,'[]') WITH &&)
 )$sql$,extension_schema);
END $$;

CREATE INDEX periods_unit_dates_idx ON ucto.periods(accounting_unit_id,starts_on,id);

CREATE FUNCTION ucto.guard_record() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 IF TG_OP='INSERT' THEN
  IF NEW.version<>1 THEN RAISE EXCEPTION 'Invalid initial version.' USING ERRCODE='23514',CONSTRAINT='ucto_version'; END IF;
 ELSE
  IF NEW.id IS DISTINCT FROM OLD.id OR NEW.accounting_unit_id IS DISTINCT FROM OLD.accounting_unit_id
   OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.version<>OLD.version+1 THEN
   RAISE EXCEPTION 'Immutable identity or invalid version.' USING ERRCODE='23514',CONSTRAINT='ucto_identity';
  END IF;
 END IF;
 NEW.updated_at:=clock_timestamp();
 RETURN NEW;
END $$;
CREATE TRIGGER record_guard BEFORE INSERT OR UPDATE ON ucto.accounts FOR EACH ROW EXECUTE FUNCTION ucto.guard_record();
CREATE TRIGGER record_guard BEFORE INSERT OR UPDATE ON ucto.periods FOR EACH ROW EXECUTE FUNCTION ucto.guard_record();

CREATE FUNCTION ucto.guard_account_parent() RETURNS trigger LANGUAGE plpgsql VOLATILE
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 -- A fresh snapshot after waiting for the unit lock is required. Refuse
 -- snapshot-isolated writes rather than accepting a stale hierarchy check.
 IF current_setting('transaction_isolation')<>'read committed' THEN
  RAISE EXCEPTION 'Account hierarchy writes require read committed.' USING ERRCODE='23514',CONSTRAINT='accounts_isolation';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||NEW.accounting_unit_id::text,0));
 IF NEW.parent_id IS NOT NULL AND EXISTS (
  WITH RECURSIVE ancestors(id,parent_id) AS (
   SELECT a.id,a.parent_id FROM ucto.accounts a WHERE a.accounting_unit_id=NEW.accounting_unit_id AND a.id=NEW.parent_id
   UNION
   SELECT a.id,a.parent_id FROM ucto.accounts a JOIN ancestors p ON a.id=p.parent_id WHERE a.accounting_unit_id=NEW.accounting_unit_id
  ) SELECT 1 FROM ancestors WHERE id=NEW.id
 ) THEN RAISE EXCEPTION 'Account hierarchy cycle.' USING ERRCODE='23514',CONSTRAINT='accounts_parent_cycle'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER account_parent_guard BEFORE INSERT OR UPDATE ON ucto.accounts FOR EACH ROW EXECUTE FUNCTION ucto.guard_account_parent();
REVOKE ALL ON FUNCTION ucto.guard_record(),ucto.guard_account_parent() FROM PUBLIC;

DO $$
DECLARE kind text; requests text;
BEGIN
 FOREACH kind IN ARRAY ARRAY['accounts','periods'] LOOP
  PERFORM core.audit_attach(format('ucto.%I',kind)::regclass);
  EXECUTE format('REVOKE ALL ON ucto.%I FROM PUBLIC,mikoucto_app,mikoucto_read',kind);
  EXECUTE format('GRANT SELECT,INSERT,UPDATE ON ucto.%I TO mikoucto_app',kind);
  EXECUTE format('GRANT SELECT ON ucto.%I TO mikoucto_read',kind);
  EXECUTE format('ALTER TABLE ucto.%I ENABLE ROW LEVEL SECURITY',kind);
  EXECUTE format('CREATE POLICY scoped_read ON ucto.%I FOR SELECT TO mikoucto_app,mikoucto_read USING (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,%L))',kind,'core.ucto.'||kind||'.read');
  EXECUTE format('CREATE POLICY scoped_insert ON ucto.%I FOR INSERT TO mikoucto_app WITH CHECK (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,%L))',kind,'core.ucto.'||kind||'.write');
  EXECUTE format('CREATE POLICY scoped_update ON ucto.%I FOR UPDATE TO mikoucto_app USING (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,%L)) WITH CHECK (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,%L))',kind,'core.ucto.'||kind||'.write','core.ucto.'||kind||'.write');
  requests:=CASE kind WHEN 'accounts' THEN 'account_create_requests' ELSE 'period_create_requests' END;
  EXECUTE format($sql$CREATE TABLE ucto.%I (
   id uuid PRIMARY KEY, accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id),
   user_id uuid NOT NULL REFERENCES core.users(id), operation text NOT NULL CHECK(operation=%L),
   key varchar(128) NOT NULL CHECK(length(key)>0), request_hash char(64) NOT NULL,
   resource_id uuid NOT NULL, response jsonb NOT NULL,
   created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
   expires_at timestamptz NOT NULL DEFAULT clock_timestamp()+interval '24 hours',
   UNIQUE(accounting_unit_id,user_id,operation,key),
   FOREIGN KEY(accounting_unit_id,resource_id) REFERENCES ucto.%I(accounting_unit_id,id),
   CHECK(jsonb_typeof(response)='object' AND response ?& ARRAY['id','accountingUnitId','version']
    AND response->>'id'=resource_id::text AND response->>'accountingUnitId'=accounting_unit_id::text)
  )$sql$,requests,kind||'.create',kind);
  EXECUTE format('REVOKE ALL ON ucto.%I FROM PUBLIC,mikoucto_app,mikoucto_read',requests);
  EXECUTE format('GRANT SELECT,INSERT,UPDATE ON ucto.%I TO mikoucto_app',requests);
  EXECUTE format('ALTER TABLE ucto.%I ENABLE ROW LEVEL SECURITY',requests);
  EXECUTE format('CREATE POLICY request_scope ON ucto.%I TO mikoucto_app USING (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,%L) AND user_id=nullif(current_setting(''app.user_id'',true),'''')::uuid) WITH CHECK (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,%L) AND user_id=nullif(current_setting(''app.user_id'',true),'''')::uuid)',requests,'core.ucto.'||kind||'.write','core.ucto.'||kind||'.write');
  EXECUTE format('CREATE INDEX ON ucto.%I(expires_at)',requests);
 END LOOP;
END $$;

END
$ucto_migration$;
