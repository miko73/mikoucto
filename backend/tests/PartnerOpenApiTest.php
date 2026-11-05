<?php

namespace App\Tests;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Modules\Core\Application\Identity\ApplicationUser;
use App\Modules\Core\Application\Identity\UserDirectory;
use App\Tests\Support\TestTokens;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PartnerOpenApiTest extends WebTestCase
{
    public function testSchemaDocumentsSecurityPreconditionsAndNoDelete(): void
    {
        self::bootKernel();
        $factory=self::getContainer()->get(OpenApiFactoryInterface::class);
        $api=$factory([]);
        self::assertSame('3.1.0',$api->getOpenapi());
        $collection=$api->getPaths()->getPath('/api/v1/accounting-units/{unit}/partners');
        $item=$api->getPaths()->getPath('/api/v1/accounting-units/{unit}/partners/{id}');
        self::assertNotNull($collection); self::assertNotNull($item); self::assertNull($item->getDelete());
        self::assertSame([['bearerAuth'=>[]]],$api->getSecurity());
        self::assertSame('http',$api->getComponents()->getSecuritySchemes()['bearerAuth']->getType());
        self::assertContains('Idempotency-Key',array_map(static fn ($p)=>$p->getName(),$collection->getPost()->getParameters()));
        self::assertContains('If-Match',array_map(static fn ($p)=>$p->getName(),$item->getPatch()->getParameters()));
        self::assertArrayHasKey('412',$item->getPatch()->getResponses());
        self::assertArrayHasKey('428',$item->getPatch()->getResponses());
        $schemas=$api->getComponents()->getSchemas();
        self::assertSame(['name'],$schemas['Partner.PartnerInput']['required']);
        self::assertSame('boolean',$schemas['Partner.PartnerInput.jsonMergePatch']['properties']['active']['type']);
        self::assertArrayNotHasKey('default',$schemas['Partner.PartnerInput.jsonMergePatch']['properties']['active']);
    }

    public function testDocumentationRequiresAuthentication(): void
    {
        $client=self::createClient();
        $client->request('GET','/api/docs.json');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedDocumentationIsOpenApi31(): void
    {
        $client=self::createClient();
        self::getContainer()->set(AccessTokenValidator::class,TestTokens::validator());
        $users=$this->createMock(UserDirectory::class);
        $users->method('findActive')->willReturn(new ApplicationUser('00000000-0000-7000-8000-000000000001','Documentation user'));
        self::getContainer()->set(UserDirectory::class,$users);
        $client->request('GET','/api/docs.json',server:['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token()]);
        self::assertResponseIsSuccessful();
        $spec=json_decode($client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR);
        self::assertSame('3.1.0',$spec['openapi']);
        self::assertArrayHasKey('bearerAuth',$spec['components']['securitySchemes']);
    }
}
