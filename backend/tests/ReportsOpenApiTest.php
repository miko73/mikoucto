<?php
namespace App\Tests;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReportsOpenApiTest extends KernelTestCase
{
    public function testReportContractsExposeExactAmountsAndEnvelopes(): void
    {
        self::bootKernel(); $api=self::getContainer()->get(OpenApiFactoryInterface::class)([]);
        foreach (['general-ledger'=>'GeneralLedgerReport','trial-balance'=>'TrialBalanceReport'] as $kind=>$schema) {
            $path=$api->getPaths()->getPath('/api/v1/accounting-units/{unit}/reports/'.$kind);
            self::assertNotNull($path); self::assertNull($path->getPost()); self::assertNull($path->getPatch());
            $get=$path->getGet(); $params=[];
            foreach ($get->getParameters() as $p) { $params[$p->getName()]=$p; }
            self::assertTrue($params['periodId']->getRequired());
            foreach (['from','to','page','itemsPerPage'] as $p) { self::assertArrayHasKey($p,$params); }
            if ($kind==='general-ledger') { self::assertTrue($params['accountId']->getRequired()); self::assertArrayNotHasKey('includeZero',$params); }
            else { self::assertArrayHasKey('includeZero',$params); self::assertArrayNotHasKey('accountId',$params); }
            self::assertSame('#/components/schemas/'.$schema,$get->getResponses()['200']->getContent()['application/json']['schema']['$ref']);
            $definition=(array)$api->getComponents()->getSchemas()[$schema];
            foreach (['filters','totals','items','total','generatedAt','basis'] as $key) { self::assertContains($key,$definition['required']); }
            $amount=$definition['properties']['totals']['properties']['turnoverMd'];
            self::assertSame('string',$amount['type']); self::assertMatchesRegularExpression('/'.$amount['pattern'].'/','19999999999999999.98');
            foreach (['400','401','403','404'] as $status) { self::assertArrayHasKey($status,$get->getResponses()); }
            $csv=$api->getPaths()->getPath('/api/v1/accounting-units/{unit}/reports/'.$kind.'.csv')->getGet();
            self::assertSame('binary',$csv->getResponses()['200']->getContent()['text/csv']['schema']['format']);
            self::assertArrayHasKey('422',$csv->getResponses());
            $csvNames=array_map(static fn($p)=>$p->getName(),$csv->getParameters());
            self::assertNotContains('page',$csvNames); self::assertNotContains('itemsPerPage',$csvNames);
        }
    }
}
