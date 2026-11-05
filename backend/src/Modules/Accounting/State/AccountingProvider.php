<?php
namespace App\Modules\Accounting\State;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use App\Modules\Accounting\ApiResource\AccountingHistory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final class AccountingProvider implements ProviderInterface
{
    public function __construct(private AccountingService $service) { }
    public function provide(Operation $operation,array $uriVariables=[],array $context=[]): object|array|null
    {
        $kind=AccountingService::kind($operation); $history=str_ends_with($operation->getName(),'_history');
        [$user,$unit,$id,$request]=$this->service->access->context($uriVariables,'core.ucto.'.$kind.($history?'.history':'.read'));
        return $this->service->session->run($user,$unit,function() use($kind,$history,$operation,$unit,$id,$request) {
            if ($operation instanceof GetCollection) {
                [$page,$size,$search,$active,$on]=AccountingService::pagination($request,$kind);
                [$rows,$total]=$this->service->repository->listing($kind,$unit,$page,$size,$search,$active,$on);
                $request->attributes->set('_accounting_total',$total);
                return new TraversablePaginator(new \ArrayIterator(array_map(static fn($row)=>AccountingService::resource($kind,$row),$rows)),$page,$size,$total);
            }
            $row=$this->service->repository->find($kind,$unit,$id);
            if ($row===false) { throw new NotFoundHttpException(); }
            if ($history) {
                [$page,$size]=AccountingService::pagination($request,$kind,true);
                [$rows,$total]=$this->service->repository->history($kind,$id,$page,$size);
                return new AccountingHistory($rows,$total,$page,$size);
            }
            $resource=AccountingService::resource($kind,$row);
            $request->attributes->set('_accounting_etag',$resource->etag());
            return $resource;
        },$request->attributes->get('_request_id'));
    }
}
