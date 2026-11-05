<?php
namespace App\Modules\Accounting\Infrastructure;

use App\Modules\Core\Application\InputValidationException;

final class ReportExportLimit
{
    public const MAX_ROWS=50000;
    public static function check(int $count,int $limit=self::MAX_ROWS): void
    {
        if ($limit<1||$limit>self::MAX_ROWS) { throw new \InvalidArgumentException('Invalid CSV limit.'); }
        if ($count>$limit) {
            throw new InputValidationException([['propertyPath'=>'export','message'=>"Výběr překračuje limit $limit řádků. Zúžte rozsah dat."]],'Export je příliš velký');
        }
    }
}
