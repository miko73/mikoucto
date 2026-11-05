<?php
namespace App\Modules\Accounting\ApiResource;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Modules\Accounting\State\JournalProvider;

#[ApiResource(operations:[new GetCollection(uriTemplate:'/v1/accounting-units/{unit}/journal',name:'journal_list',provider:JournalProvider::class)],
    uriVariables:['unit'=>new Link(fromClass:JournalEntry::class,identifiers:['accountingUnitId'])],
    requirements:['unit'=>'[0-9a-fA-F-]{36}'],paginationClientItemsPerPage:true,paginationItemsPerPage:20,paginationMaximumItemsPerPage:100)]
final class JournalEntry
{
    public function __construct(#[ApiProperty(identifier:true)] public string $id,#[ApiProperty(identifier:false)] public string $accountingUnitId,public string $documentId,public string $periodId,
        public string $number,public string $postingDate,public int $lineNo,public string $accountId,public string $accountCode,
        public string $accountName,public string $documentText,public string $text,public string $md,public string $dal) { }
}
