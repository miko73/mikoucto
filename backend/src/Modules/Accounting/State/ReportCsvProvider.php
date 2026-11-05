<?php
namespace App\Modules\Accounting\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Accounting\Application\ReportQuery;
use App\Modules\Accounting\Infrastructure\ReportCsvWriter;
use App\Modules\Accounting\Infrastructure\ReportExportLimit;
use App\Modules\Accounting\Infrastructure\ReportRepository;
use App\Modules\Core\Application\Identity\IdentitySession;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportCsvProvider implements ProviderInterface
{
    public function __construct(private ReportAccess $access,private IdentitySession $session,private ReportRepository $repository,
        private ReportCsvWriter $writer,private int $maxRows=ReportExportLimit::MAX_ROWS) { }

    public function provide(Operation $operation,array $uriVariables=[],array $context=[]): Response
    {
        [$user,$unit,,$request]=$this->access->context($uriVariables);
        $ledger=$operation->getName()==='general_ledger_csv';
        $query=ReportQuery::parse($request->query->all(),$ledger,export:true);
        $stream=fopen('php://temp/maxmemory:2097152','w+b');
        if ($stream===false) { throw new \RuntimeException('Cannot prepare CSV.'); }
        try {
            $metadata=$this->session->run($user,$unit,function(Connection $connection) use($stream,$unit,$query,$ledger): array {
                $unitRow=$connection->fetchAssociative('SELECT id,code,name FROM core.accounting_units WHERE id=?',[$unit]);
                if ($unitRow===false) { throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException(); }
                $metadata=[];
                $result=$this->repository->export($unit,$query,$ledger,
                    function(array $data) use($stream,$unitRow,$ledger,&$metadata): void { $metadata=$data; $this->writer->begin($stream,$data,$unitRow,$ledger); },
                    function(array $item) use($stream,$ledger,&$metadata): void { $this->writer->row($stream,$item,$metadata,$ledger); },$this->maxRows);
                $this->writer->finish($stream,$result,$ledger);
                return $result;
            },$request->attributes->get('_request_id'),readOnlySnapshot:true);
            $length=ftell($stream);
            if ($length===false||!rewind($stream)) { throw new \RuntimeException('Cannot rewind CSV.'); }
            $filename=($ledger?'general-ledger':'trial-balance').'_'.$unit.'_'.$metadata['filters']['from'].'_'.$metadata['filters']['to'].'.csv';
            $headers=['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>HeaderUtils::makeDisposition('attachment',$filename),
                'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','Content-Length'=>(string)$length];
            $request->attributes->set('_accounting_report',true);
            if ($request->isMethod('HEAD')) { fclose($stream); return new Response('',200,$headers); }
            return new StreamedResponse(static function() use($stream): void {
                try { if (fpassthru($stream)===false) { throw new \RuntimeException('Cannot send CSV.'); } }
                finally { fclose($stream); }
            },200,$headers);
        } catch (\Throwable $error) { if (is_resource($stream)) { fclose($stream); } throw $error; }
    }
}
