<?php
namespace App\Modules\Accounting\Application;
use App\Modules\Core\Application\InputValidationException;
use App\Modules\Core\Infrastructure\Identity\EntraSettings;
final class CorrectionInput
{
    public static function normalize(array $payload): array
    {
        $errors=[];
        if (array_diff(array_keys($payload),['periodId','postingDate'])!==[]) { $errors['input']='Unknown or server-managed field.'; }
        if (!EntraSettings::isGuid($payload['periodId']??null)) { $errors['periodId']='A period UUID is required.'; }
        if (!is_string($payload['postingDate']??null) || !AccountingInput::date($payload['postingDate'])) { $errors['postingDate']='A valid date YYYY-MM-DD is required.'; }
        if ($errors!==[]) { throw new InputValidationException($errors); }
        return ['periodId'=>strtolower($payload['periodId']),'postingDate'=>$payload['postingDate']];
    }
}
