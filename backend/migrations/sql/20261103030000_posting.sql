DO $posting_migration$
BEGIN
INSERT INTO core.permissions(code,name) VALUES ('core.ucto.documents.post','Zauctovat doklad'),('core.ucto.periods.close','Uzavrit obdobi');
INSERT INTO core.role_permissions(role_code,permission_code) VALUES
 ('administrator','core.ucto.documents.post'),('accountant','core.ucto.documents.post'),('administrator','core.ucto.periods.close');
ALTER TABLE ucto.documents DROP CONSTRAINT documents_status_check;
ALTER TABLE ucto.documents ADD COLUMN number varchar(64), ADD COLUMN sequence_number bigint,
 ADD COLUMN posted_at timestamptz, ADD COLUMN posted_by uuid REFERENCES core.users(id);
ALTER TABLE ucto.documents ADD CONSTRAINT documents_posted_state CHECK
 ((status='draft' AND number IS NULL AND sequence_number IS NULL AND posted_at IS NULL AND posted_by IS NULL)
 OR (status='posted' AND number IS NOT NULL AND number ~ '^INT/[0-9]{8}/[0-9]{6,19}$' AND sequence_number>0
     AND sequence_number IS NOT NULL AND posted_at IS NOT NULL AND posted_by IS NOT NULL));
CREATE UNIQUE INDEX documents_number_unique ON ucto.documents(accounting_unit_id,number) WHERE number IS NOT NULL;
CREATE UNIQUE INDEX documents_sequence_unique ON ucto.documents(accounting_unit_id,period_id,sequence_number) WHERE sequence_number IS NOT NULL;
ALTER TABLE ucto.periods DROP CONSTRAINT periods_status_check;
ALTER TABLE ucto.periods ADD COLUMN closed_at timestamptz, ADD COLUMN closed_by uuid REFERENCES core.users(id);
ALTER TABLE ucto.periods ADD CONSTRAINT periods_closed_state CHECK
 ((status='open' AND closed_at IS NULL AND closed_by IS NULL) OR (status='closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL));

CREATE TABLE ucto.document_sequences (
 accounting_unit_id uuid NOT NULL,period_id uuid NOT NULL,series text NOT NULL CHECK(series='INT'),last_number bigint NOT NULL CHECK(last_number>0),
 PRIMARY KEY(accounting_unit_id,period_id,series),
 FOREIGN KEY(accounting_unit_id,period_id) REFERENCES ucto.periods(accounting_unit_id,id)
);
REVOKE ALL ON ucto.document_sequences FROM PUBLIC,mikoucto_app,mikoucto_read;

CREATE FUNCTION ucto.assert_postable(p_unit uuid,p_id uuid,p_period uuid,p_date date) RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
DECLARE n bigint; md_sum numeric; dal_sum numeric;
BEGIN
 IF NOT coalesce(core.can_access_unit(p_unit) AND core.has_permission(p_unit,'core.ucto.documents.post'),false) THEN
  RAISE EXCEPTION 'Permission denied.' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM ucto.periods WHERE accounting_unit_id=p_unit AND id=p_period AND status='open' AND p_date BETWEEN starts_on AND ends_on) THEN
  RAISE EXCEPTION 'Period is not open for posting.' USING ERRCODE='23514',CONSTRAINT='posting_period'; END IF;
 SELECT count(*),coalesce(sum(md),0),coalesce(sum(dal),0) INTO n,md_sum,dal_sum FROM ucto.document_lines WHERE accounting_unit_id=p_unit AND document_id=p_id;
 IF n<2 OR md_sum<>dal_sum THEN
  RAISE EXCEPTION 'Document must contain two or more balanced lines.' USING ERRCODE='23514',CONSTRAINT='posting_balance'; END IF;
 IF EXISTS(SELECT 1 FROM ucto.document_lines l JOIN ucto.accounts a ON a.accounting_unit_id=l.accounting_unit_id AND a.id=l.account_id
  WHERE l.accounting_unit_id=p_unit AND l.document_id=p_id AND (NOT a.active OR NOT a.postable)) THEN
  RAISE EXCEPTION 'Account is not available for posting.' USING ERRCODE='23514',CONSTRAINT='posting_account'; END IF;
END $$;

CREATE FUNCTION ucto.guard_document_business() RETURNS trigger LANGUAGE plpgsql VOLATILE
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 -- The migration owner is trusted for audited maintenance/deletion. Runtime
 -- SECURITY DEFINER calls still have session_user=mikoucto_app.
 IF TG_OP='DELETE' AND session_user='mikoucto_owner' THEN RETURN OLD; END IF;
 IF TG_OP<>'INSERT' AND OLD.status='posted' THEN
  RAISE EXCEPTION 'Posted document is immutable.' USING ERRCODE='23514',CONSTRAINT='posting_immutable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 IF current_setting('transaction_isolation')<>'read committed' THEN
  RAISE EXCEPTION 'Document writes require read committed.' USING ERRCODE='23514',CONSTRAINT='posting_isolation'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||NEW.accounting_unit_id::text,0));
 PERFORM id FROM ucto.periods WHERE accounting_unit_id=NEW.accounting_unit_id AND id=NEW.period_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM ucto.periods WHERE accounting_unit_id=NEW.accounting_unit_id AND id=NEW.period_id AND status='open'
  AND NEW.posting_date BETWEEN starts_on AND ends_on) THEN
  RAISE EXCEPTION 'Document requires an open period.' USING ERRCODE='23514',CONSTRAINT='posting_period'; END IF;
 IF NEW.status='posted' THEN
  IF TG_OP='INSERT' OR current_user<>'mikoucto_owner' THEN RAISE EXCEPTION 'Use the posting command.' USING ERRCODE='42501'; END IF;
  PERFORM ucto.assert_postable(NEW.accounting_unit_id,NEW.id,NEW.period_id,NEW.posting_date);
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER document_business_guard BEFORE INSERT OR UPDATE OR DELETE ON ucto.documents FOR EACH ROW EXECUTE FUNCTION ucto.guard_document_business();
CREATE FUNCTION ucto.guard_posted_line() RETURNS trigger LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp AS $$
DECLARE v_unit uuid; v_document uuid;
BEGIN
 IF TG_OP='DELETE' AND session_user='mikoucto_owner' THEN RETURN OLD; END IF;
 v_unit:=CASE WHEN TG_OP='DELETE' THEN OLD.accounting_unit_id ELSE NEW.accounting_unit_id END;
 v_document:=CASE WHEN TG_OP='DELETE' THEN OLD.document_id ELSE NEW.document_id END;
 IF EXISTS(SELECT 1 FROM ucto.documents d JOIN ucto.periods p ON p.accounting_unit_id=d.accounting_unit_id AND p.id=d.period_id
  WHERE d.accounting_unit_id=v_unit AND d.id=v_document AND (d.status='posted' OR p.status='closed')) THEN
  RAISE EXCEPTION 'Posted or closed-period line is immutable.' USING ERRCODE='23514',CONSTRAINT='posting_immutable_line'; END IF;
 IF TG_OP='UPDATE' AND (OLD.document_id IS DISTINCT FROM NEW.document_id OR OLD.accounting_unit_id IS DISTINCT FROM NEW.accounting_unit_id) THEN
  RAISE EXCEPTION 'Line identity is immutable.' USING ERRCODE='23514',CONSTRAINT='posting_line_identity'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER posted_line_guard BEFORE INSERT OR UPDATE OR DELETE ON ucto.document_lines FOR EACH ROW EXECUTE FUNCTION ucto.guard_posted_line();

ALTER FUNCTION ucto.save_draft(uuid,uuid,integer,jsonb,uuid[]) RENAME TO save_draft_72;
REVOKE ALL ON FUNCTION ucto.save_draft_72(uuid,uuid,integer,jsonb,uuid[]) FROM PUBLIC,mikoucto_app,mikoucto_read;
CREATE FUNCTION ucto.save_draft(p_unit uuid,p_id uuid,p_version integer,p_payload jsonb,p_line_ids uuid[]) RETURNS uuid
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 IF NOT coalesce(core.can_access_unit(p_unit) AND core.has_permission(p_unit,'core.ucto.documents.write'),false) THEN
  RAISE EXCEPTION 'Permission denied.' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||p_unit::text,0));
 IF EXISTS(SELECT 1 FROM ucto.documents WHERE accounting_unit_id=p_unit AND id=p_id AND status='posted') THEN
  RAISE EXCEPTION 'Posted document is immutable.' USING ERRCODE='23514',CONSTRAINT='posting_immutable'; END IF;
 RETURN ucto.save_draft_72(p_unit,p_id,p_version,p_payload,p_line_ids);
END $$;

CREATE FUNCTION ucto.post_document(p_unit uuid,p_id uuid,p_version integer) RETURNS uuid
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
DECLARE d ucto.documents%ROWTYPE; p ucto.periods%ROWTYPE; v_period uuid; v_sequence bigint; v_number text;
BEGIN
 IF NOT coalesce(core.can_access_unit(p_unit) AND core.has_permission(p_unit,'core.ucto.documents.post'),false) THEN
  RAISE EXCEPTION 'Permission denied.' USING ERRCODE='42501'; END IF;
 IF current_setting('transaction_isolation')<>'read committed' THEN
  RAISE EXCEPTION 'Posting requires read committed.' USING ERRCODE='23514',CONSTRAINT='posting_isolation'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||p_unit::text,0));
 SELECT period_id INTO v_period FROM ucto.documents WHERE accounting_unit_id=p_unit AND id=p_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'Not found.' USING ERRCODE='P0014'; END IF;
 SELECT * INTO p FROM ucto.periods WHERE accounting_unit_id=p_unit AND id=v_period FOR UPDATE;
 SELECT * INTO d FROM ucto.documents WHERE accounting_unit_id=p_unit AND id=p_id FOR UPDATE;
 IF p_version IS NULL OR d.version<>p_version THEN RAISE EXCEPTION 'Stale version.' USING ERRCODE='P0012'; END IF;
 IF d.status='posted' THEN RETURN p_id; END IF;
 PERFORM ucto.assert_postable(p_unit,p_id,d.period_id,d.posting_date);
 INSERT INTO ucto.document_sequences(accounting_unit_id,period_id,series,last_number) VALUES(p_unit,v_period,'INT',1)
 ON CONFLICT(accounting_unit_id,period_id,series) DO UPDATE SET last_number=ucto.document_sequences.last_number+1 RETURNING last_number INTO v_sequence;
 v_number:='INT/'||to_char(p.starts_on,'YYYYMMDD')||'/'||lpad(v_sequence::text,greatest(6,length(v_sequence::text)),'0');
 UPDATE ucto.documents SET status='posted',number=v_number,sequence_number=v_sequence,posted_at=clock_timestamp(),
  posted_by=nullif(current_setting('app.user_id',true),'')::uuid,version=version+1 WHERE accounting_unit_id=p_unit AND id=p_id;
 RETURN p_id;
END $$;

CREATE FUNCTION ucto.guard_period_business() RETURNS trigger LANGUAGE plpgsql VOLATILE
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 IF OLD.status='closed' THEN RAISE EXCEPTION 'Closed period is immutable.' USING ERRCODE='23514',CONSTRAINT='posting_closed_period'; END IF;
 IF NEW.status='closed' THEN
  IF current_user<>'mikoucto_owner' OR NOT coalesce(core.can_access_unit(NEW.accounting_unit_id) AND core.has_permission(NEW.accounting_unit_id,'core.ucto.periods.close'),false) THEN
   RAISE EXCEPTION 'Use the period closing command.' USING ERRCODE='42501'; END IF;
  IF current_setting('transaction_isolation')<>'read committed' THEN
   RAISE EXCEPTION 'Closing requires read committed.' USING ERRCODE='23514',CONSTRAINT='posting_isolation'; END IF;
  IF EXISTS(SELECT 1 FROM ucto.documents WHERE accounting_unit_id=OLD.accounting_unit_id AND period_id=OLD.id AND status='draft') THEN
   RAISE EXCEPTION 'Period contains drafts.' USING ERRCODE='23514',CONSTRAINT='posting_remaining_drafts'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER period_business_guard BEFORE UPDATE ON ucto.periods FOR EACH ROW EXECUTE FUNCTION ucto.guard_period_business();
CREATE FUNCTION ucto.close_period(p_unit uuid,p_id uuid,p_version integer) RETURNS uuid
LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,pg_temp AS $$
DECLARE p ucto.periods%ROWTYPE;
BEGIN
 IF NOT coalesce(core.can_access_unit(p_unit) AND core.has_permission(p_unit,'core.ucto.periods.close'),false) THEN
  RAISE EXCEPTION 'Permission denied.' USING ERRCODE='42501'; END IF;
 IF current_setting('transaction_isolation')<>'read committed' THEN
  RAISE EXCEPTION 'Closing requires read committed.' USING ERRCODE='23514',CONSTRAINT='posting_isolation'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('ucto.accounts:'||p_unit::text,0));
 SELECT * INTO p FROM ucto.periods WHERE accounting_unit_id=p_unit AND id=p_id FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Not found.' USING ERRCODE='P0014'; END IF;
 IF p_version IS NULL OR p.version<>p_version THEN RAISE EXCEPTION 'Stale version.' USING ERRCODE='P0012'; END IF;
 IF p.status='closed' THEN RETURN p_id; END IF;
 UPDATE ucto.periods SET status='closed',closed_at=clock_timestamp(),closed_by=nullif(current_setting('app.user_id',true),'')::uuid,
  version=version+1 WHERE accounting_unit_id=p_unit AND id=p_id;
 RETURN p_id;
END $$;

DO $$
DECLARE item record;
BEGIN
 FOR item IN SELECT * FROM (VALUES('document_post_requests','documents','documents.post','core.ucto.documents.post'),
  ('period_close_requests','periods','periods.close','core.ucto.periods.close')) AS x(table_name,resource,operation,permission) LOOP
  EXECUTE format($sql$CREATE TABLE ucto.%I (
   id uuid PRIMARY KEY,accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id),user_id uuid NOT NULL REFERENCES core.users(id),
   operation text NOT NULL CHECK(operation=%L),key varchar(128) NOT NULL CHECK(length(key)>0),request_hash char(64) NOT NULL,
   resource_id uuid NOT NULL,response jsonb NOT NULL,created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
   expires_at timestamptz NOT NULL DEFAULT clock_timestamp()+interval '24 hours',UNIQUE(accounting_unit_id,user_id,operation,key),
   FOREIGN KEY(accounting_unit_id,resource_id) REFERENCES ucto.%I(accounting_unit_id,id),
   CHECK(jsonb_typeof(response)='object' AND response ?& ARRAY['id','accountingUnitId','version']
    AND response->>'id'=resource_id::text AND response->>'accountingUnitId'=accounting_unit_id::text))$sql$,item.table_name,item.operation,item.resource);
  EXECUTE format('REVOKE ALL ON ucto.%I FROM PUBLIC,mikoucto_app,mikoucto_read',item.table_name);
  EXECUTE format('GRANT SELECT,INSERT,UPDATE ON ucto.%I TO mikoucto_app',item.table_name);
  EXECUTE format('ALTER TABLE ucto.%I ENABLE ROW LEVEL SECURITY',item.table_name);
  EXECUTE format('CREATE POLICY command_scope ON ucto.%I TO mikoucto_app USING (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,%L) AND user_id=nullif(current_setting(''app.user_id'',true),'''')::uuid) WITH CHECK (core.can_access_unit(accounting_unit_id) AND core.has_permission(accounting_unit_id,%L) AND user_id=nullif(current_setting(''app.user_id'',true),'''')::uuid)',item.table_name,item.permission,item.permission);
  EXECUTE format('CREATE INDEX ON ucto.%I(expires_at)',item.table_name);
 END LOOP;
END $$;
REVOKE ALL ON FUNCTION ucto.assert_postable(uuid,uuid,uuid,date),ucto.guard_document_business(),ucto.guard_posted_line(),ucto.guard_period_business(),
 ucto.save_draft(uuid,uuid,integer,jsonb,uuid[]),ucto.post_document(uuid,uuid,integer),ucto.close_period(uuid,uuid,integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ucto.save_draft(uuid,uuid,integer,jsonb,uuid[]),ucto.post_document(uuid,uuid,integer),ucto.close_period(uuid,uuid,integer) TO mikoucto_app;
END
$posting_migration$;
