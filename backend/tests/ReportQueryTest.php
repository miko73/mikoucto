<?php
namespace App\Tests;

use App\Modules\Accounting\Application\ReportQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use App\Modules\Core\Application\InputValidationException;

final class ReportQueryTest extends TestCase
{
    private const ID='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    public function testDefaultsAndCanonicalIds(): void
    {
        $q=ReportQuery::parse(['periodId'=>strtoupper(self::ID),'accountId'=>self::ID],true);
        self::assertSame(self::ID,$q['periodId']); self::assertSame(1,$q['page']); self::assertSame(20,$q['itemsPerPage']); self::assertFalse($q['includeZero']);
        self::assertTrue(ReportQuery::parse(['periodId'=>self::ID,'includeZero'=>'true'],false)['includeZero']);
    }
    public static function invalid(): iterable
    {
        foreach ([['periodId'=>'no'],['from'=>'2026-02-30'],['from'=>'2026-12-01','to'=>'2026-01-01'],['page'=>'0'],['page'=>'100001'],
            ['itemsPerPage'=>'101'],['itemsPerPage'=>[]],['from'=>['x']],['includeZero'=>'1'],['search'=>'x'],['accountId'=>self::ID]] as $i=>$case) { yield (string)$i=>[$case]; }
    }
    #[DataProvider('invalid')]
    public function testInvalidTrialFilters(array $case): void
    {
        $this->expectException(InputValidationException::class);
        ReportQuery::parse(array_replace(['periodId'=>self::ID],$case),false);
    }
    public function testLedgerRequiresAccount(): void
    {
        $this->expectException(InputValidationException::class); ReportQuery::parse(['periodId'=>self::ID],true);
    }
    public function testQueryErrorsIdentifyFieldWith400AndOtherInputKeeps422(): void
    {
        try { ReportQuery::parse(['periodId'=>self::ID,'to'=>'2026-02-30'],false); self::fail('Invalid date accepted.'); }
        catch (InputValidationException $error) {
            self::assertSame(400,$error->getStatusCode());
            self::assertSame('to',$error->violations[0]['propertyPath']);
        }
        self::assertSame(422,(new InputValidationException(['name'=>'Required']))->getStatusCode());
    }
    public function testExportRejectsPagination(): void
    {
        self::assertSame(self::ID,ReportQuery::parse(['periodId'=>self::ID],false,export:true)['periodId']);
        foreach (['page','itemsPerPage'] as $field) {
            try { ReportQuery::parse(['periodId'=>self::ID,$field=>'1'],false,export:true); self::fail('CSV pagination accepted.'); }
            catch (InputValidationException $e) { self::assertSame(400,$e->getStatusCode()); self::assertSame($field,$e->violations[0]['propertyPath']); }
        }
    }
}
