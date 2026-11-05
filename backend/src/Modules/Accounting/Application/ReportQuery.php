<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Core\Infrastructure\Identity\EntraSettings;
use App\Modules\Core\Application\InputValidationException;

/** Validated filters; dates are completed against the period in the repository. */
final class ReportQuery
{
    public static function invalid(string $field,string $message): never
    {
        throw new InputValidationException([['propertyPath'=>$field,'message'=>$message]],'Invalid report filters',400);
    }
    public static function parse(array $input, bool $ledger, bool $export = false): array
    {
        $allowed=['periodId','from','to','page','itemsPerPage',...($ledger?['accountId']:['includeZero'])];
        foreach ($input as $key=>$value) {
            if ($export&&in_array($key,['page','itemsPerPage'],true)) { self::invalid($key,'CSV exports the entire selection; pagination is not supported.'); }
            if (!in_array($key,$allowed,true)||!is_string($value)) { self::invalid((string)$key,'Unknown parameter or a single string value is required.'); }
        }
        foreach ($ledger?['periodId','accountId']:['periodId'] as $key) {
            if (!EntraSettings::isGuid($input[$key]??null)) { self::invalid($key,'A valid UUID is required.'); }
            $input[$key]=strtolower($input[$key]);
        }
        foreach (['from','to'] as $key) {
            if (isset($input[$key])&&!AccountingInput::date($input[$key])) { self::invalid($key,'A valid date YYYY-MM-DD is required.'); }
        }
        if (isset($input['from'],$input['to'])&&$input['from']>$input['to']) { self::invalid('from','Must not be after to.'); }
        foreach (['page'=>100000,'itemsPerPage'=>100] as $key=>$max) {
            $value=$input[$key]??($key==='page'?'1':'20');
            if (!ctype_digit($value)||strlen($value)>6||(int)$value<1||(int)$value>$max) { self::invalid($key,'An integer between 1 and '.$max.' is required.'); }
            $input[$key]=(int)$value;
        }
        if (isset($input['includeZero'])&&!in_array($input['includeZero'],['true','false'],true)) { self::invalid('includeZero','Use true or false.'); }
        $input['includeZero']=($input['includeZero']??'false')==='true';
        return $input;
    }
}
