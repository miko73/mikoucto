DO $correction_migration$
BEGIN
ALTER TABLE ucto.document_reversals ADD CONSTRAINT reversal_document_pair UNIQUE(accounting_unit_id,original_document_id,reversal_document_id);
CREATE TABLE ucto.document_corrections (
 id uuid PRIMARY KEY,accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id),
 original_document_id uuid NOT NULL,reversal_document_id uuid NOT NULL,correction_document_id uuid NOT NULL,
 requested_period_id uuid NOT NULL,requested_posting_date date NOT NULL,
 created_by uuid NOT NULL REFERENCES core.users(id),created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(accounting_unit_id,original_document_id),UNIQUE(accounting_unit_id,reversal_document_id),UNIQUE(accounting_unit_id,correction_document_id),
 CHECK(correction_document_id<>original_document_id AND correction_document_id<>reversal_document_id),
 FOREIGN KEY(accounting_unit_id,original_document_id,reversal_document_id)
  REFERENCES ucto.document_reversals(accounting_unit_id,original_document_id,reversal_document_id),
 FOREIGN KEY(accounting_unit_id,correction_document_id) REFERENCES ucto.documents(accounting_unit_id,id),
 FOREIGN KEY(accounting_unit_id,requested_period_id) REFERENCES ucto.periods(accounting_unit_id,id),
 CHECK(isfinite(requested_posting_date) AND requested_posting_date BETWEEN DATE '0001-01-01' AND DATE '9999-12-31')
);
REVOKE ALL ON ucto.document_corrections FROM PUBLIC,mikoucto_app,mikoucto_read;
GRANT SELECT ON ucto.document_corrections TO mikoucto_app,mikoucto_read;
ALTER TABLE ucto.document_corrections ENABLE ROW LEVEL SECURITY;
CREATE POLICY correction_read ON ucto.document_corrections FOR SELECT TO mikoucto_app,mikoucto_read
 USING(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.read'));
PERFORM core.audit_attach('ucto.document_corrections');

CREATE FUNCTION ucto.guard_correction() RETURNS trigger LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp AS $$
DECLARE source ucto.documents%ROWTYPE; target ucto.documents%ROWTYPE;
BEGIN
 IF TG_OP='DELETE' AND session_user='mikoucto_owner' THEN RETURN OLD; END IF;
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Correction relation is immutable.' USING ERRCODE='23514'; END IF;
 IF NOT coalesce(core.can_access_unit(NEW.accounting_unit_id)
  AND core.has_permission(NEW.accounting_unit_id,'core.units.read')
  AND core.has_permission(NEW.accounting_unit_id,'core.ucto.documents.read')
  AND core.has_permission(NEW.accounting_unit_id,'core.ucto.documents.write'),false) THEN
  RAISE EXCEPTION 'Permission denied.' USING ERRCODE='42501'; END IF;
 IF current_setting('transaction_isolation')<>'read committed' THEN
  RAISE EXCEPTION 'Corrections require read committed.' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||NEW.accounting_unit_id::text,0));
 SELECT * INTO source FROM ucto.documents WHERE accounting_unit_id=NEW.accounting_unit_id AND id=NEW.original_document_id;
 SELECT * INTO target FROM ucto.documents WHERE accounting_unit_id=NEW.accounting_unit_id AND id=NEW.correction_document_id;
 IF source.id IS NULL OR target.id IS NULL OR source.status<>'posted' OR target.status<>'draft'
  OR source.currency<>target.currency OR source.partner_id IS DISTINCT FROM target.partner_id
  OR source.text<>target.text OR target.posting_date<source.posting_date
  OR target.period_id<>NEW.requested_period_id OR target.posting_date<>NEW.requested_posting_date
  OR NEW.created_by IS DISTINCT FROM nullif(current_setting('app.user_id',true),'')::uuid
  OR EXISTS(SELECT 1 FROM ucto.document_corrections WHERE accounting_unit_id=NEW.accounting_unit_id AND correction_document_id=source.id)
  OR EXISTS(SELECT 1 FROM ucto.document_reversals WHERE accounting_unit_id=NEW.accounting_unit_id
   AND (reversal_document_id=source.id OR original_document_id=target.id OR reversal_document_id=target.id)) THEN
  RAISE EXCEPTION 'Invalid correction source or header.' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM ucto.periods WHERE accounting_unit_id=NEW.accounting_unit_id AND id=target.period_id AND status='open') THEN
  RAISE EXCEPTION 'Correction period is closed.' USING ERRCODE='23514'; END IF;
 IF EXISTS(
  (SELECT line_no,account_id,text,md,dal FROM ucto.document_lines WHERE accounting_unit_id=NEW.accounting_unit_id AND document_id=source.id
   EXCEPT SELECT line_no,account_id,text,md,dal FROM ucto.document_lines WHERE accounting_unit_id=NEW.accounting_unit_id AND document_id=target.id)
  UNION ALL
  (SELECT line_no,account_id,text,md,dal FROM ucto.document_lines WHERE accounting_unit_id=NEW.accounting_unit_id AND document_id=target.id
   EXCEPT SELECT line_no,account_id,text,md,dal FROM ucto.document_lines WHERE accounting_unit_id=NEW.accounting_unit_id AND document_id=source.id)
 ) THEN RAISE EXCEPTION 'Initial correction must copy source lines exactly.' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER correction_guard BEFORE INSERT OR UPDATE OR DELETE ON ucto.document_corrections FOR EACH ROW EXECUTE FUNCTION ucto.guard_correction();

-- Existing reverse_document remains unchanged. Even direct SQL reversal of a
-- posted correction rolls back when its relation is inserted.
CREATE FUNCTION ucto.guard_correction_reversal() RETURNS trigger LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM ucto.document_corrections WHERE accounting_unit_id=NEW.accounting_unit_id AND correction_document_id=NEW.original_document_id) THEN
  RAISE EXCEPTION 'A correction cannot be reversed again.' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER reversal_source_guard BEFORE INSERT ON ucto.document_reversals FOR EACH ROW EXECUTE FUNCTION ucto.guard_correction_reversal();
CREATE FUNCTION ucto.guard_correction_date() RETURNS trigger LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM ucto.document_corrections c JOIN ucto.documents d
  ON d.accounting_unit_id=c.accounting_unit_id AND d.id=c.original_document_id
  WHERE c.accounting_unit_id=NEW.accounting_unit_id AND c.correction_document_id=NEW.id AND NEW.posting_date<d.posting_date) THEN
  RAISE EXCEPTION 'Correction date precedes source.' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER correction_date_guard BEFORE UPDATE ON ucto.documents FOR EACH ROW EXECUTE FUNCTION ucto.guard_correction_date();

CREATE FUNCTION ucto.create_correction_draft(p_unit uuid,p_source uuid,p_version integer,p_period uuid,p_date date) RETURNS uuid
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
DECLARE source ucto.documents%ROWTYPE; inverse ucto.documents%ROWTYPE; existing ucto.document_corrections%ROWTYPE;
 target uuid; lines jsonb; line_ids uuid[];
BEGIN
 IF NOT coalesce(core.can_access_unit(p_unit) AND core.has_permission(p_unit,'core.units.read')
  AND core.has_permission(p_unit,'core.ucto.documents.read') AND core.has_permission(p_unit,'core.ucto.documents.write'),false) THEN
  RAISE EXCEPTION 'Permission denied.' USING ERRCODE='42501'; END IF;
 IF current_setting('transaction_isolation')<>'read committed' THEN
  RAISE EXCEPTION 'Corrections require read committed.' USING ERRCODE='23514'; END IF;
 IF p_period IS NULL OR p_date IS NULL OR NOT isfinite(p_date) OR p_date NOT BETWEEN DATE '0001-01-01' AND DATE '9999-12-31' THEN
  RAISE EXCEPTION 'Invalid correction input.' USING ERRCODE='22023'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||p_unit::text,0));
 SELECT * INTO source FROM ucto.documents WHERE accounting_unit_id=p_unit AND id=p_source;
 IF NOT FOUND THEN RAISE EXCEPTION 'Not found.' USING ERRCODE='P0014'; END IF;
 SELECT d.* INTO inverse FROM ucto.document_reversals r JOIN ucto.documents d
  ON d.accounting_unit_id=r.accounting_unit_id AND d.id=r.reversal_document_id
  WHERE r.accounting_unit_id=p_unit AND r.original_document_id=p_source;
 PERFORM id FROM ucto.periods WHERE accounting_unit_id=p_unit AND id IN (source.period_id,inverse.period_id,p_period) ORDER BY id FOR UPDATE;
 PERFORM id FROM ucto.documents WHERE accounting_unit_id=p_unit AND id IN (p_source,inverse.id) ORDER BY id FOR UPDATE;
 SELECT * INTO source FROM ucto.documents WHERE accounting_unit_id=p_unit AND id=p_source;
 IF p_version IS NULL OR source.version<>p_version THEN RAISE EXCEPTION 'Stale version.' USING ERRCODE='P0012'; END IF;
 IF source.status<>'posted' OR inverse.id IS NULL OR inverse.status<>'posted'
  OR EXISTS(SELECT 1 FROM ucto.document_corrections WHERE accounting_unit_id=p_unit AND correction_document_id=p_source)
  OR EXISTS(SELECT 1 FROM ucto.document_reversals WHERE accounting_unit_id=p_unit AND reversal_document_id=p_source) THEN
  RAISE EXCEPTION 'Correction needs an ordinary reversed source.' USING ERRCODE='23514'; END IF;
 SELECT * INTO existing FROM ucto.document_corrections WHERE accounting_unit_id=p_unit AND original_document_id=p_source;
 IF FOUND THEN
  IF existing.requested_period_id=p_period AND existing.requested_posting_date=p_date THEN RETURN existing.correction_document_id; END IF;
  RAISE EXCEPTION 'Correction already exists with different requested values.' USING ERRCODE='23514';
 END IF;
 IF p_date<source.posting_date THEN RAISE EXCEPTION 'Correction date precedes source.' USING ERRCODE='23514'; END IF;
 SELECT jsonb_agg(jsonb_build_object('accountId',account_id,'text',text,'md',round(md,2)::text,'dal',round(dal,2)::text) ORDER BY line_no),
  array_agg(gen_random_uuid() ORDER BY line_no) INTO lines,line_ids
  FROM ucto.document_lines WHERE accounting_unit_id=p_unit AND document_id=p_source;
 target:=gen_random_uuid();
 PERFORM ucto.save_draft(p_unit,target,NULL,jsonb_build_object('periodId',p_period,'postingDate',to_char(p_date,'YYYY-MM-DD'),
  'text',source.text,'partnerId',source.partner_id,'lines',lines),line_ids);
 INSERT INTO ucto.document_corrections(id,accounting_unit_id,original_document_id,reversal_document_id,correction_document_id,
  requested_period_id,requested_posting_date,created_by)
 VALUES(gen_random_uuid(),p_unit,p_source,inverse.id,target,p_period,p_date,nullif(current_setting('app.user_id',true),'')::uuid);
 RETURN target;
END $$;

CREATE TABLE ucto.document_correction_requests (
 id uuid PRIMARY KEY,accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id),user_id uuid NOT NULL REFERENCES core.users(id),
 operation text NOT NULL CHECK(operation='documents.correction-draft'),key varchar(128) NOT NULL CHECK(length(key)>0),request_hash char(64) NOT NULL,
 source_id uuid NOT NULL,resource_id uuid NOT NULL,response jsonb NOT NULL,response_status integer NOT NULL CHECK(response_status IN (200,201)),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),expires_at timestamptz NOT NULL DEFAULT clock_timestamp()+interval '24 hours',
 UNIQUE(accounting_unit_id,user_id,operation,key),
 FOREIGN KEY(accounting_unit_id,source_id) REFERENCES ucto.documents(accounting_unit_id,id),
 FOREIGN KEY(accounting_unit_id,resource_id) REFERENCES ucto.documents(accounting_unit_id,id),
 CHECK(jsonb_typeof(response)='object' AND response ?& ARRAY['id','accountingUnitId','version']
  AND response->>'id'=resource_id::text AND response->>'accountingUnitId'=accounting_unit_id::text)
);
CREATE INDEX ON ucto.document_correction_requests(expires_at);
REVOKE ALL ON ucto.document_correction_requests FROM PUBLIC,mikoucto_app,mikoucto_read;
GRANT SELECT,INSERT,UPDATE ON ucto.document_correction_requests TO mikoucto_app;
ALTER TABLE ucto.document_correction_requests ENABLE ROW LEVEL SECURITY;
CREATE POLICY correction_request_scope ON ucto.document_correction_requests TO mikoucto_app
 USING(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.write')
  AND user_id=nullif(current_setting('app.user_id',true),'')::uuid)
 WITH CHECK(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.write')
  AND user_id=nullif(current_setting('app.user_id',true),'')::uuid);
REVOKE ALL ON FUNCTION ucto.guard_correction(),ucto.guard_correction_reversal(),ucto.guard_correction_date(),
 ucto.create_correction_draft(uuid,uuid,integer,uuid,date) FROM PUBLIC,mikoucto_read;
GRANT EXECUTE ON FUNCTION ucto.create_correction_draft(uuid,uuid,integer,uuid,date) TO mikoucto_app;
END
$correction_migration$;
