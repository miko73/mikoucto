<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Core\Application\InputValidationException;
use App\Modules\Core\Infrastructure\Identity\EntraSettings;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class AccountingInput
{
    public const ACCOUNT_FIELDS=['code'=>'code','name'=>'name','active'=>'active','postable'=>'postable','parentId'=>'parent_id'];
    public const PERIOD_FIELDS=['name'=>'name','startsOn'=>'starts_on','endsOn'=>'ends_on'];

    public static function date(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)!==1) { return false; }
        return checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4));
    }

    public static function normalize(string $kind,array $payload,?array $current=null): array
    {
        $fields=$kind==='accounts'?self::ACCOUNT_FIELDS:self::PERIOD_FIELDS;
        if (array_diff(array_keys($payload),array_keys($fields))!==[]) { throw new BadRequestHttpException('Unknown or server-managed field.'); }
        $values=$current===null?($kind==='accounts'?['code'=>'','name'=>'','active'=>true,'postable'=>true,'parent_id'=>null]:['name'=>'','starts_on'=>'','ends_on'=>'']):array_intersect_key($current,array_flip(array_values($fields)));
        $errors=[];
        foreach ($payload as $key=>$value) {
            if (in_array($key,['active','postable'],true)) {
                if (!is_bool($value)) { throw new BadRequestHttpException('A boolean is required.'); }
            } elseif ($key==='parentId') {
                if ($value!==null && !EntraSettings::isGuid($value)) { $errors[$key]='Use a UUID or null.'; }
                elseif ($value!==null) { $value=strtolower($value); }
            } elseif (!is_string($value)) { throw new BadRequestHttpException('A string is required.'); }
            if (in_array($key,['code','name'],true)) { $value=trim($value); }
            $values[$fields[$key]]=$value;
        }
        if (mb_strlen($values['name'])<1 || mb_strlen($values['name'])>200) { $errors['name']='Use 1 to 200 characters.'; }
        if ($kind==='accounts') {
            if (preg_match('/^[0-9]{1,20}$/D',$values['code'])!==1) { $errors['code']='Use 1 to 20 digits as a string.'; }
        } else {
            foreach (['starts_on'=>'startsOn','ends_on'=>'endsOn'] as $column=>$field) {
                if (!self::date($values[$column])) { $errors[$field]='Use a valid date YYYY-MM-DD.'; }
            }
            if ($errors===[] && $values['starts_on']>$values['ends_on']) { $errors['endsOn']='End precedes start.'; }
        }
        if ($errors!==[]) { throw new InputValidationException($errors,'Invalid accounting input'); }
        return $values;
    }
}
