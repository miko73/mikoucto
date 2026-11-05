<?php
namespace App\Modules\Accounting\ApiResource;
final class DocumentLine
{
    public function __construct(public string $id,public int $lineNo,public string $accountId,public string $text,public string $md,public string $dal) { }
}
