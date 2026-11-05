<?php

namespace App\Modules\Partners\Infrastructure;

use App\Modules\Partners\Application\PartnerInputValidator;
use Doctrine\DBAL\Connection;

final class PartnerRepository
{
    public function __construct(private Connection $connection) { }

    public function find(string $unit,string $id): array|false
    {
        return $this->connection->fetchAssociative('SELECT * FROM core.partners WHERE accounting_unit_id=? AND id=?',[$unit,$id]);
    }

    public function list(string $unit,int $page,int $size,string $search,?bool $active): array
    {
        $where = 'accounting_unit_id=?'; $params = [$unit];
        if ($search !== '') {
            $where .= " AND (name ILIKE ? ESCAPE '!' OR registration_number ILIKE ? ESCAPE '!')";
            $search = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
            $params[]=$search; $params[]=$search;
        }
        if ($active !== null) { $where .= ' AND active=?'; $params[]=$active ? 'true':'false'; }
        $total = (int)$this->connection->fetchOne('SELECT count(*) FROM core.partners WHERE '.$where,$params);
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM core.partners WHERE '.$where.' ORDER BY name,id LIMIT ? OFFSET ?', [...$params,$size,($page-1)*$size]);
        return [$rows,$total];
    }

    public function create(string $unit,string $id,array $values): array
    {
        $columns = array_values(PartnerInputValidator::FIELDS);
        return $this->connection->fetchAssociative('INSERT INTO core.partners (id,accounting_unit_id,'.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns)+2,'?')).') RETURNING *',
            [$id,$unit,...array_map(static fn (mixed $value): mixed => is_bool($value) ? ($value?'true':'false'):$value,array_values($values))]);
    }

    public function update(string $unit,string $id,int $version,array $values): array|false
    {
        $assignments = array_map(static fn (string $column): string => $column.'=?',array_values(PartnerInputValidator::FIELDS));
        return $this->connection->fetchAssociative('UPDATE core.partners SET '.implode(',',$assignments).',version=version+1 WHERE accounting_unit_id=? AND id=? AND version=? RETURNING *',
            [...array_map(static fn (mixed $value): mixed => is_bool($value) ? ($value?'true':'false'):$value,array_values($values)),$unit,$id,$version]);
    }

    public function history(string $id,int $page,int $size): array
    {
        $total = (int)$this->connection->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='core.partners' AND row_id=?",[$id]);
        $rows = $this->connection->fetchAllAssociative("SELECT id,occurred_at,user_id,request_id,client,operation,old_data,new_data,to_jsonb(changed_cols)::text AS changed_cols FROM core.audit_log WHERE table_name='core.partners' AND row_id=? ORDER BY occurred_at DESC,id DESC LIMIT ? OFFSET ?",[$id,$size,($page-1)*$size]);
        foreach ($rows as &$row) {
            foreach (['old_data','new_data','changed_cols'] as $field) { if ($row[$field] !== null) { $row[$field] = json_decode($row[$field],true,512,JSON_THROW_ON_ERROR); } }
        }
        return [$rows,$total];
    }
}
