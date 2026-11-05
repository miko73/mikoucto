<?php
namespace App\Modules\Accounting\State;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use App\Modules\Accounting\ApiResource\JournalEntry;
final class JournalProvider implements ProviderInterface
{
    public function __construct(private DocumentService $service) { }
    public function provide(Operation $operation,array $uriVariables=[],array $context=[]): object|array|null
    {
        [$user,$unit,,$request]=$this->service->access->context($uriVariables,'core.ucto.documents.read');
        $query=DocumentService::pagination($request,journal:true);
        return $this->service->session->run($user,$unit,function() use($unit,$query,$request) {
            [$rows,$total]=$this->service->repository->journal($unit,$query);
            $request->attributes->set('_accounting_total',$total);
            $items=array_map(static fn($row)=>new JournalEntry($row['id'],$row['accounting_unit_id'],$row['document_id'],$row['period_id'],
                $row['number'],$row['posting_date'],(int)$row['line_no'],$row['account_id'],$row['account_code'],$row['account_name'],
                $row['document_text'],$row['text'],$row['md'],$row['dal']),$rows);
            return new TraversablePaginator(new \ArrayIterator($items),$query[0],$query[1],$total);
        },$request->attributes->get('_request_id'));
    }
}
