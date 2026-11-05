<?php
namespace App\Tests;

use App\Modules\Accounting\Application\ReportBalanceControl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportBalanceControlTest extends TestCase
{
    private const TOTALS=['openingMd'=>'100.00','openingDal'=>'100.00','turnoverMd'=>'19999999999999999.98',
        'turnoverDal'=>'19999999999999999.98','closingMd'=>'20000000000000099.98','closingDal'=>'20000000000000099.98'];
    public function testLargeBalancedTotalsArePreservedExactly(): void
    {
        $result=ReportBalanceControl::apply(self::TOTALS);
        self::assertTrue($result['balanced']); self::assertSame('0.00',$result['openingDifference']);
        self::assertSame('0.00',$result['turnoverDifference']); self::assertSame('0.00',$result['closingDifference']);
        foreach (self::TOTALS as $field=>$value) { self::assertSame($value,$result[$field]); }
    }
    public static function differences(): iterable
    {
        yield 'opening'=>['openingMd','100.01','openingDifference','0.01'];
        yield 'turnover'=>['turnoverDal','19999999999999999.99','turnoverDifference','-0.01'];
        yield 'closing'=>['closingMd','20000000000000099.97','closingDifference','-0.01'];
    }
    #[DataProvider('differences')]
    public function testAnyDifferenceIsVisibleWithoutChangingTotals(string $field,string $value,string $difference,string $expected): void
    {
        $input=array_replace(self::TOTALS,[$field=>$value]); $result=ReportBalanceControl::apply($input);
        self::assertFalse($result['balanced']); self::assertSame($expected,$result[$difference]);
        self::assertSame($value,$result[$field]);
    }
}
