<?php
namespace App\Modules\Accounting\ApiResource;
final class AccountInput
{
    public ?string $code=null;
    public ?string $name=null;
    public ?bool $active=null;
    public ?bool $postable=null;
    public ?string $parentId=null;
}