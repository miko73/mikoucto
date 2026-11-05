<?php
namespace App\Tests;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class PostingOpenApiTest extends KernelTestCase
{
    public function testPostingAndClosingCommandsDocumentPreconditionsAndEmptyBodies(): void
    {
        self::bootKernel(); $api=self::getContainer()->get(OpenApiFactoryInterface::class)([]);
        foreach (['documents/{id}/post','periods/{id}/close'] as $suffix) {
            $operation=$api->getPaths()->getPath('/api/v1/accounting-units/{unit}/'.$suffix)?->getPost();
            self::assertNotNull($operation); $parameters=array_map(static fn($p)=>$p->getName(),$operation->getParameters());
            self::assertContains('If-Match',$parameters); self::assertContains('Idempotency-Key',$parameters);
            foreach (['200','409','412','428'] as $status) { self::assertArrayHasKey($status,$operation->getResponses()); }
            self::assertSame(0,$operation->getRequestBody()->getContent()['application/json']['schema']['maxProperties']);
            self::assertArrayHasKey('ETag',$operation->getResponses()['200']->getHeaders());
        }
    }
}
