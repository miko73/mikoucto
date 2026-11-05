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
use App\Modules\Accounting\State\DocumentProvider;
use App\Modules\Accounting\State\DocumentProcessor;
#[ApiResource(
    operations: [
        new GetCollection(output:DocumentSummary::class,uriTemplate:'/v1/accounting-units/{unit}/documents',name:'documents_list',provider:DocumentProvider::class,uriVariables:['unit'=>new Link(fromClass:Document::class,identifiers:['accountingUnitId'])]),
        new Post(uriTemplate:'/v1/accounting-units/{unit}/documents',name:'documents_create',input:DocumentInput::class,read:false,deserialize:false,validate:false,processor:DocumentProcessor::class,uriVariables:['unit'=>new Link(fromClass:Document::class,identifiers:['accountingUnitId'])],openapi:new Operation(parameters:[new Parameter(name:'Idempotency-Key',in:'header',required:true,schema:['type'=>'string','maxLength'=>128])])),
        new Get(uriTemplate:'/v1/accounting-units/{unit}/documents/{id}',name:'documents_get',provider:DocumentProvider::class),
        new Get(uriTemplate:'/v1/accounting-units/{unit}/documents/{id}/relations',name:'documents_relations',output:DocumentRelations::class,provider:DocumentProvider::class),
        new Post(uriTemplate:'/v1/accounting-units/{unit}/documents/{id}/correction-draft',name:'documents_correction_draft',input:CorrectionInput::class,read:false,deserialize:false,validate:false,status:201,processor:\App\Modules\Accounting\State\CorrectionProcessor::class,
            openapi:new Operation(parameters:[new Parameter(name:'If-Match',in:'header',required:true,schema:['type'=>'string']),new Parameter(name:'Idempotency-Key',in:'header',required:true,schema:['type'=>'string','maxLength'=>128])])),
        new Post(uriTemplate:'/v1/accounting-units/{unit}/documents/{id}/reverse',name:'documents_reverse',input:ReversalInput::class,read:false,deserialize:false,validate:false,status:201,processor:\App\Modules\Accounting\State\ReversalProcessor::class,
            openapi:new Operation(parameters:[new Parameter(name:'If-Match',in:'header',required:true,schema:['type'=>'string']),new Parameter(name:'Idempotency-Key',in:'header',required:true,schema:['type'=>'string','maxLength'=>128])])),
        new Post(uriTemplate:'/v1/accounting-units/{unit}/documents/{id}/post',name:'documents_post',input:CommandInput::class,read:false,deserialize:false,validate:false,status:200,processor:\App\Modules\Accounting\State\PostingProcessor::class,
            openapi:new Operation(parameters:[new Parameter(name:'If-Match',in:'header',required:true,schema:['type'=>'string']),new Parameter(name:'Idempotency-Key',in:'header',required:true,schema:['type'=>'string','maxLength'=>128])])),
        new Patch(uriTemplate:'/v1/accounting-units/{unit}/documents/{id}',name:'documents_update',input:DocumentInput::class,read:false,deserialize:false,validate:false,processor:DocumentProcessor::class,openapi:new Operation(parameters:[new Parameter(name:'If-Match',in:'header',required:true,schema:['type'=>'string'])])),
        new Get(uriTemplate:'/v1/accounting-units/{unit}/documents/{id}/history',name:'documents_history',output:AccountingHistory::class,provider:DocumentProvider::class),
    ],
    uriVariables:['unit'=>new Link(fromClass:Document::class,identifiers:['accountingUnitId']),'id'=>new Link(fromClass:Document::class,identifiers:['id'])],
    requirements:['unit'=>'[0-9a-fA-F-]{36}','id'=>'[0-9a-fA-F-]{36}'],
    normalizationContext:['skip_null_values'=>false],
    paginationClientItemsPerPage:true,paginationItemsPerPage:20,paginationMaximumItemsPerPage:100
)]
final class Document extends DocumentSummary
{
    /** @var list<DocumentLine> */
    public array $lines=[];
    public function input(): array
    {
        return ['periodId'=>$this->periodId,'postingDate'=>$this->postingDate,'text'=>$this->text,'partnerId'=>$this->partnerId,
            'lines'=>array_map(static fn(DocumentLine $line)=>['accountId'=>$line->accountId,'text'=>$line->text,'md'=>$line->md,'dal'=>$line->dal],$this->lines)];
    }
    public static function snapshot(array $data): self
    {
        $lines=$data['lines']; unset($data['lines']);
        $document=new self(...$data);
        $document->lines=array_map(static fn($line)=>new DocumentLine(...$line),$lines);
        return $document;
    }
}
