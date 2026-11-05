<?php
namespace App\Tests;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class CorrectionOpenApiTest extends KernelTestCase
{
    public function testCorrectionDraftHasStrictInputAndReplayResponses(): void
    {
        self::bootKernel(); $api=self::getContainer()->get(OpenApiFactoryInterface::class)([]);
        $post=$api->getPaths()->getPath('/api/v1/accounting-units/{unit}/documents/{id}/correction-draft')?->getPost(); self::assertNotNull($post);
        $schema=$post->getRequestBody()->getContent()['application/json']['schema'];
        self::assertFalse($schema['additionalProperties']); self::assertSame(['periodId','postingDate'],$schema['required']);
        self::assertSame(['periodId','postingDate'],array_keys($schema['properties']));
        foreach (['200','201','400','401','403','404','409','412','422','428'] as $status) { self::assertArrayHasKey($status,$post->getResponses()); }
        foreach (['200','201'] as $status) { foreach (['ETag','Location','Idempotency-Replayed'] as $header) { self::assertArrayHasKey($header,$post->getResponses()[$status]->getHeaders()); } }
        self::assertArrayHasKey('Location',$post->getResponses()['409']->getHeaders());
        $headers=array_map(static fn($p)=>$p->getName(),$post->getParameters());
        self::assertContains('If-Match',$headers); self::assertContains('Idempotency-Key',$headers);
    }
}
