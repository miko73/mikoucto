<?php
namespace App\Modules\Accounting\Application;
use App\Modules\Core\Application\InputValidationException;
use App\Modules\Core\Infrastructure\Identity\EntraSettings;
final class ReversalInput
{
    public static function normalize(array $payload): array
    {
        $errors=[];
        if (array_diff(array_keys($payload),['periodId','postingDate','reason'])!==[]) { $errors['input']='Unknown or server-managed field.'; }
        if (!EntraSettings::isGuid($payload['periodId']??null)) { $errors['periodId']='A period UUID is required.'; }
        if (!is_string($payload['postingDate']??null) || !AccountingInput::date($payload['postingDate'])) { $errors['postingDate']='A valid date YYYY-MM-DD is required.'; }
        $reason=is_string($payload['reason']??null)?trim($payload['reason']):'';
        if (mb_strlen($reason)<1 || mb_strlen($reason)>1000 || str_contains($reason,"\0")) { $errors['reason']='Use 1 to 1000 characters without NUL.'; }
        if ($errors!==[]) { throw new InputValidationException($errors); }
        return ['periodId'=>strtolower($payload['periodId']),'postingDate'=>$payload['postingDate'],'reason'=>$reason];
    }
}
