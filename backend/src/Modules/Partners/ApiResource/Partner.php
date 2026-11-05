<?php

namespace App\Modules\Partners\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Modules\Partners\State\PartnerProvider;
use App\Modules\Partners\State\PartnerProcessor;

#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/v1/accounting-units/{unit}/partners', name: 'partners_list', provider: PartnerProvider::class,
            uriVariables: ['unit'=>new Link(fromClass:Partner::class,identifiers:['accountingUnitId'])],
            parameters: ['page' => new QueryParameter(schema: ['type'=>'integer','minimum'=>1]), 'itemsPerPage' => new QueryParameter(schema: ['type'=>'integer','minimum'=>1,'maximum'=>100]), 'search'=>new QueryParameter(schema:['type'=>'string','maxLength'=>100]), 'active'=>new QueryParameter(schema:['type'=>'string','enum'=>['true','false']])]),
        new Post(uriTemplate: '/v1/accounting-units/{unit}/partners', name: 'partners_create', input: PartnerInput::class, read: false, validate: false, processor: PartnerProcessor::class,
            uriVariables: ['unit'=>new Link(fromClass:Partner::class,identifiers:['accountingUnitId'])],
            openapi: new Operation(parameters: [new Parameter(name:'Idempotency-Key',in:'header',required:true,schema:['type'=>'string','maxLength'=>128])])),
        new Get(uriTemplate: '/v1/accounting-units/{unit}/partners/{id}', name: 'partners_get', provider: PartnerProvider::class),
        new Patch(uriTemplate: '/v1/accounting-units/{unit}/partners/{id}', name: 'partners_update', input: PartnerInput::class, read: false, validate: false, processor: PartnerProcessor::class,
            openapi: new Operation(parameters: [new Parameter(name:'If-Match',in:'header',required:true,schema:['type'=>'string'])])),
        new Get(uriTemplate: '/v1/accounting-units/{unit}/partners/{id}/history', name: 'partners_history', output: PartnerHistory::class, provider: PartnerProvider::class),
    ],
    uriVariables: ['unit'=>new Link(fromClass: Partner::class,identifiers:['accountingUnitId']), 'id'=>new Link(fromClass: Partner::class,identifiers:['id'])],
    requirements: ['unit'=>'[0-9a-fA-F-]{36}','id'=>'[0-9a-fA-F-]{36}'],
    normalizationContext: ['skip_null_values'=>false],
    denormalizationContext: ['allow_extra_attributes'=>false],
    paginationClientItemsPerPage: true,
    paginationItemsPerPage: 20,
    paginationMaximumItemsPerPage: 100,
)]
final class Partner
{
    public function __construct(
        #[ApiProperty(identifier:true)] public string $id,
        #[ApiProperty(identifier:false)] public string $accountingUnitId,
        public string $name,
        public ?string $registrationNumber,
        public ?string $vatNumber,
        public string $countryCode,
        public ?string $street,
        public ?string $city,
        public ?string $postalCode,
        public ?string $email,
        public ?string $phone,
        public bool $active,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
    ) { }

    public static function fromRow(array $row): self
    {
        return new self($row['id'],$row['accounting_unit_id'],$row['name'],$row['registration_number'],$row['vat_number'],$row['country_code'],
            $row['street'],$row['city'],$row['postal_code'],$row['email'],$row['phone'],(bool)$row['active'],(int)$row['version'],$row['created_at'],$row['updated_at']);
    }

    public function etag(): string { return '"'.$this->id.':'.$this->version.'"'; }
}
