<?php
namespace App\Modules\Accounting\ApiResource;
final class AccountingHistory
{
    public function __construct(public array $items,public int $total,public int $page,public int $itemsPerPage) { }
}
