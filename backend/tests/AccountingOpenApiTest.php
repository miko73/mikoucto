<?php
namespace App\Tests;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class AccountingOpenApiTest extends KernelTestCase
{
    public function testAccountingRoutesInputsAndPreconditionsAreDocumented(): void
    {
        self::bootKernel(); $api=self::getContainer()->get(OpenApiFactoryInterface::class)([]);
        foreach (['accounts','periods'] as $kind) {
            $base='/api/v1/accounting-units/{unit}/'.$kind;
            $collection=$api->getPaths()->getPath($base); $item=$api->getPaths()->getPath($base.'/{id}');
            self::assertNotNull($collection); self::assertNotNull($item); self::assertNull($item->getDelete());
            self::assertNotNull($api->getPaths()->getPath($base.'/{id}/history'));
            self::assertContains('Idempotency-Key',array_map(static fn($p)=>$p->getName(),$collection->getPost()->getParameters()));
            self::assertContains('If-Match',array_map(static fn($p)=>$p->getName(),$item->getPatch()->getParameters()));
            foreach (['409','412','428'] as $status) { self::assertArrayHasKey($status,$item->getPatch()->getResponses()); }
            $parameters=$collection->getGet()->getParameters();
            $keys=array_map(static fn($p)=>$p->getIn().':'.$p->getName(),$parameters);
            self::assertSame($keys,array_values(array_unique($keys)));
            self::assertContains('query:search',$keys);
            self::assertContains('query:'.($kind==='accounts'?'active':'on'),$keys);
        }
        $schemas=$api->getComponents()->getSchemas();
        self::assertSame(['code','name'],$schemas['Account.AccountInput']['required']);
        self::assertSame(['name','startsOn','endsOn'],$schemas['Period.PeriodInput']['required']);
        self::assertSame('date',$schemas['Period.PeriodInput']['properties']['startsOn']['format']);
        self::assertSame('boolean',$schemas['Account.AccountInput']['properties']['active']['type']);
        self::assertSame([['bearerAuth'=>[]]],$api->getSecurity());
    }
}
