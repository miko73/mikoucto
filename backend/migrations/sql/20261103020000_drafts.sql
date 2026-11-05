DO $draft_migration$
BEGIN
INSERT INTO core.permissions(code,name) VALUES
 ('core.ucto.documents.read','Cist rozpracovane doklady'),
 ('core.ucto.documents.write','Spravovat rozpracovane doklady'),
 ('core.ucto.documents.history','Historie dokladu');
INSERT INTO core.role_permissions(role_code,permission_code)
 SELECT r.code,p.code FROM core.roles r CROSS JOIN core.permissions p
 WHERE p.code LIKE 'core.ucto.documents.%' AND
 (p.code LIKE '%.read' OR (r.code IN ('administrator','accountant') AND p.code LIKE '%.write')
 OR (r.code IN ('administrator','auditor') AND p.code LIKE '%.history'));

CREATE TABLE ucto.documents (
 id uuid PRIMARY KEY,
 accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id),
 period_id uuid NOT NULL, posting_date date NOT NULL,
 text text NOT NULL CHECK(length(btrim(text)) BETWEEN 1 AND 2000),
 partner_id uuid,
 status text NOT NULL DEFAULT 'draft' CHECK(status='draft'),
 currency text NOT NULL DEFAULT 'CZK' CHECK(currency='CZK'),
 version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(accounting_unit_id,id),
 CONSTRAINT documents_period_fk FOREIGN KEY(accounting_unit_id,period_id) REFERENCES ucto.periods(accounting_unit_id,id),
 CONSTRAINT documents_partner_fk FOREIGN KEY(accounting_unit_id,partner_id) REFERENCES core.partners(accounting_unit_id,id),
 CHECK(isfinite(posting_date) AND posting_date BETWEEN DATE '0001-01-01' AND DATE '9999-12-31')
);
CREATE INDEX documents_unit_date_idx ON ucto.documents(accounting_unit_id,posting_date DESC,id DESC);
CREATE INDEX documents_period_idx ON ucto.documents(accounting_unit_id,period_id);
CREATE TABLE ucto.document_lines (
 id uuid PRIMARY KEY,
 accounting_unit_id uuid NOT NULL,
 document_id uuid NOT NULL, line_no integer NOT NULL CHECK(line_no BETWEEN 1 AND 500),
 account_id uuid NOT NULL, text text NOT NULL DEFAULT '' CHECK(length(text)<=500),
 -- An unconstrained numeric rejects excess precision via CHECK instead of
 -- rounding before constraints run, as numeric(18,2) would do.
 md numeric NOT NULL, dal numeric NOT NULL,
 UNIQUE(accounting_unit_id,document_id,line_no),
 CONSTRAINT document_lines_document_fk FOREIGN KEY(accounting_unit_id,document_id) REFERENCES ucto.documents(accounting_unit_id,id),
 CONSTRAINT document_lines_account_fk FOREIGN KEY(accounting_unit_id,account_id) REFERENCES ucto.accounts(accounting_unit_id,id),
 CONSTRAINT document_lines_amounts CHECK(md>=0 AND dal>=0 AND md<=9999999999999999.99 AND dal<=9999999999999999.99
  AND scale(md)<=2 AND scale(dal)<=2 AND ((md>0 AND dal=0) OR (dal>0 AND md=0)))
);
CREATE INDEX document_lines_account_idx ON ucto.document_lines(accounting_unit_id,account_id);
CREATE TRIGGER record_guard BEFORE INSERT OR UPDATE ON ucto.documents FOR EACH ROW EXECUTE FUNCTION ucto.guard_record();
PERFORM core.audit_attach('ucto.documents');
PERFORM core.audit_attach('ucto.document_lines');

REVOKE ALL ON ucto.documents,ucto.document_lines FROM PUBLIC,mikoucto_app,mikoucto_read;
GRANT SELECT ON ucto.documents,ucto.document_lines TO mikoucto_app,mikoucto_read;
ALTER TABLE ucto.documents ENABLE ROW LEVEL SECURITY;
ALTER TABLE ucto.document_lines ENABLE ROW LEVEL SECURITY;
CREATE POLICY documents_read ON ucto.documents FOR SELECT TO mikoucto_app,mikoucto_read
 USING(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.read'));
CREATE POLICY document_lines_read ON ucto.document_lines FOR SELECT TO mikoucto_app,mikoucto_read
 USING(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.read'));

CREATE TABLE ucto.document_create_requests (
 id uuid PRIMARY KEY, accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id),
 user_id uuid NOT NULL REFERENCES core.users(id), operation text NOT NULL CHECK(operation='documents.create'),
 key varchar(128) NOT NULL CHECK(length(key)>0), request_hash char(64) NOT NULL,
 resource_id uuid NOT NULL, response jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(), expires_at timestamptz NOT NULL DEFAULT clock_timestamp()+interval '24 hours',
 UNIQUE(accounting_unit_id,user_id,operation,key),
 FOREIGN KEY(accounting_unit_id,resource_id) REFERENCES ucto.documents(accounting_unit_id,id),
 CHECK(jsonb_typeof(response)='object' AND response ?& ARRAY['id','accountingUnitId','version']
  AND response->>'id'=resource_id::text AND response->>'accountingUnitId'=accounting_unit_id::text)
);
CREATE INDEX ON ucto.document_create_requests(expires_at);
REVOKE ALL ON ucto.document_create_requests FROM PUBLIC,mikoucto_app,mikoucto_read;
GRANT SELECT,INSERT,UPDATE ON ucto.document_create_requests TO mikoucto_app;
ALTER TABLE ucto.document_create_requests ENABLE ROW LEVEL SECURITY;
CREATE POLICY document_requests_scope ON ucto.document_create_requests TO mikoucto_app
 USING(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.write')
  AND user_id=nullif(current_setting('app.user_id',true),'')::uuid)
 WITH CHECK(core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,'core.ucto.documents.write')
  AND user_id=nullif(current_setting('app.user_id',true),'')::uuid);

CREATE FUNCTION ucto.guard_used_account() RETURNS trigger LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 -- account_parent_guard has already acquired the shared unit advisory lock.
 IF NEW.code IS DISTINCT FROM OLD.code AND EXISTS(SELECT 1 FROM ucto.document_lines WHERE accounting_unit_id=OLD.accounting_unit_id AND account_id=OLD.id) THEN
  RAISE EXCEPTION 'A used account code is immutable.' USING ERRCODE='23514',CONSTRAINT='accounts_used_code';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER account_usage_guard BEFORE UPDATE ON ucto.accounts FOR EACH ROW EXECUTE FUNCTION ucto.guard_used_account();
CREATE FUNCTION ucto.guard_used_period() RETURNS trigger LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 IF (NEW.starts_on IS DISTINCT FROM OLD.starts_on OR NEW.ends_on IS DISTINCT FROM OLD.ends_on)
  AND EXISTS(SELECT 1 FROM ucto.documents WHERE accounting_unit_id=OLD.accounting_unit_id AND period_id=OLD.id) THEN
  RAISE EXCEPTION 'A used period range is immutable.' USING ERRCODE='23514',CONSTRAINT='periods_used_range';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER period_usage_guard BEFORE UPDATE ON ucto.periods FOR EACH ROW EXECUTE FUNCTION ucto.guard_used_period();

CREATE FUNCTION ucto.save_draft(p_unit uuid,p_id uuid,p_version integer,p_payload jsonb,p_line_ids uuid[])
RETURNS uuid LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
DECLARE
 old_doc ucto.documents%ROWTYPE; period_row ucto.periods%ROWTYPE;
 v_period uuid; old_period uuid; v_partner uuid; v_date date; v_text text;
 line jsonb; normalized jsonb:='[]'::jsonb; previous_lines jsonb; v_account uuid;
 v_md numeric; v_dal numeric; v_line_text text; idx integer:=0; lines_changed boolean;
BEGIN
 IF NOT coalesce(core.can_access_unit(p_unit) AND core.has_permission(p_unit,'core.ucto.documents.write'),false) THEN
  RAISE EXCEPTION 'Permission denied.' USING ERRCODE='42501';
 END IF;
 IF current_setting('transaction_isolation')<>'read committed' THEN
  RAISE EXCEPTION 'Draft writes require read committed.' USING ERRCODE='23514',CONSTRAINT='documents_isolation';
 END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||p_unit::text,0));
 IF jsonb_typeof(p_payload) IS DISTINCT FROM 'object' OR
  (p_payload-ARRAY['periodId','postingDate','text','partnerId','lines'])<>'{}'::jsonb OR
  NOT(p_payload ?& ARRAY['periodId','postingDate','text','partnerId','lines']) THEN
  RAISE EXCEPTION 'Invalid draft fields.' USING ERRCODE='22023';
 END IF;
 IF jsonb_typeof(p_payload->'periodId') IS DISTINCT FROM 'string' OR
  jsonb_typeof(p_payload->'postingDate') IS DISTINCT FROM 'string' OR
  jsonb_typeof(p_payload->'text') IS DISTINCT FROM 'string' OR
  jsonb_typeof(p_payload->'lines') IS DISTINCT FROM 'array' OR
  jsonb_typeof(p_payload->'partnerId') NOT IN ('null','string') THEN
  RAISE EXCEPTION 'Invalid draft types.' USING ERRCODE='22023';
 END IF;
 IF (p_payload->>'periodId') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
  OR (p_payload->>'postingDate') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
  OR length(btrim(p_payload->>'text')) NOT BETWEEN 1 AND 2000
  OR jsonb_array_length(p_payload->'lines')>500 THEN
  RAISE EXCEPTION 'Invalid draft values.' USING ERRCODE='22023';
 END IF;
 v_period:=(p_payload->>'periodId')::uuid; v_partner:=(p_payload->>'partnerId')::uuid;
 v_date:=(p_payload->>'postingDate')::date; v_text:=btrim(p_payload->>'text');
 IF p_version IS NOT NULL THEN
  SELECT period_id INTO old_period FROM ucto.documents WHERE accounting_unit_id=p_unit AND id=p_id;
 END IF;
 PERFORM id FROM ucto.periods WHERE accounting_unit_id=p_unit AND id IN (v_period,old_period) ORDER BY id FOR UPDATE;
 IF p_version IS NOT NULL THEN
  SELECT * INTO old_doc FROM ucto.documents WHERE accounting_unit_id=p_unit AND id=p_id FOR UPDATE;
  IF NOT FOUND THEN RAISE EXCEPTION 'Draft not found.' USING ERRCODE='P0014'; END IF;
  IF old_doc.version<>p_version THEN RAISE EXCEPTION 'Stale document version.' USING ERRCODE='P0012'; END IF;
 END IF;
 SELECT * INTO period_row FROM ucto.periods WHERE accounting_unit_id=p_unit AND id=v_period;
 IF NOT FOUND OR period_row.status<>'open' OR v_date<period_row.starts_on OR v_date>period_row.ends_on THEN
  RAISE EXCEPTION 'Invalid period or posting date.' USING ERRCODE='23514',CONSTRAINT='documents_period';
 END IF;
 IF v_partner IS NOT NULL AND NOT EXISTS(SELECT 1 FROM core.partners WHERE accounting_unit_id=p_unit AND id=v_partner) THEN
  RAISE EXCEPTION 'Invalid partner.' USING ERRCODE='23514',CONSTRAINT='documents_partner';
 END IF;
 FOR line IN SELECT value FROM jsonb_array_elements(p_payload->'lines') LOOP
  IF jsonb_typeof(line) IS DISTINCT FROM 'object' OR (line-ARRAY['accountId','text','md','dal'])<>'{}'::jsonb
   OR NOT(line ?& ARRAY['accountId','text','md','dal']) OR
   jsonb_typeof(line->'accountId') IS DISTINCT FROM 'string' OR jsonb_typeof(line->'text') IS DISTINCT FROM 'string'
   OR jsonb_typeof(line->'md') IS DISTINCT FROM 'string' OR jsonb_typeof(line->'dal') IS DISTINCT FROM 'string' THEN
   RAISE EXCEPTION 'Invalid line fields.' USING ERRCODE='22023';
  END IF;
  IF (line->>'accountId') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
   OR length(btrim(line->>'text'))>500 OR (line->>'md') !~ '^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$'
   OR (line->>'dal') !~ '^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$' THEN
   RAISE EXCEPTION 'Invalid line values.' USING ERRCODE='22023';
  END IF;
  v_account:=(line->>'accountId')::uuid; v_md:=(line->>'md')::numeric; v_dal:=(line->>'dal')::numeric;
  v_line_text:=btrim(line->>'text');
  IF NOT((v_md>0 AND v_dal=0) OR (v_dal>0 AND v_md=0)) THEN
   RAISE EXCEPTION 'Exactly one side must be positive.' USING ERRCODE='22023';
  END IF;
  IF NOT EXISTS(SELECT 1 FROM ucto.accounts WHERE accounting_unit_id=p_unit AND id=v_account AND active AND postable) THEN
   RAISE EXCEPTION 'Invalid account.' USING ERRCODE='23514',CONSTRAINT='documents_account';
  END IF;
  normalized:=normalized||jsonb_build_array(jsonb_build_object('accountId',v_account,'text',v_line_text,'md',round(v_md,2)::text,'dal',round(v_dal,2)::text));
 END LOOP;
 SELECT coalesce(jsonb_agg(jsonb_build_object('accountId',account_id,'text',text,'md',round(md,2)::text,'dal',round(dal,2)::text) ORDER BY line_no),'[]'::jsonb)
 INTO previous_lines FROM ucto.document_lines WHERE accounting_unit_id=p_unit AND document_id=p_id;
 lines_changed:=normalized IS DISTINCT FROM previous_lines;
 IF p_version IS NOT NULL AND NOT lines_changed AND old_doc.period_id=v_period AND old_doc.posting_date=v_date
  AND old_doc.text=v_text AND old_doc.partner_id IS NOT DISTINCT FROM v_partner THEN RETURN p_id; END IF;
 IF coalesce(array_length(p_line_ids,1),0)<>jsonb_array_length(normalized) THEN
  RAISE EXCEPTION 'Incorrect server line identities.' USING ERRCODE='22023';
 END IF;
 IF p_version IS NULL THEN
  INSERT INTO ucto.documents(id,accounting_unit_id,period_id,posting_date,text,partner_id)
   VALUES(p_id,p_unit,v_period,v_date,v_text,v_partner);
 ELSE
  UPDATE ucto.documents SET period_id=v_period,posting_date=v_date,text=v_text,partner_id=v_partner,version=version+1
   WHERE accounting_unit_id=p_unit AND id=p_id;
 END IF;
 IF lines_changed THEN
  DELETE FROM ucto.document_lines WHERE accounting_unit_id=p_unit AND document_id=p_id;
  FOR line IN SELECT value FROM jsonb_array_elements(normalized) LOOP
   idx:=idx+1;
   INSERT INTO ucto.document_lines(id,accounting_unit_id,document_id,line_no,account_id,text,md,dal)
    VALUES(p_line_ids[idx],p_unit,p_id,idx,(line->>'accountId')::uuid,line->>'text',(line->>'md')::numeric,(line->>'dal')::numeric);
  END LOOP;
 END IF;
 RETURN p_id;
END $$;
REVOKE ALL ON FUNCTION ucto.save_draft(uuid,uuid,integer,jsonb,uuid[]),ucto.guard_used_account(),ucto.guard_used_period() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ucto.save_draft(uuid,uuid,integer,jsonb,uuid[]) TO mikoucto_app;
END
$draft_migration$;
