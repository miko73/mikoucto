<?php
namespace App\Modules\Accounting\ApiResource;
final class DocumentRelations
{
    public function __construct(public string $documentId,public ?DocumentRelation $reversal=null,public ?DocumentRelation $reverses=null,
        public ?DocumentRelation $correction=null,public ?DocumentRelation $corrects=null) { }
}
