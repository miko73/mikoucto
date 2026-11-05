<?php

namespace App\Tests;

use App\Modules\Core\Infrastructure\Identity\EntraAccessTokenValidator;
use App\Modules\Core\Infrastructure\Identity\EntraSettings;
use App\Modules\Core\Infrastructure\Identity\IdentityConfigurationException;
use App\Modules\Core\Infrastructure\Identity\InvalidAccessToken;
use App\Modules\Core\Infrastructure\Identity\SigningKeys;
use App\Tests\Support\TestTokens;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntraTokenTest extends TestCase
{
    public function testValidSignedDelegatedTokenResolvesStableIdentity(): void
    {
        $identity = TestTokens::validator()->validate(TestTokens::token());
        self::assertSame(TestTokens::TENANT, $identity->tenantId);
        self::assertSame(TestTokens::OBJECT, $identity->objectId);
    }

    #[DataProvider('invalidClaims')]
    public function testInvalidClaimsAreRejected(array $claims, array $timeOffsets = []): void
    {
        // PHPUnit evaluates providers before running the whole suite. Resolve
        // relative times here so a slow earlier test cannot make nbf valid.
        $now = time();
        foreach ($timeOffsets as $claim => $offset) {
            $claims[$claim] = $now + $offset;
        }
        $this->expectException(InvalidAccessToken::class);
        TestTokens::validator()->validate(TestTokens::token($claims));
    }

    public static function invalidClaims(): array
    {
        return [
            'wrong audience' => [['aud' => '44444444-4444-4444-8444-444444444444']],
            'wrong issuer' => [['iss' => 'https://example.com/']],
            'wrong tenant' => [['tid' => '44444444-4444-4444-8444-444444444444']],
            'missing object' => [['oid' => null]],
            'invalid object' => [['oid' => 'email@example.com']],
            'expired' => [[], ['exp' => -100]],
            'not active yet' => [[], ['nbf' => 100]],
            'missing expiry' => [['exp' => null]],
            'missing nbf' => [['nbf' => null]],
            'future issued time' => [[], ['iat' => 300]],
            'wrong client' => [['azp' => '44444444-4444-4444-8444-444444444444']],
            'missing client' => [['azp' => null]],
            'wrong scope' => [['scp' => 'User.Read']],
            'id token or app-only token' => [['scp' => null, 'roles' => ['administrator']]],
            'v1 token' => [['ver' => '1.0']],
        ];
    }

    public function testChangedSignatureIsRejected(): void
    {
        $parts = explode('.', TestTokens::token());
        $signature = JWT::urlsafeB64Decode($parts[2]);
        $signature[0] = chr(ord($signature[0]) ^ 1);
        $parts[2] = JWT::urlsafeB64Encode($signature);
        $this->expectException(InvalidAccessToken::class);
        TestTokens::validator()->validate(implode('.', $parts));
    }

    public function testUnknownSigningKeyIsRejected(): void
    {
        $this->expectException(InvalidAccessToken::class);
        TestTokens::validator()->validate(TestTokens::token([], 'unknown-key'));
    }

    public function testSymmetricAlgorithmIsRejectedBeforeLoadingKeys(): void
    {
        $keys = $this->createMock(SigningKeys::class);
        $keys->expects(self::never())->method('get');
        $this->expectException(InvalidAccessToken::class);
        (new EntraAccessTokenValidator(TestTokens::settings(), $keys))->validate(JWT::encode(['exp' => time() + 60], str_repeat('x', 64), 'HS256', 'test-key'));
    }

    public function testMissingConfigurationDoesNotAcceptAnyToken(): void
    {
        $keys = $this->createMock(SigningKeys::class);
        $keys->expects(self::never())->method('get');
        $this->expectException(IdentityConfigurationException::class);
        (new EntraAccessTokenValidator(new EntraSettings('', '', '', 'access_as_user'), $keys))->validate(TestTokens::token());
    }
}
