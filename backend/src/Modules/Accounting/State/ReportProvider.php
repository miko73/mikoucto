<?php

namespace App\Modules\Accounting\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Accounting\ApiResource\AccountingReport;
use App\Modules\Accounting\Application\ReportQuery;
use App\Modules\Accounting\Infrastructure\ReportRepository;
use App\Modules\Core\Application\Identity\IdentitySession;

final class ReportProvider implements ProviderInterface
{
    public function __construct(private ReportAccess $access,private IdentitySession $session,private ReportRepository $repository) { }

    public function provide(Operation $operation,array $uriVariables=[],array $context=[]): AccountingReport
    {
        [$user,$unit,,$request]=$this->access->context($uriVariables);
        $ledger=$operation->getName()==='general_ledger';
        $query=ReportQuery::parse($request->query->all(),$ledger);
        $result=$this->session->run($user,$unit,fn()=> $this->repository->report($unit,$query,$ledger),$request->attributes->get('_request_id'));
        $request->attributes->set('_accounting_report',true);
        return new AccountingReport($unit,$result['filters'],$result['generatedAt'],'CZK','posted_movements_within_period_no_carry_forward',
            $result['totals'],$result['items'],$result['total'],$query['page'],$query['itemsPerPage']);
    }
}
