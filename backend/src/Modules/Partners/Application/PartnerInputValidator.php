<?php

namespace App\Modules\Partners\Application;

use App\Modules\Partners\ApiResource\PartnerInput;
use App\Modules\Partners\Domain\PartnerValidationException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PartnerInputValidator
{
    public const FIELDS = ['name'=>'name','registrationNumber'=>'registration_number','vatNumber'=>'vat_number','countryCode'=>'country_code',
        'street'=>'street','city'=>'city','postalCode'=>'postal_code','email'=>'email','phone'=>'phone','active'=>'active'];

    public function __construct(private ValidatorInterface $validator) { }

    public function normalize(PartnerInput $input, array $present, ?array $existing = null): array
    {
        $values = [];
        $errors = [];
        $complete = new PartnerInput();
        foreach (self::FIELDS as $property => $column) {
            $value = $existing[$column] ?? match ($property) { 'countryCode'=>'CZ','active'=>true,default=>null };
            if (in_array($property, $present, true)) {
                $value = $input->$property;
                if (is_string($value)) {
                    $value = trim($value);
                    if (in_array($property,['countryCode','registrationNumber','vatNumber'],true)) { $value = strtoupper($value); }
                    if ($value === '' && !in_array($property,['name','countryCode'],true)) { $value = null; }
                }
            }
            if (in_array($property,['name','countryCode','active'],true) && $value === null) {
                $errors[] = ['propertyPath'=>$property,'message'=>'This field must not be null.'];
            }
            if (is_string($value) && preg_match('/[\x00-\x1f\x7f]/u',$value)) {
                $errors[] = ['propertyPath'=>$property,'message'=>'Control characters are not allowed.'];
            }
            $complete->$property = $value;
            $values[$column] = $value;
        }
        foreach ($this->validator->validate($complete) as $violation) {
            $errors[] = ['propertyPath'=>$violation->getPropertyPath(),'message'=>$violation->getMessage()];
        }
        if ($values['registration_number'] !== null) {
            $pattern = $values['country_code'] === 'CZ' ? '/^[0-9]{8}$/D' : '/^[A-Z0-9][A-Z0-9 \/.-]{0,31}$/D';
            if (preg_match($pattern,$values['registration_number']) !== 1) {
                $errors[] = ['propertyPath'=>'registrationNumber','message'=>'Use eight digits for a Czech registration number, or a valid foreign registration number.'];
            }
        }
        if ($errors !== []) { throw new PartnerValidationException($errors); }
        return $values;
    }
}
