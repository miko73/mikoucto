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
        new GetCollection(uriTemplate:'/v1/accounting-units/{unit}/periods',name:'periods_list',provider:AccountingProvider::class,uriVariables:['unit'=>new Link(fromClass:Period::class,identifiers:['accountingUnitId'])]),
        new Post(uriTemplate:'/v1/accounting-units/{unit}/periods',name:'periods_create',input:PeriodInput::class,read:false,deserialize:false,validate:false,processor:AccountingProcessor::class,uriVariables:['unit'=>new Link(fromClass:Period::class,identifiers:['accountingUnitId'])],openapi:new Operation(parameters:[new Parameter(name:'Idempotency-Key',in:'header',required:true,schema:['type'=>'string','maxLength'=>128])])),
        new Get(uriTemplate:'/v1/accounting-units/{unit}/periods/{id}',name:'periods_get',provider:AccountingProvider::class),
        new Post(uriTemplate:'/v1/accounting-units/{unit}/periods/{id}/close',name:'periods_close',input:CommandInput::class,read:false,deserialize:false,validate:false,status:200,processor:\App\Modules\Accounting\State\PostingProcessor::class,
            openapi:new Operation(parameters:[new Parameter(name:'If-Match',in:'header',required:true,schema:['type'=>'string']),new Parameter(name:'Idempotency-Key',in:'header',required:true,schema:['type'=>'string','maxLength'=>128])])),
        new Patch(uriTemplate:'/v1/accounting-units/{unit}/periods/{id}',name:'periods_update',input:PeriodInput::class,read:false,deserialize:false,validate:false,processor:AccountingProcessor::class,openapi:new Operation(parameters:[new Parameter(name:'If-Match',in:'header',required:true,schema:['type'=>'string'])])),
        new Get(uriTemplate:'/v1/accounting-units/{unit}/periods/{id}/history',name:'periods_history',output:AccountingHistory::class,provider:AccountingProvider::class),
    ],
    uriVariables:['unit'=>new Link(fromClass:Period::class,identifiers:['accountingUnitId']),'id'=>new Link(fromClass:Period::class,identifiers:['id'])],
    requirements:['unit'=>'[0-9a-fA-F-]{36}','id'=>'[0-9a-fA-F-]{36}'],
    normalizationContext:['skip_null_values'=>false],
    paginationClientItemsPerPage:true,paginationItemsPerPage:20,paginationMaximumItemsPerPage:100
)]
final class Period
{
    public function __construct(
        #[ApiProperty(identifier:true)] public string $id,
        #[ApiProperty(identifier:false)] public string $accountingUnitId,
        public string $name,public string $startsOn,public string $endsOn,public string $status,
        public int $version,public string $createdAt,public string $updatedAt,
        public ?string $closedAt=null,public ?string $closedBy=null
    ) { }
    public static function fromRow(array $row): self
    {
        return new self($row['id'],$row['accounting_unit_id'],$row['name'],$row['starts_on'],$row['ends_on'],$row['status'],(int)$row['version'],$row['created_at'],$row['updated_at'],$row['closed_at']??null,$row['closed_by']??null);
    }
    public function etag(): string { return '"'.$this->id.':'.$this->version.'"'; }
}
