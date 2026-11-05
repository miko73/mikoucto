DO $reversal_migration$
BEGIN
INSERT INTO core.permissions(code,name) VALUES ('core.ucto.documents.reverse','Stornovat doklad');
INSERT INTO core.role_permissions(role_code,permission_code) VALUES
 ('administrator','core.ucto.documents.reverse'),('accountant','core.ucto.documents.reverse');

CREATE TABLE ucto.document_reversals (
 id uuid PRIMARY KEY, accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id),
 original_document_id uuid NOT NULL, reversal_document_id uuid NOT NULL,
 reason text NOT NULL CHECK(length(btrim(reason)) BETWEEN 1 AND 1000 AND reason=btrim(reason)),
 created_by uuid NOT NULL REFERENCES core.users(id),created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(accounting_unit_id,original_document_id),UNIQUE(accounting_unit_id,reversal_document_id),
 CHECK(original_document_id<>reversal_document_id),
 FOREIGN KEY(accounting_unit_id,original_document_id) REFERENCES ucto.documents(accounting_unit_id,id),
 FOREIGN KEY(accounting_unit_id,reversal_document_id) REFERENCES ucto.documents(accounting_unit_id,id)
);
REVOKE ALL ON ucto.document_reversals FROM PUBLIC,mikoucto_app,mikoucto_read;
GRANT SELECT ON ucto.document_reversals TO mikoucto_app,mikoucto_read;
ALTER TABLE ucto.document_reversals ENABLE ROW LEVEL SECURITY;
CREATE POLICY reversal_read ON ucto.document_reversals FOR SELECT TO mikoucto_app,mikoucto_read
 USING(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.read'));
PERFORM core.audit_attach('ucto.document_reversals');

CREATE FUNCTION ucto.guard_reversal() RETURNS trigger LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp AS $$
DECLARE source ucto.documents%ROWTYPE; target ucto.documents%ROWTYPE;
BEGIN
 IF TG_OP='DELETE' AND session_user='mikoucto_owner' THEN RETURN OLD; END IF;
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Reversal relation is immutable.' USING ERRCODE='23514'; END IF;
 IF NOT coalesce(core.can_access_unit(NEW.accounting_unit_id)
  AND core.has_permission(NEW.accounting_unit_id,'core.units.read')
  AND core.has_permission(NEW.accounting_unit_id,'core.ucto.documents.read')
  AND core.has_permission(NEW.accounting_unit_id,'core.ucto.documents.write')
  AND core.has_permission(NEW.accounting_unit_id,'core.ucto.documents.post')
  AND core.has_permission(NEW.accounting_unit_id,'core.ucto.documents.reverse'),false) THEN
  RAISE EXCEPTION 'Permission denied.' USING ERRCODE='42501'; END IF;
 IF current_setting('transaction_isolation')<>'read committed' THEN
  RAISE EXCEPTION 'Reversals require read committed.' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||NEW.accounting_unit_id::text,0));
 SELECT * INTO source FROM ucto.documents WHERE accounting_unit_id=NEW.accounting_unit_id AND id=NEW.original_document_id;
 SELECT * INTO target FROM ucto.documents WHERE accounting_unit_id=NEW.accounting_unit_id AND id=NEW.reversal_document_id;
 IF source.id IS NULL OR target.id IS NULL OR source.status<>'posted' OR target.status<>'posted'
  OR source.currency<>target.currency OR source.partner_id IS DISTINCT FROM target.partner_id
  OR source.text<>target.text OR target.posting_date<source.posting_date
  OR target.posted_by IS DISTINCT FROM NEW.created_by
  OR NEW.created_by IS DISTINCT FROM nullif(current_setting('app.user_id',true),'')::uuid
  OR EXISTS(SELECT 1 FROM ucto.document_reversals WHERE accounting_unit_id=NEW.accounting_unit_id
   AND (reversal_document_id=NEW.original_document_id OR original_document_id=NEW.reversal_document_id)) THEN
  RAISE EXCEPTION 'Invalid reversal source or header.' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM ucto.periods WHERE accounting_unit_id=NEW.accounting_unit_id AND id=target.period_id AND status='open') THEN
  RAISE EXCEPTION 'Reversal period is closed.' USING ERRCODE='23514'; END IF;
 IF EXISTS(
  (SELECT line_no,account_id,text,md,dal FROM ucto.document_lines WHERE accounting_unit_id=NEW.accounting_unit_id AND document_id=source.id
   EXCEPT SELECT line_no,account_id,text,dal,md FROM ucto.document_lines WHERE accounting_unit_id=NEW.accounting_unit_id AND document_id=target.id)
  UNION ALL
  (SELECT line_no,account_id,text,dal,md FROM ucto.document_lines WHERE accounting_unit_id=NEW.accounting_unit_id AND document_id=target.id
   EXCEPT SELECT line_no,account_id,text,md,dal FROM ucto.document_lines WHERE accounting_unit_id=NEW.accounting_unit_id AND document_id=source.id)
 ) THEN RAISE EXCEPTION 'Reversal lines must be exact inverses.' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER reversal_guard BEFORE INSERT OR UPDATE OR DELETE ON ucto.document_reversals FOR EACH ROW EXECUTE FUNCTION ucto.guard_reversal();

CREATE FUNCTION ucto.reverse_document(p_unit uuid,p_source uuid,p_version integer,p_period uuid,p_date date,p_reason text) RETURNS uuid
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
DECLARE source ucto.documents%ROWTYPE; existing record; target uuid; lines jsonb; line_ids uuid[];
BEGIN
 IF NOT coalesce(core.can_access_unit(p_unit) AND core.has_permission(p_unit,'core.units.read')
  AND core.has_permission(p_unit,'core.ucto.documents.read') AND core.has_permission(p_unit,'core.ucto.documents.write')
  AND core.has_permission(p_unit,'core.ucto.documents.post') AND core.has_permission(p_unit,'core.ucto.documents.reverse'),false) THEN
  RAISE EXCEPTION 'Permission denied.' USING ERRCODE='42501'; END IF;
 IF current_setting('transaction_isolation')<>'read committed' THEN
  RAISE EXCEPTION 'Reversals require read committed.' USING ERRCODE='23514'; END IF;
 IF p_period IS NULL OR p_date IS NULL OR NOT isfinite(p_date) OR p_date NOT BETWEEN DATE '0001-01-01' AND DATE '9999-12-31'
  OR p_reason IS NULL OR length(btrim(p_reason)) NOT BETWEEN 1 AND 1000 THEN
  RAISE EXCEPTION 'Invalid reversal input.' USING ERRCODE='22023'; END IF;
 p_reason:=btrim(p_reason);
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||p_unit::text,0));
 SELECT * INTO source FROM ucto.documents WHERE accounting_unit_id=p_unit AND id=p_source;
 IF NOT FOUND THEN RAISE EXCEPTION 'Not found.' USING ERRCODE='P0014'; END IF;
 PERFORM id FROM ucto.periods WHERE accounting_unit_id=p_unit AND id IN (source.period_id,p_period) ORDER BY id FOR UPDATE;
 SELECT * INTO source FROM ucto.documents WHERE accounting_unit_id=p_unit AND id=p_source FOR UPDATE;
 IF p_version IS NULL OR source.version<>p_version THEN RAISE EXCEPTION 'Stale version.' USING ERRCODE='P0012'; END IF;
 IF source.status<>'posted' OR EXISTS(SELECT 1 FROM ucto.document_reversals WHERE accounting_unit_id=p_unit AND reversal_document_id=p_source) THEN
  RAISE EXCEPTION 'Only an ordinary posted source can be reversed.' USING ERRCODE='23514'; END IF;
 SELECT r.reason,d.id,d.period_id,d.posting_date INTO existing FROM ucto.document_reversals r
  JOIN ucto.documents d ON d.accounting_unit_id=r.accounting_unit_id AND d.id=r.reversal_document_id
  WHERE r.accounting_unit_id=p_unit AND r.original_document_id=p_source;
 IF FOUND THEN
  IF existing.period_id=p_period AND existing.posting_date=p_date AND existing.reason=p_reason THEN RETURN existing.id; END IF;
  RAISE EXCEPTION 'Source already reversed with different values.' USING ERRCODE='23514';
 END IF;
 IF p_date<source.posting_date THEN RAISE EXCEPTION 'Reversal date precedes source.' USING ERRCODE='23514'; END IF;
 SELECT jsonb_agg(jsonb_build_object('accountId',account_id,'text',text,'md',round(dal,2)::text,'dal',round(md,2)::text) ORDER BY line_no),
  array_agg(gen_random_uuid() ORDER BY line_no) INTO lines,line_ids
  FROM ucto.document_lines WHERE accounting_unit_id=p_unit AND document_id=p_source;
 target:=gen_random_uuid();
 PERFORM ucto.save_draft(p_unit,target,NULL,jsonb_build_object('periodId',p_period,'postingDate',to_char(p_date,'YYYY-MM-DD'),
  'text',source.text,'partnerId',source.partner_id,'lines',lines),line_ids);
 PERFORM ucto.post_document(p_unit,target,1);
 INSERT INTO ucto.document_reversals(id,accounting_unit_id,original_document_id,reversal_document_id,reason,created_by)
 VALUES(gen_random_uuid(),p_unit,p_source,target,p_reason,nullif(current_setting('app.user_id',true),'')::uuid);
 RETURN target;
END $$;

CREATE TABLE ucto.document_reverse_requests (
 id uuid PRIMARY KEY,accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id),user_id uuid NOT NULL REFERENCES core.users(id),
 operation text NOT NULL CHECK(operation='documents.reverse'),key varchar(128) NOT NULL CHECK(length(key)>0),request_hash char(64) NOT NULL,
 source_id uuid NOT NULL,resource_id uuid NOT NULL,response jsonb NOT NULL,response_status integer NOT NULL CHECK(response_status IN (200,201)),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),expires_at timestamptz NOT NULL DEFAULT clock_timestamp()+interval '24 hours',
 UNIQUE(accounting_unit_id,user_id,operation,key),
 FOREIGN KEY(accounting_unit_id,source_id) REFERENCES ucto.documents(accounting_unit_id,id),
 FOREIGN KEY(accounting_unit_id,resource_id) REFERENCES ucto.documents(accounting_unit_id,id),
 CHECK(jsonb_typeof(response)='object' AND response ?& ARRAY['id','accountingUnitId','version']
  AND response->>'id'=resource_id::text AND response->>'accountingUnitId'=accounting_unit_id::text)
);
CREATE INDEX ON ucto.document_reverse_requests(expires_at);
REVOKE ALL ON ucto.document_reverse_requests FROM PUBLIC,mikoucto_app,mikoucto_read;
GRANT SELECT,INSERT,UPDATE ON ucto.document_reverse_requests TO mikoucto_app;
ALTER TABLE ucto.document_reverse_requests ENABLE ROW LEVEL SECURITY;
CREATE POLICY reverse_request_scope ON ucto.document_reverse_requests TO mikoucto_app
 USING(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.reverse')
  AND user_id=nullif(current_setting('app.user_id',true),'')::uuid)
 WITH CHECK(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.reverse')
  AND user_id=nullif(current_setting('app.user_id',true),'')::uuid);
REVOKE ALL ON FUNCTION ucto.guard_reversal(),ucto.reverse_document(uuid,uuid,integer,uuid,date,text) FROM PUBLIC,mikoucto_read;
GRANT EXECUTE ON FUNCTION ucto.reverse_document(uuid,uuid,integer,uuid,date,text) TO mikoucto_app;
END
$reversal_migration$;
