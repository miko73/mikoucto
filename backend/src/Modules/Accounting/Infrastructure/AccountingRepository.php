<?php

namespace App\Modules\Accounting\Infrastructure;

use Doctrine\DBAL\Connection;

final class AccountingRepository
{
    public function __construct(private Connection $connection) { }
    public static function table(string $kind): string
    {
        return match($kind) { 'accounts'=>'ucto.accounts','periods'=>'ucto.periods',default=>throw new \LogicException('Unknown accounting resource.') };
    }
    public static function requests(string $kind): string
    {
        return match($kind) { 'accounts'=>'ucto.account_create_requests','periods'=>'ucto.period_create_requests',default=>throw new \LogicException('Unknown accounting resource.') };
    }
    public function find(string $kind,string $unit,string $id): array|false
    {
        return $this->connection->fetchAssociative('SELECT * FROM '.self::table($kind).' WHERE accounting_unit_id=? AND id=?',[$unit,$id]);
    }
    public function listing(string $kind,string $unit,int $page,int $size,string $search,?bool $active,?string $on): array
    {
        $where='accounting_unit_id=?'; $params=[$unit];
        if ($search!=='') {
            $escaped='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
            $where.=$kind==='accounts'?" AND (name ILIKE ? ESCAPE '!' OR code ILIKE ? ESCAPE '!')":" AND name ILIKE ? ESCAPE '!'";
            $params[]=$escaped; if ($kind==='accounts') { $params[]=$escaped; }
        }
        if ($active!==null) { $where.=' AND active=?'; $params[]=$active?'true':'false'; }
        if ($on!==null) { $where.=' AND starts_on<=?::date AND ends_on>=?::date'; array_push($params,$on,$on); }
        $table=self::table($kind); $order=$kind==='accounts'?'code,id':'starts_on,id';
        $total=(int)$this->connection->fetchOne("SELECT count(*) FROM $table WHERE $where",$params);
        $rows=$this->connection->fetchAllAssociative("SELECT * FROM $table WHERE $where ORDER BY $order LIMIT ? OFFSET ?",[...$params,$size,($page-1)*$size]);
        return [$rows,$total];
    }
    public function create(string $kind,string $unit,string $id,array $values): array
    {
        $columns=implode(',',array_keys($values)); $holders=implode(',',array_fill(0,count($values)+2,'?'));
        return $this->connection->fetchAssociative('INSERT INTO '.self::table($kind)."(id,accounting_unit_id,$columns) VALUES ($holders) RETURNING *",[$id,$unit,...self::parameters($values)]);
    }
    public function update(string $kind,string $unit,string $id,int $version,array $values): array|false
    {
        $set=implode(',',array_map(static fn($column)=>$column.'=?',array_keys($values)));
        return $this->connection->fetchAssociative('UPDATE '.self::table($kind)." SET $set,version=version+1 WHERE accounting_unit_id=? AND id=? AND version=? RETURNING *",[...self::parameters($values),$unit,$id,$version]);
    }
    private static function parameters(array $values): array { return array_map(static fn($v)=>is_bool($v)?($v?'true':'false'):$v,array_values($values)); }
    public function history(string $kind,string $id,int $page,int $size): array
    {
        $params=[self::table($kind),$id];
        $total=(int)$this->connection->fetchOne('SELECT count(*) FROM core.audit_log WHERE table_name=? AND row_id=?',$params);
        $rows=$this->connection->fetchAllAssociative('SELECT id,occurred_at,user_id,request_id,operation,old_data,new_data,to_jsonb(changed_cols)::text AS changed_cols FROM core.audit_log WHERE table_name=? AND row_id=? ORDER BY occurred_at DESC,id DESC LIMIT ? OFFSET ?',[...$params,$size,($page-1)*$size]);
        foreach ($rows as &$row) { foreach (['old_data','new_data','changed_cols'] as $field) { if ($row[$field]!==null) { $row[$field]=json_decode($row[$field],true,512,JSON_THROW_ON_ERROR); } } }
        return [$rows,$total];
    }
}
