<?php

namespace App\Modules\Accounting\Infrastructure;

use Doctrine\DBAL\Connection;
use App\Modules\Accounting\Application\ReportQuery;
use App\Modules\Accounting\Application\ReportBalanceControl;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A single statement supplies metadata, count, totals and page from one snapshot. */
final class ReportRepository
{
    public function __construct(private Connection $connection,private int $exportBatchSize=1000)
    {
        if ($exportBatchSize<1||$exportBatchSize>1000) { throw new \InvalidArgumentException('Invalid export batch size.'); }
    }

    private function parts(string $unit,array $query,bool $ledger): array
    {
        $common=<<<'SQL'
WITH input AS (
 SELECT ?::uuid AS unit,?::uuid AS period_id,?::uuid AS account_id,
        ?::date AS date_from,?::date AS date_to,?::boolean AS include_zero
), scope AS (
 SELECT i.*,p.name AS period_name,p.starts_on,p.ends_on,
        coalesce(i.date_from,p.starts_on) AS date_start,coalesce(i.date_to,p.ends_on) AS date_end
 FROM input i JOIN ucto.periods p ON p.accounting_unit_id=i.unit AND p.id=i.period_id
), movements AS MATERIALIZED (
 SELECT l.id,l.account_id,l.md,l.dal,l.line_no,l.text,d.id AS document_id,d.number,d.text AS document_text,d.posting_date
 FROM scope s JOIN ucto.documents d ON d.accounting_unit_id=s.unit AND d.period_id=s.period_id AND d.status='posted'
 JOIN ucto.document_lines l ON l.accounting_unit_id=d.accounting_unit_id AND l.document_id=d.id
 WHERE d.posting_date<=s.date_end AND (s.account_id IS NULL OR l.account_id=s.account_id)
), accounts AS (
 SELECT a.id,a.code,a.name FROM input i JOIN ucto.accounts a ON a.accounting_unit_id=i.unit
 WHERE i.account_id IS NULL OR a.id=i.account_id
), account_sums AS (
 SELECT a.id,a.code,a.name,
   coalesce(sum(m.md-m.dal) FILTER (WHERE m.posting_date<s.date_start),0) AS opening,
   coalesce(sum(m.md) FILTER (WHERE m.posting_date>=s.date_start),0) AS md,
   coalesce(sum(m.dal) FILTER (WHERE m.posting_date>=s.date_start),0) AS dal,
   count(m.id) FILTER (WHERE m.posting_date>=s.date_start) AS movement_count
 FROM scope s CROSS JOIN accounts a LEFT JOIN movements m ON m.account_id=a.id
 GROUP BY a.id,a.code,a.name
), balances AS (
 SELECT *,opening+md-dal AS closing FROM account_sums
)
SQL;
        $body=$ledger?<<<'SQL'
, selected AS (SELECT b.* FROM balances b JOIN input i ON b.id=i.account_id),
 ordered AS (
 SELECT m.*,a.code,a.name,
        a.opening+sum(m.md-m.dal) OVER (ORDER BY m.posting_date,m.document_id,m.line_no ROWS UNBOUNDED PRECEDING) AS running
 FROM movements m JOIN selected a ON a.id=m.account_id CROSS JOIN scope s WHERE m.posting_date>=s.date_start
 )
SQL:<<<'SQL'
, selected AS (SELECT b.* FROM balances b CROSS JOIN input i WHERE i.include_zero OR b.opening<>0 OR b.movement_count>0),
 sums AS (
 SELECT coalesce(sum(greatest(opening,0)),0) AS opening_md,coalesce(sum(greatest(-opening,0)),0) AS opening_dal,
        coalesce(sum(md),0) AS md,coalesce(sum(dal),0) AS dal,
        coalesce(sum(greatest(closing,0)),0) AS closing_md,coalesce(sum(greatest(-closing,0)),0) AS closing_dal FROM selected
 )
SQL;
        $source=$ledger?'ordered':'selected';
        $order=$ledger?'posting_date,document_id,line_no':'code COLLATE "C",id';
        $totals=$ledger?<<<'SQL'
(SELECT jsonb_build_object('openingBalance',round(opening,2)::text,'turnoverMd',round(md,2)::text,
 'turnoverDal',round(dal,2)::text,'closingBalance',round(closing,2)::text) FROM selected)
SQL:<<<'SQL'
(SELECT jsonb_build_object('openingMd',round(opening_md,2)::text,'openingDal',round(opening_dal,2)::text,
 'turnoverMd',round(md,2)::text,'turnoverDal',round(dal,2)::text,'closingMd',round(closing_md,2)::text,'closingDal',round(closing_dal,2)::text) FROM sums)
SQL;
        $row=$ledger?<<<'SQL'
jsonb_build_object('id',id,'accountId',account_id,'documentId',document_id,'number',number,'postingDate',posting_date,
 'lineNo',line_no,'documentText',document_text,'text',text,'md',round(md,2)::text,'dal',round(dal,2)::text,'runningBalance',round(running,2)::text)
SQL:<<<'SQL'
jsonb_build_object('accountId',id,'accountCode',code,'accountName',name,'openingMd',round(greatest(opening,0),2)::text,
 'openingDal',round(greatest(-opening,0),2)::text,'turnoverMd',round(md,2)::text,'turnoverDal',round(dal,2)::text,
 'closingMd',round(greatest(closing,0),2)::text,'closingDal',round(greatest(-closing,0),2)::text)
SQL;
        $end=<<<'SQL'
 'generatedAt',to_char(statement_timestamp() AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
 'filters',(SELECT jsonb_build_object('periodId',period_id,'periodName',period_name,'periodStartsOn',starts_on,'periodEndsOn',ends_on,
     'from',date_start,'to',date_end,'includeZero',include_zero,'accountId',account_id,
     'accountCode',(SELECT code FROM accounts WHERE id=account_id),'accountName',(SELECT name FROM accounts WHERE id=account_id)) FROM scope),
 'validRange',(SELECT date_start>=starts_on AND date_end<=ends_on AND date_start<=date_end FROM scope),
 'accountExists',EXISTS(SELECT 1 FROM accounts a CROSS JOIN input i WHERE a.id=i.account_id)
 )
SQL;
        $metadata="jsonb_build_object('totals',$totals,'total',(SELECT count(*) FROM $source),".$end;
        return [$common.$body,$metadata,$row,$source,$order,[$unit,$query['periodId'],$ledger?$query['accountId']:null,
            $query['from']??null,$query['to']??null,$query['includeZero']?'true':'false']];
    }

    public function report(string $unit,array $query,bool $ledger): array
    {
        [$cte,$metadata,$row,$source,$order,$params]=$this->parts($unit,$query,$ledger);
        $sql=$cte.", page_rows AS (SELECT * FROM $source ORDER BY $order LIMIT ? OFFSET ?) SELECT ($metadata || jsonb_build_object('items',coalesce((SELECT jsonb_agg($row ORDER BY $order) FROM page_rows),'[]'::jsonb)))::text";
        $raw=$this->connection->fetchOne($sql,[...$params,$query['itemsPerPage'],($query['page']-1)*$query['itemsPerPage']]);
        $result=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if ($result['filters']===null||($ledger&&!$result['accountExists'])) { throw new NotFoundHttpException('Report period or account not found.'); }
        if (!$result['validRange']) {
            $filters=$result['filters'];
            $fromOutside=$filters['from']<$filters['periodStartsOn']||$filters['from']>$filters['periodEndsOn'];
            $toOutside=$filters['to']<$filters['periodStartsOn']||$filters['to']>$filters['periodEndsOn'];
            $field=$fromOutside?'from':($toOutside?'to':'from');
            ReportQuery::invalid($field,'Dates must be ordered and within the selected period.');
        }
        if (!$ledger) { $result['totals']=ReportBalanceControl::apply($result['totals']); }
        return $result;
    }

    /** Caller owns a read-only REPEATABLE READ identity transaction. */
    public function export(string $unit,array $query,bool $ledger,callable $begin,callable $write,int $limit=ReportExportLimit::MAX_ROWS): array
    {
        if (!$this->connection->isTransactionActive()) { throw new \LogicException('Export requires a snapshot transaction.'); }
        $settings=$this->connection->fetchAssociative("SELECT current_setting('transaction_isolation') AS isolation,current_setting('transaction_read_only') AS read_only");
        if ($settings['isolation']!=='repeatable read'||$settings['read_only']!=='on') { throw new \LogicException('Export requires a read-only repeatable snapshot.'); }
        $metadata=$this->report($unit,array_replace($query,['page'=>1,'itemsPerPage'=>0]),$ledger);
        ReportExportLimit::check($metadata['total'],$limit);
        $begin($metadata);
        [$cte,,$row,$source,$order,$params]=$this->parts($unit,$query,$ledger);
        $cursor='report_'.bin2hex(random_bytes(8));
        $this->connection->executeStatement("DECLARE $cursor NO SCROLL CURSOR WITHOUT HOLD FOR ".$cte." SELECT ($row)::text AS payload FROM $source ORDER BY $order",$params);
        $written=0;
        $failure=null;
        try {
            do {
                $batch=$this->connection->fetchFirstColumn("FETCH FORWARD $this->exportBatchSize FROM $cursor");
                foreach ($batch as $payload) { $write(json_decode($payload,true,512,JSON_THROW_ON_ERROR)); ++$written; }
            } while (count($batch)===$this->exportBatchSize);
        } catch (\Throwable $error) { $failure=$error; throw $error; }
        finally {
            try { $this->connection->executeStatement("CLOSE $cursor"); }
            catch (\Throwable $closeError) { if ($failure===null) { throw $closeError; } }
        }
        if ($written!==$metadata['total']) { throw new \LogicException('Export row count differs from snapshot totals.'); }
        return $metadata;
    }
}
