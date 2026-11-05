<?php
namespace App\Modules\Accounting\ApiResource;
final class ReversalInput
{
    public string $periodId;
    public string $postingDate;
    public string $reason;
}
