DO $mikoucto_partners$
BEGIN
INSERT INTO core.permissions(code,name) VALUES
    ('core.partners.read','Cist partnery jednotky'),
    ('core.partners.write','Vytvaret a upravovat partnery jednotky'),
    ('core.partners.history','Cist historii partneru jednotky');
INSERT INTO core.role_permissions(role_code,permission_code) VALUES
    ('administrator','core.partners.read'), ('administrator','core.partners.write'), ('administrator','core.partners.history'),
    ('accountant','core.partners.read'), ('accountant','core.partners.write'),
    ('auditor','core.partners.read'), ('auditor','core.partners.history'), ('reader','core.partners.read');

CREATE TABLE core.partners (
    id uuid PRIMARY KEY,
    accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id) ON DELETE RESTRICT,
    name text NOT NULL CHECK (length(btrim(name)) BETWEEN 1 AND 200),
    registration_number varchar(32),
    vat_number varchar(32),
    country_code char(2) NOT NULL DEFAULT 'CZ' CHECK (country_code ~ '^[A-Z]{2}$'),
    street varchar(200), city varchar(120), postal_code varchar(20),
    email varchar(254), phone varchar(40),
    active boolean NOT NULL DEFAULT true,
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (accounting_unit_id,id),
    CHECK (registration_number IS NULL OR (length(registration_number) > 0 AND
           CASE WHEN country_code='CZ' THEN registration_number ~ '^[0-9]{8}$'
                ELSE registration_number ~ '^[A-Z0-9][A-Z0-9 /.-]{0,31}$' END)),
    CHECK (vat_number IS NULL OR vat_number ~ '^[A-Z]{2}[A-Z0-9]{2,30}$')
);
CREATE UNIQUE INDEX partners_registration_unique ON core.partners(accounting_unit_id,country_code,registration_number)
    WHERE registration_number IS NOT NULL;
CREATE INDEX partners_unit_name_idx ON core.partners(accounting_unit_id,name,id);

CREATE FUNCTION core.partner_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id OR NEW.accounting_unit_id IS DISTINCT FROM OLD.accounting_unit_id
       OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.version <> OLD.version+1 THEN
        RAISE EXCEPTION 'Immutable partner identity or invalid version.' USING ERRCODE='23514';
    END IF;
    NEW.updated_at := clock_timestamp();
    RETURN NEW;
END $$;
CREATE TRIGGER partner_guard BEFORE UPDATE ON core.partners FOR EACH ROW EXECUTE FUNCTION core.partner_guard();
PERFORM core.audit_attach('core.partners');

REVOKE ALL ON core.partners FROM PUBLIC,mikoucto_app,mikoucto_read;
GRANT SELECT,INSERT,UPDATE ON core.partners TO mikoucto_app;
GRANT SELECT ON core.partners TO mikoucto_read;
ALTER TABLE core.partners ENABLE ROW LEVEL SECURITY;
CREATE POLICY partners_read ON core.partners FOR SELECT TO mikoucto_app,mikoucto_read
    USING (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.partners.read'));
CREATE POLICY partners_insert ON core.partners FOR INSERT TO mikoucto_app
    WITH CHECK (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.partners.write'));
CREATE POLICY partners_update ON core.partners FOR UPDATE TO mikoucto_app
    USING (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.partners.write'))
    WITH CHECK (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.partners.write'));

CREATE TABLE core.idempotency_keys (
    id uuid PRIMARY KEY,
    accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id) ON DELETE RESTRICT,
    user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE RESTRICT,
    operation varchar(80) NOT NULL,
    key varchar(128) NOT NULL,
    request_hash char(64) NOT NULL,
    resource_id uuid NOT NULL,
    response jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    expires_at timestamptz NOT NULL DEFAULT clock_timestamp()+interval '24 hours',
    UNIQUE(accounting_unit_id,user_id,operation,key),
    FOREIGN KEY(accounting_unit_id,resource_id) REFERENCES core.partners(accounting_unit_id,id) ON DELETE RESTRICT,
    CHECK(jsonb_typeof(response)='object' AND response ?& ARRAY['id','accountingUnitId','version']
          AND response->>'id'=resource_id::text AND response->>'accountingUnitId'=accounting_unit_id::text)
);
CREATE INDEX idempotency_expiry_idx ON core.idempotency_keys(expires_at);
REVOKE ALL ON core.idempotency_keys FROM PUBLIC,mikoucto_app,mikoucto_read;
GRANT SELECT,INSERT,UPDATE ON core.idempotency_keys TO mikoucto_app;
ALTER TABLE core.idempotency_keys ENABLE ROW LEVEL SECURITY;
CREATE POLICY idempotency_scoped ON core.idempotency_keys TO mikoucto_app
    USING (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.partners.write')
           AND user_id=nullif(current_setting('app.user_id',true),'')::uuid)
    WITH CHECK (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.partners.write')
           AND user_id=nullif(current_setting('app.user_id',true),'')::uuid);
END
$mikoucto_partners$;
