<?php

namespace App\Modules\Accounting\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Modules\Accounting\State\ReportProvider;
use App\Modules\Accounting\State\ReportCsvProvider;

#[ApiResource(operations:[
    new Get(uriTemplate:'/v1/accounting-units/{unit}/reports/general-ledger',name:'general_ledger',provider:ReportProvider::class),
    new Get(uriTemplate:'/v1/accounting-units/{unit}/reports/trial-balance',name:'trial_balance',provider:ReportProvider::class),
    new Get(uriTemplate:'/v1/accounting-units/{unit}/reports/general-ledger.csv',name:'general_ledger_csv',provider:ReportCsvProvider::class,output:false,formats:['csv'=>['text/csv']],serialize:false),
    new Get(uriTemplate:'/v1/accounting-units/{unit}/reports/trial-balance.csv',name:'trial_balance_csv',provider:ReportCsvProvider::class,output:false,formats:['csv'=>['text/csv']],serialize:false),
],uriVariables:['unit'=>new Link(fromClass:AccountingReport::class,identifiers:['accountingUnitId'])],
    requirements:['unit'=>'[0-9a-fA-F-]{36}'],formats:['json'=>['application/json']])]
final class AccountingReport
{
    public function __construct(
        public string $accountingUnitId,
        public array $filters,
        public string $generatedAt,
        public string $currency,
        public string $basis,
        public array $totals,
        public array $items,
        public int $total,
        public int $page,
        public int $itemsPerPage,
    ) { }
}
