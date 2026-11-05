<?php
namespace App\Modules\Accounting\ApiResource;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Modules\Accounting\State\AccountingProvider;
use App\Modules\Accounting\State\AccountingProcessor;
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate:'/v1/accounting-units/{unit}/accounts',name:'accounts_list',provider:AccountingProvider::class,uriVariables:['unit'=>new Link(fromClass:Account::class,identifiers:['accountingUnitId'])]),
        new Post(uriTemplate:'/v1/accounting-units/{unit}/accounts',name:'accounts_create',input:AccountInput::class,read:false,deserialize:false,validate:false,processor:AccountingProcessor::class,uriVariables:['unit'=>new Link(fromClass:Account::class,identifiers:['accountingUnitId'])],openapi:new Operation(parameters:[new Parameter(name:'Idempotency-Key',in:'header',required:true,schema:['type'=>'string','maxLength'=>128])])),
        new Get(uriTemplate:'/v1/accounting-units/{unit}/accounts/{id}',name:'accounts_get',provider:AccountingProvider::class),
        new Patch(uriTemplate:'/v1/accounting-units/{unit}/accounts/{id}',name:'accounts_update',input:AccountInput::class,read:false,deserialize:false,validate:false,processor:AccountingProcessor::class,openapi:new Operation(parameters:[new Parameter(name:'If-Match',in:'header',required:true,schema:['type'=>'string'])])),
        new Get(uriTemplate:'/v1/accounting-units/{unit}/accounts/{id}/history',name:'accounts_history',output:AccountingHistory::class,provider:AccountingProvider::class),
    ],
    uriVariables:['unit'=>new Link(fromClass:Account::class,identifiers:['accountingUnitId']),'id'=>new Link(fromClass:Account::class,identifiers:['id'])],
    requirements:['unit'=>'[0-9a-fA-F-]{36}','id'=>'[0-9a-fA-F-]{36}'],
    normalizationContext:['skip_null_values'=>false],
    paginationClientItemsPerPage:true,paginationItemsPerPage:20,paginationMaximumItemsPerPage:100
)]
final class Account
{
    public function __construct(
        #[ApiProperty(identifier:true)] public string $id,
        #[ApiProperty(identifier:false)] public string $accountingUnitId,
        public string $code,public string $name,public bool $active,public bool $postable,public ?string $parentId,
        public int $version,public string $createdAt,public string $updatedAt
    ) { }
    public static function fromRow(array $row): self
    {
        return new self($row['id'],$row['accounting_unit_id'],$row['code'],$row['name'],(bool)$row['active'],(bool)$row['postable'],$row['parent_id'],(int)$row['version'],$row['created_at'],$row['updated_at']);
    }
    public function etag(): string { return '"'.$this->id.':'.$this->version.'"'; }
}