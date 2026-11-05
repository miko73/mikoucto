<?php
namespace App\Tests;
use App\Modules\Accounting\Application\AccountingInput;
use App\Modules\Core\Application\InputValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
final class AccountingInputTest extends TestCase
{
    public function testLeadingZeroesDefaultsAndPartialUpdate(): void
    {
        $values=AccountingInput::normalize('accounts',['code'=>'001','name'=>' Test ']);
        self::assertSame(['code'=>'001','name'=>'Test','active'=>true,'postable'=>true,'parent_id'=>null],$values);
        $changed=AccountingInput::normalize('accounts',['active'=>false],$values);
        self::assertFalse($changed['active']); self::assertSame('001',$changed['code']);
    }
    public function testValidDatesIncludeLeapDayButRejectInvalidDates(): void
    {
        self::assertTrue(AccountingInput::date('2024-02-29'));
        foreach (['2026-02-29','0000-01-01','2026-1-01','2026-01-01T00:00:00Z',null,20260101] as $value) { self::assertFalse(AccountingInput::date($value)); }
        self::assertSame('2026-12-31',AccountingInput::normalize('periods',['name'=>'Y','startsOn'=>'2026-01-01','endsOn'=>'2026-12-31'])['ends_on']);
    }
    public function testReverseRangeRejected(): void
    {
        $this->expectException(InputValidationException::class);
        AccountingInput::normalize('periods',['name'=>'Y','startsOn'=>'2026-12-31','endsOn'=>'2026-01-01']);
    }
    public function testUnknownAndServerFieldsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        AccountingInput::normalize('accounts',['code'=>'1','name'=>'A','accountingUnitId'=>'foreign']);
    }
    public function testBooleanStringRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        AccountingInput::normalize('accounts',['code'=>'1','name'=>'A','active'=>'false']);
    }
}
