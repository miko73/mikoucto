<?php
namespace App\Modules\Accounting\ApiResource;
final class DocumentInput
{
    public ?string $periodId=null;
    public ?string $postingDate=null;
    public ?string $text=null;
    public ?string $partnerId=null;
    /** @var list<DocumentLineInput>|null */
    public ?array $lines=null;
}