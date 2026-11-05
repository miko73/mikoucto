<?php
namespace App\Modules\Accounting\Application;

use App\Modules\Core\Application\InputValidationException;
use App\Modules\Core\Infrastructure\Identity\EntraSettings;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class DraftInput
{
    public static function normalize(array $payload,?array $current=null): array
    {
        if (array_diff(array_keys($payload),['periodId','postingDate','text','partnerId','lines'])!==[]) { throw new BadRequestHttpException('Unknown or server-managed field.'); }
        $values=$current??['periodId'=>'','postingDate'=>'','text'=>'','partnerId'=>null,'lines'=>[]];
        foreach ($payload as $key=>$value) { $values[$key]=$value; }
        foreach (['periodId','postingDate','text'] as $key) {
            if (!is_string($values[$key])) { throw new BadRequestHttpException('A string is required.'); }
        }
        if ($values['partnerId']!==null && !is_string($values['partnerId'])) { throw new BadRequestHttpException('partnerId must be a UUID or null.'); }
        if (!is_array($values['lines']) || !array_is_list($values['lines'])) { throw new BadRequestHttpException('lines must be an array.'); }
        $errors=[]; $values['text']=trim($values['text']);
        if (!EntraSettings::isGuid($values['periodId'])) { $errors['periodId']='A period UUID is required.'; }
        if (!AccountingInput::date($values['postingDate'])) { $errors['postingDate']='A valid date YYYY-MM-DD is required.'; }
        if (mb_strlen($values['text'])<1 || mb_strlen($values['text'])>2000) { $errors['text']='Use 1 to 2000 characters.'; }
        if ($values['partnerId']!==null && !EntraSettings::isGuid($values['partnerId'])) { $errors['partnerId']='Use a UUID or null.'; }
        if (count($values['lines'])>500) { $errors['lines']='At most 500 lines are allowed.'; }
        if ($errors!==[]) { throw new InputValidationException($errors); }
        $values['periodId']=strtolower($values['periodId']);
        if ($values['partnerId']!==null) { $values['partnerId']=strtolower($values['partnerId']); }
        $lines=[];
        foreach ($values['lines'] as $index=>$line) {
            if (!is_array($line) || array_diff(array_keys($line),['accountId','text','md','dal'])!==[]) { throw new BadRequestHttpException('Unknown line fields.'); }
            $account=$line['accountId']??null; $text=array_key_exists('text',$line)?$line['text']:'';
            if (!EntraSettings::isGuid($account) || !array_key_exists('md',$line) || !array_key_exists('dal',$line)) { throw new InputValidationException(['lines.'.$index=>'accountId, md and dal are required.']); }
            if (!is_string($text)) { throw new BadRequestHttpException('Line text must be a string.'); }
            $text=trim($text);
            if (mb_strlen($text)>500) { throw new InputValidationException(['lines.'.$index.'.text'=>'At most 500 characters.']); }
            $md=DecimalAmount::normalize($line['md']); $dal=DecimalAmount::normalize($line['dal']);
            if (($md==='0.00')===($dal==='0.00')) { throw new InputValidationException(['lines.'.$index=>'Exactly one side must be positive.']); }
            $lines[]=['accountId'=>strtolower($account),'text'=>$text,'md'=>$md,'dal'=>$dal];
        }
        $values['lines']=$lines;
        return $values;
    }
}
