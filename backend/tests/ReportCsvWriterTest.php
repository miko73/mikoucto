<?php
namespace App\Tests;

use App\Modules\Accounting\Infrastructure\ReportCsvWriter;
use App\Modules\Accounting\Infrastructure\ReportExportLimit;
use App\Modules\Core\Application\InputValidationException;
use PHPUnit\Framework\TestCase;

final class ReportCsvWriterTest extends TestCase
{
    public function testSpreadsheetTextAndExactAmounts(): void
    {
        foreach (['=1+1','+SUM(1;2)','-test','@name'," \t=1", "\ntext",'＝1+1'] as $text) { self::assertSame("'".$text,ReportCsvWriter::text($text)); }
        foreach (['001','Český účet','text; "citace"',"řádek\r\ndruhý",'123'] as $text) { self::assertSame($text,ReportCsvWriter::text($text)); }
        self::assertSame('-19999999999999999,98',ReportCsvWriter::money('-19999999999999999.98'));
        self::assertSame('0,00',ReportCsvWriter::money('0.00'));
    }
    public function testLimitAccepts50000AndRejects50001(): void
    {
        self::assertSame(50000,ReportExportLimit::MAX_ROWS); ReportExportLimit::check(50000);
        try { ReportExportLimit::check(50001); self::fail('Oversized export accepted.'); }
        catch (InputValidationException $e) { self::assertSame(422,$e->getStatusCode()); self::assertSame('export',$e->violations[0]['propertyPath']); }
    }
    public function testWriterEscapesQuotesSeparatorsAndNewlinesWithoutFloat(): void
    {
        $s=fopen('php://temp','w+b'); $writer=new ReportCsvWriter();
        try {
            $metadata=['filters'=>['accountCode'=>'001']];
            $writer->row($s,['postingDate'=>'2026-11-04','number'=>'INT/1','documentText'=>"Žluťoučký; \"test\"\r\ndalší",
                'text'=>'=SUM(1;2)','md'=>'9999999999999999.99','dal'=>'0.00','runningBalance'=>'19999999999999999.98','lineNo'=>1],$metadata,true);
            rewind($s); $bytes=stream_get_contents($s); self::assertStringEndsWith("\r\n",$bytes);
            rewind($s); $row=fgetcsv($s,0,';','"','');
            self::assertCount(9,$row); self::assertSame('001',$row[2]); self::assertSame("Žluťoučký; \"test\"\r\ndalší",$row[3]);
            self::assertSame("'=SUM(1;2)",$row[4]); self::assertSame('9999999999999999,99',$row[5]); self::assertSame('19999999999999999,98',$row[7]);
        } finally { fclose($s); }
    }
    public function testWriterDoesNotTruncate50000Rows(): void
    {
        $stream=fopen('php://temp/maxmemory:2097152','w+b'); $writer=new ReportCsvWriter();
        $item=['accountCode'=>'001','accountName'=>'Test','openingMd'=>'0.00','openingDal'=>'0.00','turnoverMd'=>'1.00','turnoverDal'=>'0.00','closingMd'=>'1.00','closingDal'=>'0.00'];
        try {
            for ($i=0;$i<50000;++$i) { $writer->row($stream,$item,[],false); }
            rewind($stream); $count=0; while (fgetcsv($stream,0,';','"','')!==false) { ++$count; }
            self::assertSame(50000,$count);
        } finally { fclose($stream); }
    }
}
