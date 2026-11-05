<?php
namespace App\Tests;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class DraftOpenApiTest extends KernelTestCase
{
    public function testDraftContractDocumentsLinesMoneyAndPreconditions(): void
    {
        self::bootKernel(); $api=self::getContainer()->get(OpenApiFactoryInterface::class)([]);
        $base='/api/v1/accounting-units/{unit}/documents';
        $collection=$api->getPaths()->getPath($base); $item=$api->getPaths()->getPath($base.'/{id}');
        self::assertNotNull($collection); self::assertNotNull($item); self::assertNull($item->getDelete());
        self::assertNotNull($api->getPaths()->getPath($base.'/{id}/history'));
        self::assertContains('Idempotency-Key',array_map(static fn($p)=>$p->getName(),$collection->getPost()->getParameters()));
        self::assertContains('If-Match',array_map(static fn($p)=>$p->getName(),$item->getPatch()->getParameters()));
        foreach (['409','412','422','428'] as $code) { self::assertArrayHasKey($code,$item->getPatch()->getResponses()); }
        $schemas=$api->getComponents()->getSchemas();
        self::assertSame(['periodId','postingDate','text'],$schemas['Document.DocumentInput']['required']);
        self::assertSame(500,$schemas['Document.DocumentInput']['properties']['lines']['maxItems']);
        self::assertSame('string',$schemas['DraftLineInput']['properties']['md']['type']);
        self::assertSame(['accountId','md','dal'],$schemas['DraftLineInput']['required']);
        self::assertFalse($schemas['DraftLineInput']['additionalProperties']);
    }
}
