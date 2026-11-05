<?php

namespace App\Modules\Partners\ApiResource;

final readonly class PartnerHistory
{
    public function __construct(public array $items, public int $total, public int $page, public int $itemsPerPage) { }
}
