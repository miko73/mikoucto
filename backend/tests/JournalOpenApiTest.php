<?php
namespace App\Tests;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class JournalOpenApiTest extends KernelTestCase
{
    public function testJournalDocumentsFiltersAndReadOnlyCollection(): void
    {
        self::bootKernel(); $api=self::getContainer()->get(OpenApiFactoryInterface::class)([]);
        $path=$api->getPaths()->getPath('/api/v1/accounting-units/{unit}/journal');
        self::assertNotNull($path); self::assertNull($path->getPost());
        $get=$path->getGet(); $names=array_map(static fn($p)=>$p->getName(),$get->getParameters());
        foreach (['page','itemsPerPage','periodId','accountId','search','from','to'] as $name) { self::assertContains($name,$names); }
        self::assertArrayHasKey('X-Total-Count',$get->getResponses()['200']->getHeaders());
    }
}
