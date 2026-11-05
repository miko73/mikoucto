<?php
namespace App\Tests;
use App\Modules\Accounting\Application\DecimalAmount;
use App\Modules\Core\Application\InputValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class DecimalAmountTest extends TestCase
{
    public function testExactSumsDifferencesAndLargeTotals(): void
    {
        self::assertSame('1.20',DecimalAmount::normalize('1.2'));
        self::assertSame('0.00',DecimalAmount::sum([]));
        self::assertSame('0.30',DecimalAmount::sum(['0.10','0.20']));
        self::assertSame('1.23',DecimalAmount::sum(['1.2','0.03']));
        self::assertSame('19999999999999999.98',DecimalAmount::sum(['9999999999999999.99','9999999999999999.99']));
        self::assertSame('-19999999999999999.97',DecimalAmount::difference('0.01','19999999999999999.98'));
        self::assertSame('0.01',DecimalAmount::difference('10000000000000000.00','9999999999999999.99'));
        self::assertSame('0.00',DecimalAmount::difference('0.00','0.00'));
    }
    #[DataProvider('invalidAmounts')]
    public function testInvalidAmountsAreNotRounded(string $value): void
    {
        $this->expectException(InputValidationException::class); DecimalAmount::normalize($value);
    }
    public static function invalidAmounts(): array
    {
        return array_map(static fn($v)=>[$v],['-1','-0.00','1.000','0.001','1e2','1,20',' 1.20','01.00','10000000000000000.00','NaN','Infinity','']);
    }
}
