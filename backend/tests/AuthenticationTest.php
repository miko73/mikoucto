<?php

namespace App\Tests;

use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Modules\Core\Application\Identity\ApplicationUser;
use App\Modules\Core\Application\Identity\UserDirectory;
use App\Modules\Core\Infrastructure\Identity\EntraAccessTokenValidator;
use App\Modules\Core\Infrastructure\Identity\EntraSettings;
use App\Modules\Core\Infrastructure\Identity\SigningKeys;
use App\Tests\Support\TestTokens;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    public function testProtectedEndpointWithoutTokenReturns401(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertResponseHasHeader('www-authenticate');
    }

    public function testValidSignatureReachesOnlyProvisionedIdentity(): void
    {
        $client = self::createClient();
        self::getContainer()->set(AccessTokenValidator::class, TestTokens::validator());
        $directory = $this->createMock(UserDirectory::class);
        $directory->expects(self::once())->method('findActive')->willReturn(new ApplicationUser('00000000-0000-7000-8000-000000000001', 'Test user'));
        self::getContainer()->set(UserDirectory::class, $directory);
        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokens::token(), 'HTTP_X_USER_ID' => 'forged-user']);
        self::assertResponseIsSuccessful();
        self::assertSame('00000000-0000-7000-8000-000000000001', json_decode($client->getResponse()->getContent(), true)['id']);
    }

    public function testUnprovisionedIdentityReturns403(): void
    {
        $client = self::createClient();
        self::getContainer()->set(AccessTokenValidator::class, TestTokens::validator());
        $directory = $this->createMock(UserDirectory::class);
        $directory->method('findActive')->willReturn(null);
        self::getContainer()->set(UserDirectory::class, $directory);
        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokens::token()]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBadTokenDoesNotQueryUserDirectory(): void
    {
        $client = self::createClient();
        self::getContainer()->set(AccessTokenValidator::class, TestTokens::validator());
        $directory = $this->createMock(UserDirectory::class);
        $directory->expects(self::never())->method('findActive');
        self::getContainer()->set(UserDirectory::class, $directory);
        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokens::token(['aud' => TestTokens::CLIENT])]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnconfiguredEntraReturns503ForBearer(): void
    {
        $client = self::createClient();
        self::getContainer()->set(AccessTokenValidator::class, new EntraAccessTokenValidator(new EntraSettings('', '', '', 'access_as_user'), $this->createMock(SigningKeys::class)));
        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer token']);
        self::assertResponseStatusCodeSame(503);
        self::assertStringNotContainsString('token', $client->getResponse()->getContent());
    }
}
