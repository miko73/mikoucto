<?php
namespace App\Modules\Accounting\State;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use App\Modules\Accounting\ApiResource\AccountingHistory;
use App\Modules\Accounting\ApiResource\DocumentSummary;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final class DocumentProvider implements ProviderInterface
{
    public function __construct(private DocumentService $service) { }
    public function provide(Operation $operation,array $uriVariables=[],array $context=[]): object|array|null
    {
        $history=$operation->getName()==='documents_history';
        $relations=$operation->getName()==='documents_relations';
        [$user,$unit,$id,$request]=$this->service->access->context($uriVariables,'core.ucto.documents.'.($history?'history':'read'));
        return $this->service->session->run($user,$unit,function() use($history,$relations,$operation,$unit,$id,$request) {
            if ($operation instanceof GetCollection) {
                $query=DocumentService::pagination($request);
                [$rows,$total]=$this->service->repository->listing($unit,$query);
                $request->attributes->set('_accounting_total',$total);
                return new TraversablePaginator(new \ArrayIterator(array_map(DocumentSummary::fromRow(...),$rows)),$query[0],$query[1],$total);
            }
            $document=$this->service->repository->find($unit,$id)??throw new NotFoundHttpException();
            if ($relations) {
                if ($request->query->all()!==[]) { throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(); }
                $request->attributes->set('_accounting_no_store',true);
                return $this->service->repository->relations($unit,$id);
            }
            if ($history) {
                [$page,$size]=DocumentService::pagination($request,true);
                [$items,$total]=$this->service->repository->history($id,$page,$size);
                return new AccountingHistory($items,$total,$page,$size);
            }
            $request->attributes->set('_accounting_etag',$document->etag());
            return $document;
        },$request->attributes->get('_request_id'));
    }
}
