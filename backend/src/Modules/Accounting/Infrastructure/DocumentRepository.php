<?php
namespace App\Modules\Accounting\Infrastructure;
use App\Modules\Accounting\ApiResource\Document;
use App\Modules\Accounting\ApiResource\DocumentLine;
use App\Modules\Accounting\ApiResource\DocumentRelation;
use App\Modules\Accounting\ApiResource\DocumentRelations;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class DocumentRepository
{
    private const SELECT="SELECT d.*,round(coalesce(t.md,0),2)::text AS total_md,round(coalesce(t.dal,0),2)::text AS total_dal
        FROM ucto.documents d LEFT JOIN LATERAL(SELECT sum(md) AS md,sum(dal) AS dal FROM ucto.document_lines l
        WHERE l.accounting_unit_id=d.accounting_unit_id AND l.document_id=d.id) t ON true";
    public function __construct(private Connection $connection) { }
    public function find(string $unit,string $id): ?Document
    {
        $row=$this->connection->fetchAssociative(self::SELECT.' WHERE d.accounting_unit_id=? AND d.id=?',[$unit,$id]);
        if ($row===false) { return null; }
        $document=Document::fromRow($row);
        $document->lines=array_map(static fn($line)=>new DocumentLine($line['id'],(int)$line['line_no'],$line['account_id'],$line['text'],$line['md'],$line['dal']),
            $this->connection->fetchAllAssociative('SELECT id,line_no,account_id,text,round(md,2)::text AS md,round(dal,2)::text AS dal FROM ucto.document_lines WHERE accounting_unit_id=? AND document_id=? ORDER BY line_no',[$unit,$id]));
        return $document;
    }
    public function save(string $unit,string $id,?int $version,array $values): void
    {
        $ids=array_map(static fn()=>Uuid::v7()->toRfc4122(),$values['lines']);
        $this->connection->fetchOne('SELECT ucto.save_draft(?::uuid,?::uuid,?::integer,?::jsonb,?::uuid[])',
            [$unit,$id,$version,json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'{'.implode(',',$ids).'}']);
    }
    public function listing(string $unit,array $query): array
    {
        [$page,$size,$search,$period,$from,$to]=$query; $where='d.accounting_unit_id=?'; $params=[$unit];
        if ($search!=='') { $where.=" AND d.text ILIKE ? ESCAPE '!'"; $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%'; }
        if ($period!==null) { $where.=' AND d.period_id=?'; $params[]=$period; }
        if ($from!==null) { $where.=' AND d.posting_date>=?::date'; $params[]=$from; }
        if ($to!==null) { $where.=' AND d.posting_date<=?::date'; $params[]=$to; }
        $total=(int)$this->connection->fetchOne('SELECT count(*) FROM ucto.documents d WHERE '.$where,$params);
        return [$this->connection->fetchAllAssociative(self::SELECT.' WHERE '.$where.' ORDER BY d.posting_date DESC,d.id DESC LIMIT ? OFFSET ?',[...$params,$size,($page-1)*$size]),$total];
    }
    public function journal(string $unit,array $query): array
    {
        [$page,$size,$search,$period,$from,$to,$account]=$query;
        $where="d.accounting_unit_id=? AND d.status='posted'"; $params=[$unit];
        if ($period!==null) { $where.=' AND d.period_id=?'; $params[]=$period; }
        if ($account!==null) { $where.=' AND l.account_id=?'; $params[]=$account; }
        if ($from!==null) { $where.=' AND d.posting_date>=?::date'; $params[]=$from; }
        if ($to!==null) { $where.=' AND d.posting_date<=?::date'; $params[]=$to; }
        if ($search!=='') {
            $where.=" AND (d.text ILIKE ? ESCAPE '!' OR l.text ILIKE ? ESCAPE '!' OR d.number ILIKE ? ESCAPE '!')";
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%'; array_push($params,$pattern,$pattern,$pattern);
        }
        $join=' FROM ucto.documents d JOIN ucto.document_lines l ON l.accounting_unit_id=d.accounting_unit_id AND l.document_id=d.id
            JOIN ucto.accounts a ON a.accounting_unit_id=l.accounting_unit_id AND a.id=l.account_id WHERE '.$where;
        $total=(int)$this->connection->fetchOne('SELECT count(*)'.$join,$params);
        $rows=$this->connection->fetchAllAssociative('SELECT l.id,d.accounting_unit_id,d.id AS document_id,d.period_id,d.number,d.posting_date,l.line_no,
            l.account_id,a.code AS account_code,a.name AS account_name,d.text AS document_text,l.text,round(l.md,2)::text AS md,round(l.dal,2)::text AS dal'
            .$join.' ORDER BY d.posting_date DESC,d.id DESC,l.line_no LIMIT ? OFFSET ?',[...$params,$size,($page-1)*$size]);
        return [$rows,$total];
    }
    public function relations(string $unit,string $id): DocumentRelations
    {
        $result=new DocumentRelations($id);
        $rows=$this->connection->fetchAllAssociative('SELECT r.*,d.id AS related_id,d.number,d.period_id,d.posting_date FROM ucto.document_reversals r
            JOIN ucto.documents d ON d.accounting_unit_id=r.accounting_unit_id AND d.id=CASE WHEN r.original_document_id=? THEN r.reversal_document_id ELSE r.original_document_id END
            WHERE r.accounting_unit_id=? AND (r.original_document_id=? OR r.reversal_document_id=?)',[$id,$unit,$id,$id]);
        foreach ($rows as $row) {
            $field=$row['original_document_id']===$id?'reversal':'reverses';
            $result->$field=new DocumentRelation($row['related_id'],$row['number'],$row['period_id'],$row['posting_date'],$row['reason'],$row['created_by'],$row['created_at']);
        }
        $corrections=$this->connection->fetchAllAssociative('SELECT c.*,r.reason,d.id AS related_id,d.number,d.period_id,d.posting_date,d.status
            FROM ucto.document_corrections c JOIN ucto.document_reversals r
            ON r.accounting_unit_id=c.accounting_unit_id AND r.original_document_id=c.original_document_id
            JOIN ucto.documents d ON d.accounting_unit_id=c.accounting_unit_id
            AND ((c.correction_document_id=? AND d.id IN (c.original_document_id,c.reversal_document_id))
                OR (c.correction_document_id<>? AND d.id=c.correction_document_id))
            WHERE c.accounting_unit_id=? AND ? IN (c.original_document_id,c.reversal_document_id,c.correction_document_id)',[$id,$id,$unit,$id]);
        foreach ($corrections as $row) {
            $field=$row['correction_document_id']!==$id?'correction':($row['related_id']===$row['original_document_id']?'corrects':'reversal');
            $result->$field=new DocumentRelation($row['related_id'],$row['number'],$row['period_id'],$row['posting_date'],$row['reason'],$row['created_by'],$row['created_at'],$row['status']);
        }
        return $result;
    }
    public function history(string $id,int $page,int $size): array
    {
        $where="(table_name='ucto.documents' AND row_id=?) OR (table_name='ucto.document_lines' AND coalesce(new_data->>'document_id',old_data->>'document_id')=?)
            OR (table_name='ucto.document_reversals' AND (new_data->>'original_document_id'=? OR new_data->>'reversal_document_id'=?))
            OR (table_name='ucto.document_corrections' AND ? IN (new_data->>'original_document_id',new_data->>'reversal_document_id',new_data->>'correction_document_id'))";
        $total=(int)$this->connection->fetchOne('SELECT count(*) FROM core.audit_log WHERE '.$where,[$id,$id,$id,$id,$id]);
        // JSON numeric values would be decoded as floats by PHP. Convert the
        // original exact database amounts to strings before decoding history.
        $rows=$this->connection->fetchAllAssociative('SELECT id,table_name,row_id,occurred_at,user_id,request_id,operation,'.\App\Modules\Core\Infrastructure\AuditJsonProjection::fields().',to_jsonb(changed_cols)::text AS changed_cols FROM core.audit_log WHERE '.$where.' ORDER BY occurred_at DESC,id DESC LIMIT ? OFFSET ?',[$id,$id,$id,$id,$id,$size,($page-1)*$size]);
        foreach ($rows as &$row) { foreach (['old_data','new_data','changed_cols'] as $field) { if ($row[$field]!==null) { $row[$field]=json_decode($row[$field],true,512,JSON_THROW_ON_ERROR); } } }
        return [$rows,$total];
    }
}
