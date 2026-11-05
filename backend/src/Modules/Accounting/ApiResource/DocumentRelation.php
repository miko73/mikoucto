<?php
namespace App\Modules\Accounting\ApiResource;
final class DocumentRelation
{
    public function __construct(public string $id,public ?string $number,public string $periodId,public string $postingDate,
        public string $reason,public string $createdBy,public string $createdAt,public string $status='posted') { }
}
