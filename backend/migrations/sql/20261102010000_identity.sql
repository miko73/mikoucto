DO $mikoucto_identity$
BEGIN
CREATE TABLE core.users (
    id uuid PRIMARY KEY,
    entra_tenant_id uuid NOT NULL,
    entra_object_id uuid NOT NULL,
    display_name text NOT NULL CHECK (length(display_name) BETWEEN 1 AND 200),
    active boolean NOT NULL DEFAULT true,
    version integer NOT NULL DEFAULT 1,
    UNIQUE (entra_tenant_id, entra_object_id)
);
CREATE TABLE core.accounting_units (
    id uuid PRIMARY KEY,
    code varchar(32) NOT NULL UNIQUE,
    name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    active boolean NOT NULL DEFAULT true,
    version integer NOT NULL DEFAULT 1
);
CREATE TABLE core.roles (
    code varchar(32) PRIMARY KEY,
    name text NOT NULL
);
CREATE TABLE core.permissions (
    code varchar(80) PRIMARY KEY,
    name text NOT NULL
);
CREATE TABLE core.role_permissions (
    role_code varchar(32) NOT NULL REFERENCES core.roles(code) ON DELETE RESTRICT,
    permission_code varchar(80) NOT NULL REFERENCES core.permissions(code) ON DELETE RESTRICT,
    PRIMARY KEY (role_code, permission_code)
);
CREATE TABLE core.user_roles (
    id uuid PRIMARY KEY,
    user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE RESTRICT,
    accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id) ON DELETE RESTRICT,
    role_code varchar(32) NOT NULL REFERENCES core.roles(code) ON DELETE RESTRICT,
    version integer NOT NULL DEFAULT 1,
    UNIQUE (user_id, accounting_unit_id, role_code)
);
CREATE INDEX user_roles_unit_idx ON core.user_roles(accounting_unit_id, user_id);

INSERT INTO core.roles VALUES
    ('administrator', 'Administrator jednotky'), ('accountant', 'Ucetni'),
    ('auditor', 'Auditor'), ('reader', 'Pouze cteni');
INSERT INTO core.permissions VALUES
    ('core.units.read', 'Zobrazit jednotku'),
    ('core.users.read', 'Zobrazit uzivatele jednotky'),
    ('core.roles.read', 'Zobrazit role jednotky'),
    ('core.roles.manage', 'Pridelovat a odebirat role v jednotce'),
    ('core.audit.read', 'Cist audit jednotky');
INSERT INTO core.role_permissions
SELECT 'administrator', code FROM core.permissions;
INSERT INTO core.role_permissions VALUES
    ('accountant', 'core.units.read'), ('accountant', 'core.roles.read'),
    ('auditor', 'core.units.read'), ('auditor', 'core.roles.read'), ('auditor', 'core.audit.read'),
    ('reader', 'core.units.read'), ('reader', 'core.roles.read');

-- Backend nikdy nevytvari identity ani clenstvi primym INSERT/UPDATE.
REVOKE ALL ON core.users, core.accounting_units, core.roles, core.permissions,
    core.role_permissions, core.user_roles FROM PUBLIC, mikoucto_app, mikoucto_read;
GRANT SELECT ON core.users, core.accounting_units, core.roles, core.permissions,
    core.role_permissions, core.user_roles TO mikoucto_app, mikoucto_read;

CREATE FUNCTION core.has_permission(p_unit uuid, p_permission text)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
    SELECT EXISTS (
        SELECT 1 FROM core.user_roles ur
        JOIN core.users u ON u.id=ur.user_id AND u.active
        JOIN core.accounting_units a ON a.id=ur.accounting_unit_id AND a.active
        JOIN core.role_permissions rp ON rp.role_code=ur.role_code
        WHERE ur.user_id=nullif(current_setting('app.user_id', true), '')::uuid
          AND ur.accounting_unit_id=p_unit AND rp.permission_code=p_permission
    )
$$;

CREATE FUNCTION core.can_access_unit(p_unit uuid)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
    SELECT p_unit=nullif(current_setting('app.accounting_unit_id', true), '')::uuid
       AND core.has_permission(p_unit, 'core.units.read')
$$;

CREATE FUNCTION core.can_view_user(p_user uuid)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
    SELECT (p_user=nullif(current_setting('app.user_id', true), '')::uuid
            AND EXISTS (SELECT 1 FROM core.users WHERE id=p_user AND active))
        OR EXISTS (SELECT 1 FROM core.user_roles target WHERE target.user_id=p_user
                   AND core.has_permission(target.accounting_unit_id, 'core.users.read'))
$$;

-- Volano az po overeni podpisu a claims tokenu; nezaklada nove uzivatele.
CREATE FUNCTION core.resolve_identity(p_tenant uuid, p_object uuid)
RETURNS TABLE(id uuid, display_name text) LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
    SELECT u.id, u.display_name FROM core.users u
    WHERE u.entra_tenant_id=p_tenant AND u.entra_object_id=p_object AND u.active
$$;

CREATE FUNCTION core.visible_audit_unit(p_table text, p_new jsonb, p_old jsonb)
RETURNS uuid LANGUAGE sql IMMUTABLE
SET search_path = pg_catalog, pg_temp
AS $$
    SELECT CASE WHEN unit_value ~ '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
                THEN unit_value::uuid ELSE NULL END
    FROM (SELECT coalesce(p_new->>'accounting_unit_id', p_old->>'accounting_unit_id',
          CASE WHEN p_table='core.accounting_units' THEN coalesce(p_new->>'id', p_old->>'id') END) AS unit_value) s
$$;

ALTER TABLE core.users ENABLE ROW LEVEL SECURITY;
CREATE POLICY users_visible ON core.users FOR SELECT TO mikoucto_app, mikoucto_read
    USING (core.can_view_user(id));
ALTER TABLE core.accounting_units ENABLE ROW LEVEL SECURITY;
CREATE POLICY units_visible ON core.accounting_units FOR SELECT TO mikoucto_app, mikoucto_read
    USING (core.has_permission(id, 'core.units.read'));
ALTER TABLE core.user_roles ENABLE ROW LEVEL SECURITY;
CREATE POLICY memberships_visible ON core.user_roles FOR SELECT TO mikoucto_app, mikoucto_read
    USING ((user_id=nullif(current_setting('app.user_id', true), '')::uuid
            AND core.has_permission(accounting_unit_id, 'core.units.read'))
           OR core.has_permission(accounting_unit_id, 'core.users.read'));
ALTER TABLE core.audit_log ENABLE ROW LEVEL SECURITY;
CREATE POLICY audit_visible ON core.audit_log FOR SELECT TO mikoucto_app, mikoucto_read
    USING (core.can_access_unit(core.visible_audit_unit(table_name, new_data, old_data))
       AND core.has_permission(core.visible_audit_unit(table_name, new_data, old_data), 'core.audit.read'));

-- Vlastnik je duveryhodny spravce. SECURITY DEFINER pomocne funkce se vyhnou
-- rekurzi RLS. Backend pouziva vyhradne neprivilegovanou mikoucto_app.
CREATE FUNCTION core.assign_role(p_user uuid, p_unit uuid, p_role text, p_id uuid)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE v_id uuid;
BEGIN
    PERFORM 1 FROM core.accounting_units WHERE id=p_unit FOR UPDATE;
    IF NOT core.has_permission(p_unit, 'core.roles.manage') THEN
        RAISE EXCEPTION 'Role management is not allowed.' USING ERRCODE='42501';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM core.users WHERE id=p_user AND active)
       OR NOT EXISTS (SELECT 1 FROM core.roles WHERE code=p_role) THEN
        RAISE EXCEPTION 'Unknown active user or role.' USING ERRCODE='22023';
    END IF;
    INSERT INTO core.user_roles(id, user_id, accounting_unit_id, role_code)
    VALUES (p_id, p_user, p_unit, p_role) ON CONFLICT (user_id, accounting_unit_id, role_code) DO NOTHING;
    SELECT id INTO v_id FROM core.user_roles
    WHERE user_id=p_user AND accounting_unit_id=p_unit AND role_code=p_role;
    RETURN v_id;
END $$;

CREATE FUNCTION core.revoke_role(p_user uuid, p_unit uuid, p_role text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
    PERFORM 1 FROM core.accounting_units WHERE id=p_unit FOR UPDATE;
    IF NOT core.has_permission(p_unit, 'core.roles.manage') THEN
        RAISE EXCEPTION 'Role management is not allowed.' USING ERRCODE='42501';
    END IF;
    IF p_role='administrator' AND EXISTS (SELECT 1 FROM core.user_roles
        WHERE user_id=p_user AND accounting_unit_id=p_unit AND role_code=p_role)
       AND NOT EXISTS (SELECT 1 FROM core.user_roles ur JOIN core.users u ON u.id=ur.user_id AND u.active
            WHERE ur.accounting_unit_id=p_unit AND ur.role_code='administrator' AND ur.user_id<>p_user) THEN
        RAISE EXCEPTION 'The last active administrator cannot be removed.' USING ERRCODE='23514';
    END IF;
    DELETE FROM core.user_roles WHERE user_id=p_user AND accounting_unit_id=p_unit AND role_code=p_role;
END $$;

PERFORM core.audit_attach('core.users');
PERFORM core.audit_attach('core.accounting_units');
PERFORM core.audit_attach('core.user_roles');
PERFORM core.audit_attach('core.roles', 'code');
PERFORM core.audit_attach('core.permissions', 'code');
PERFORM core.audit_attach('core.role_permissions', 'role_code');

REVOKE ALL ON FUNCTION core.has_permission(uuid,text), core.can_access_unit(uuid), core.can_view_user(uuid),
    core.resolve_identity(uuid,uuid), core.visible_audit_unit(text,jsonb,jsonb),
    core.assign_role(uuid,uuid,text,uuid), core.revoke_role(uuid,uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION core.has_permission(uuid,text), core.can_access_unit(uuid), core.can_view_user(uuid),
    core.visible_audit_unit(text,jsonb,jsonb) TO mikoucto_app, mikoucto_read;
GRANT EXECUTE ON FUNCTION core.resolve_identity(uuid,uuid), core.assign_role(uuid,uuid,text,uuid),
    core.revoke_role(uuid,uuid,text) TO mikoucto_app;
END
$mikoucto_identity$;
