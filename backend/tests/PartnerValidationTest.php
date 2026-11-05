<?php

namespace App\Tests;

use App\Modules\Partners\ApiResource\PartnerInput;
use App\Modules\Partners\Application\PartnerInputValidator;
use App\Modules\Partners\Application\PartnerPreconditions;
use App\Modules\Partners\Domain\PartnerValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Validation;

final class PartnerValidationTest extends TestCase
{
    public function testCreateNormalizesWhitespaceAndDefaults(): void
    {
        $input=new PartnerInput(); $input->name='  Firma  '; $input->countryCode='cz'; $input->registrationNumber=' 12345678 ';
        $values=$this->validator()->normalize($input,['name','countryCode','registrationNumber']);
        self::assertSame('Firma',$values['name']); self::assertSame('CZ',$values['country_code']);
        self::assertSame('12345678',$values['registration_number']); self::assertTrue($values['active']);
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidFieldsAreRejected(string $field,mixed $value): void
    {
        $input=new PartnerInput(); $input->name='Firma'; $input->$field=$value;
        $this->expectException(PartnerValidationException::class);
        $this->validator()->normalize($input,array_unique(['name',$field]));
    }

    public static function invalidInputs(): array
    {
        return [['name','  '],['name',null],['name',str_repeat('x',201)],['name',"bad\0value"],['registrationNumber','123'],
            ['countryCode','ZZ'],['countryCode',''],['countryCode',null],['vatNumber','bad!'],['email','not-an-email'],['active',null]];
    }

    public function testPartialUpdatePreservesUnspecifiedFieldsAndClearsNullableContact(): void
    {
        $input=new PartnerInput(); $input->email=null;
        $values=$this->validator()->normalize($input,['email'],['name'=>'Old','country_code'=>'CZ','email'=>'old@example.com','active'=>false]);
        self::assertNull($values['email']); self::assertSame('Old',$values['name']); self::assertFalse($values['active']);
    }

    public function testMissingVersionReturns428AndCorrectVersionIsAccepted(): void
    {
        $id='00000000-0000-7000-8000-000000000001';
        self::assertSame(3,PartnerPreconditions::version('"'.$id.':3"',$id));
        try { PartnerPreconditions::version(null,$id); self::fail(); }
        catch (HttpExceptionInterface $exception) { self::assertSame(428,$exception->getStatusCode()); }
    }

    #[DataProvider('invalidEtags')]
    public function testWildcardWeakAndForeignEtagsAreRejected(string $tag,int $status): void
    {
        try { PartnerPreconditions::version($tag,'00000000-0000-7000-8000-000000000001'); self::fail(); }
        catch (HttpExceptionInterface $exception) { self::assertSame($status,$exception->getStatusCode()); }
    }

    public static function invalidEtags(): array
    {
        return [['*',400],['W/"00000000-0000-7000-8000-000000000001:1"',400],['"00000000-0000-7000-8000-000000000002:1"',412]];
    }

    private function validator(): PartnerInputValidator { return new PartnerInputValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()); }
}
