<?php
namespace App\Modules\Core\Infrastructure\Backup;
use Doctrine\DBAL\Connection;
final class BackupManifest
{
    private const NS="n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_toast%' AND n.nspname NOT LIKE 'pg_temp_%'";
    public static function capture(Connection $c): array
    {
        $c->executeStatement("SET LOCAL search_path=pg_catalog");$c->executeStatement("SET LOCAL TIME ZONE 'UTC'");$c->executeStatement("SET LOCAL DateStyle='ISO,YMD'");
        $db=$c->fetchAssociative("SELECT pg_encoding_to_char(encoding) AS encoding,datcollate,datctype,datlocprovider,daticulocale,pg_get_userbyid(datdba) AS owner,
            ARRAY(SELECT x::text FROM unnest(coalesce(datacl,acldefault('d',datdba))) x ORDER BY x::text)::text AS acl FROM pg_database WHERE datname=current_database()");
        $schema=[];$ns=self::NS;
        $schema['schemas']=$c->fetchAllAssociative("SELECT nspname,pg_get_userbyid(nspowner) AS owner,ARRAY(SELECT x::text FROM unnest(coalesce(nspacl,acldefault('n',nspowner))) x ORDER BY x::text)::text AS acl FROM pg_namespace n WHERE $ns ORDER BY nspname");
        $schema['relations']=$c->fetchAllAssociative("SELECT n.nspname,c.relname,c.relkind,pg_get_userbyid(c.relowner) AS owner,c.relrowsecurity,c.relforcerowsecurity,c.relispartition,c.relispopulated,
            pg_get_expr(c.relpartbound,c.oid) AS bounds,pg_get_partkeydef(c.oid) AS partition_key,c.relreplident,c.relpersistence,c.reloptions::text,
            ARRAY(SELECT x::text FROM unnest(coalesce(c.relacl,acldefault(CASE WHEN c.relkind='S' THEN 's'::\"char\" ELSE 'r'::\"char\" END,c.relowner))) x ORDER BY x::text)::text AS acl
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE $ns AND c.relkind IN ('r','p','S','v','m','c') ORDER BY 1,2");
        $schema['columns']=$c->fetchAllAssociative("SELECT n.nspname,c.relname,a.attname,a.attnum,format_type(a.atttypid,a.atttypmod) AS type,a.attnotnull,a.attidentity,a.attgenerated,
            pg_get_expr(d.adbin,d.adrelid) AS default_value,co.collname AS collation,cn.nspname AS collation_schema
            FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
            LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum LEFT JOIN pg_collation co ON co.oid=a.attcollation LEFT JOIN pg_namespace cn ON cn.oid=co.collnamespace
            WHERE $ns AND c.relkind IN ('r','p','v','m','c') AND a.attnum>0 AND NOT a.attisdropped ORDER BY 1,2,a.attnum");
        $schema['constraints']=$c->fetchAllAssociative("SELECT n.nspname,c.relname,k.conname,k.contype,k.convalidated,k.condeferrable,k.condeferred,pg_get_constraintdef(k.oid) AS definition FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE $ns ORDER BY 1,2,3");
        $schema['indexes']=$c->fetchAllAssociative("SELECT n.nspname,c.relname,pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE $ns ORDER BY 1,2");
        $schema['triggers']=$c->fetchAllAssociative("SELECT n.nspname,c.relname,t.tgname,t.tgenabled,pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE $ns AND NOT t.tgisinternal ORDER BY 1,2,3");
        $schema['policies']=$c->fetchAllAssociative("SELECT n.nspname,c.relname,p.polname,p.polcmd,p.polpermissive,ARRAY(SELECT CASE WHEN r=0 THEN 'PUBLIC' ELSE pg_get_userbyid(r) END FROM unnest(p.polroles) r ORDER BY 1)::text AS roles,pg_get_expr(p.polqual,p.polrelid) AS using_expression,pg_get_expr(p.polwithcheck,p.polrelid) AS check_expression FROM pg_policy p JOIN pg_class c ON c.oid=p.polrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE $ns ORDER BY 1,2,3");
        $schema['functions']=$c->fetchAllAssociative("SELECT n.nspname,p.proname,pg_get_function_identity_arguments(p.oid) AS arguments,pg_get_userbyid(p.proowner) AS owner,pg_get_functiondef(p.oid) AS definition,ARRAY(SELECT x::text FROM unnest(coalesce(p.proacl,acldefault('f',p.proowner))) x ORDER BY x::text)::text AS acl FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE $ns AND p.prokind IN ('f','p','w') ORDER BY 1,2,3");
        $schema['views']=$c->fetchAllAssociative("SELECT n.nspname,c.relname,pg_get_viewdef(c.oid) AS definition FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE $ns AND c.relkind IN ('v','m') ORDER BY 1,2");
        $schema['types']=$c->fetchAllAssociative("SELECT n.nspname,t.typname,t.typtype,t.typnotnull,t.typdefault,CASE WHEN t.typbasetype<>0 THEN format_type(t.typbasetype,t.typtypmod) END AS base_type,ARRAY(SELECT enumlabel FROM pg_enum WHERE enumtypid=t.oid ORDER BY enumsortorder)::text AS labels FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace WHERE $ns AND t.typtype IN ('e','d') ORDER BY 1,2");
        $schema['extensions']=$c->fetchAllAssociative('SELECT extname,extversion,n.nspname,pg_get_userbyid(extowner) AS owner FROM pg_extension JOIN pg_namespace n ON n.oid=extnamespace ORDER BY extname');
        $schema['default_acl']=$c->fetchAllAssociative("SELECT pg_get_userbyid(defaclrole) AS role,coalesce(n.nspname,'') AS schema,defaclobjtype,ARRAY(SELECT x::text FROM unnest(defaclacl) x ORDER BY x::text)::text AS acl FROM pg_default_acl LEFT JOIN pg_namespace n ON n.oid=defaclnamespace ORDER BY 1,2,3");
        $schema['roles']=$c->fetchAllAssociative('SELECT rolname,rolsuper,rolinherit,rolcreaterole,rolcreatedb,rolcanlogin,rolreplication,rolconnlimit,rolvaliduntil,rolbypassrls,rolconfig::text FROM pg_roles ORDER BY rolname');
        $schema['memberships']=$c->fetchAllAssociative('SELECT pg_get_userbyid(roleid) AS role,pg_get_userbyid(member) AS member,pg_get_userbyid(grantor) AS grantor,admin_option,inherit_option,set_option FROM pg_auth_members ORDER BY 1,2,3');
        if ((int)$c->fetchOne('SELECT count(oid) FROM pg_largeobject_metadata')!==0||(int)$c->fetchOne("SELECT count(*) FROM pg_foreign_table")!==0) { throw new \RuntimeException('This project verifier does not cover large objects or foreign tables.'); }
        $tables=[];
        foreach ($schema['relations'] as $relation) {
            if (!in_array($relation['relkind'],['r','p','m'],true)||$relation['relkind']==='m'&&!$relation['relispopulated']) { continue; }
            $name=$relation['nspname'].'.'.$relation['relname'];$quoted=$c->quoteIdentifier($relation['nspname']).'.'.$c->quoteIdentifier($relation['relname']);
            $hash=hash_init('sha256');$rowHashes=[];
            foreach ($c->executeQuery('SELECT to_jsonb(t)::text AS row FROM '.$quoted.' t ORDER BY to_jsonb(t)::text COLLATE "C"')->iterateColumn() as $row) {
                hash_update($hash,strlen($row).':'.$row."\n");$rowHashes[]=hash('sha256',$row);
            }
            $tables[$name]=['count'=>count($rowHashes),'sha256'=>hash_final($hash),'rows'=>$rowHashes];
        }
        $sequences=[];
        foreach ($c->fetchAllAssociative("SELECT n.nspname,c.relname,format_type(s.seqtypid,NULL) AS type,s.seqstart::text,s.seqincrement::text,s.seqmin::text,s.seqmax::text,s.seqcache::text,s.seqcycle FROM pg_sequence s JOIN pg_class c ON c.oid=s.seqrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE $ns ORDER BY 1,2") as $sequence) {
            $value=$c->fetchAssociative('SELECT last_value::text,is_called FROM '.$c->quoteIdentifier($sequence['nspname']).'.'.$c->quoteIdentifier($sequence['relname']));
            $sequences[$sequence['nspname'].'.'.$sequence['relname']]=['definition'=>$sequence,'value'=>$value];
        }
        $audit=$c->fetchAssociative('SELECT sealed_rows::text,ok,first_bad_seq::text FROM core.audit_verify()');
        if (!$audit['ok']) { throw new \RuntimeException('Audit chain verification failed.'); }
        $result=['formatVersion'=>1,'database'=>$db,'schema'=>$schema,'tables'=>$tables,'sequences'=>$sequences,'audit'=>$audit];
        $result['fingerprint']=hash('sha256',json_encode([$db,$schema,$tables],JSON_THROW_ON_ERROR));return $result;
    }
    public static function verify(array $expected,array $actual): void
    {
        if (($expected['formatVersion']??0)!==1||($actual['formatVersion']??0)!==1) { throw new \RuntimeException('Unsupported backup manifest format. Create a fresh backup.'); }
        if (!$expected['audit']['ok']||!$actual['audit']['ok']) { throw new \RuntimeException('Audit chain is not valid.'); }
        foreach (['database','schema','tables','audit'] as $part) { if ($expected[$part]!==$actual[$part]) { throw new \RuntimeException('Restore mismatch in '.$part.'.'); } }
        foreach ($expected['sequences'] as $name=>$sequence) {
            $restored=$actual['sequences'][$name]??null;
            if (!$restored||$sequence['definition']!==$restored['definition']) { throw new \RuntimeException('Sequence definition mismatch.'); }
            if ($sequence['value']['is_called']&&!$restored['value']['is_called']&&$sequence['value']['last_value']===$restored['value']['last_value']) { throw new \RuntimeException('Restored sequence would reuse a consumed value.'); }
            if (!$sequence['definition']['seqcycle']) {
                $increment=(int)$sequence['definition']['seqincrement'];
                // Counters are not MVCC data. pg_dump can observe a later value
                // than the exported snapshot, but must never move it backwards.
                if ($increment>0&&(int)$restored['value']['last_value']<(int)$sequence['value']['last_value']||$increment<0&&(int)$restored['value']['last_value']>(int)$sequence['value']['last_value']) { throw new \RuntimeException('Restored sequence moved backwards.'); }
            }
        }
    }
    public static function preserved(array $before,array $after): void
    {
        foreach ($before['tables'] as $name=>$table) {
            $remaining=array_count_values($after['tables'][$name]['rows']??[]);
            foreach ($table['rows'] as $hash) { if (($remaining[$hash]??0)<1) { throw new \RuntimeException('Migration changed an original row in '.$name.'.'); } $remaining[$hash]--; }
        }
    }
}
