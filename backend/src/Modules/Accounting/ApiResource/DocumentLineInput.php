<?php
namespace App\Modules\Accounting\ApiResource;
final class DocumentLineInput
{
    public ?string $accountId=null;
    public ?string $text=null;
    public ?string $md=null;
    public ?string $dal=null;
}