<?php
namespace App\Tests;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class ReversalOpenApiTest extends KernelTestCase
{
    public function testReversalDocumentsInputStatusesAndIndependentRelations(): void
    {
        self::bootKernel(); $api=self::getContainer()->get(OpenApiFactoryInterface::class)([]);
        $base='/api/v1/accounting-units/{unit}/documents/{id}';
        $post=$api->getPaths()->getPath($base.'/reverse')?->getPost(); self::assertNotNull($post);
        $schema=$post->getRequestBody()->getContent()['application/json']['schema'];
        self::assertFalse($schema['additionalProperties']); self::assertSame(['periodId','postingDate','reason'],$schema['required']);
        self::assertSame(1000,$schema['properties']['reason']['maxLength']);
        foreach (['201','200','409','412','428','422'] as $status) { self::assertArrayHasKey($status,$post->getResponses()); }
        foreach (['200','201'] as $status) { foreach (['ETag','Location','Idempotency-Replayed'] as $header) { self::assertArrayHasKey($header,$post->getResponses()[$status]->getHeaders()); } }
        $parameters=array_map(static fn($p)=>$p->getName(),$post->getParameters());
        self::assertContains('If-Match',$parameters); self::assertContains('Idempotency-Key',$parameters);
        self::assertArrayHasKey('Location',$post->getResponses()['409']->getHeaders());
        $get=$api->getPaths()->getPath($base.'/relations')?->getGet(); self::assertNotNull($get);
        self::assertArrayNotHasKey('ETag',(array)$get->getResponses()['200']->getHeaders());
    }
}
