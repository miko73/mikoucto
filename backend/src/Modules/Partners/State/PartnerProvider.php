<?php

namespace App\Modules\Partners\State;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Core\Application\Identity\IdentitySession;
use App\Modules\Partners\ApiResource\Partner;
use App\Modules\Partners\ApiResource\PartnerHistory;
use App\Modules\Partners\Infrastructure\PartnerRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PartnerProvider implements ProviderInterface
{
    public function __construct(private PartnerAccess $access,private IdentitySession $session,private PartnerRepository $repository) { }

    public function provide(Operation $operation,array $uriVariables=[],array $context=[]): object|array|null
    {
        $history=$operation->getName()==='partners_history';
        [$user,$unit,$id,$request]=$this->access->context($uriVariables,$history?'core.partners.history':'core.partners.read');
        return $this->session->run($user,$unit,function () use ($operation,$history,$unit,$id,$request): object {
            if ($operation instanceof GetCollection) {
                [$page,$size,$search,$active]=$this->access->pagination($request,true);
                [$rows,$total]=$this->repository->list($unit,$page,$size,$search,$active);
                $request->attributes->set('_partner_total',$total);
                return new TraversablePaginator(new \ArrayIterator(array_map(Partner::fromRow(...),$rows)),$page,$size,$total);
            }
            $row=$this->repository->find($unit,$id);
            if ($row===false) { throw new NotFoundHttpException(); }
            if ($history) {
                [$page,$size]=$this->access->pagination($request,false);
                [$rows,$total]=$this->repository->history($id,$page,$size);
                return new PartnerHistory($rows,$total,$page,$size);
            }
            $partner=Partner::fromRow($row);
            $request->attributes->set('_partner_etag',$partner->etag());
            return $partner;
        },$request->attributes->get('_request_id'));
    }
}
