<?php
namespace App\Modules\Accounting\ApiResource;
final class PeriodInput
{
    public ?string $name=null;
    public ?string $startsOn=null;
    public ?string $endsOn=null;
}