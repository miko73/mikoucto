<?php

namespace App\Tests\Support;

use App\Modules\Core\Infrastructure\Identity\EntraAccessTokenValidator;
use App\Modules\Core\Infrastructure\Identity\EntraSettings;
use App\Modules\Core\Infrastructure\Identity\SigningKeys;
use Firebase\JWT\JWT;

// Soukromy testovaci klic vznikne jen v pameti testu. Aplikace tento provider nepouziva.
final class TestTokens
{
    public const TENANT = '11111111-1111-4111-8111-111111111111';
    public const API = '22222222-2222-4222-8222-222222222222';
    public const CLIENT = '33333333-3333-4333-8333-333333333333';
    public const OBJECT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private static ?string $privateKey = null;
    private static array $publicDetails;

    public static function settings(): EntraSettings
    {
        return new EntraSettings(self::TENANT, self::API, self::CLIENT, 'access_as_user');
    }

    public static function keys(): array
    {
        self::privateKey();
        return ['keys' => [[
            'kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig',
            'issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0',
            'n' => JWT::urlsafeB64Encode(self::$publicDetails['rsa']['n']),
            'e' => JWT::urlsafeB64Encode(self::$publicDetails['rsa']['e']),
        ]]];
    }

    public static function validator(): EntraAccessTokenValidator
    {
        $keys = new class implements SigningKeys {
            public function get(): array { return TestTokens::keys(); }
        };
        return new EntraAccessTokenValidator(self::settings(), $keys);
    }

    public static function token(array $overrides = [], string $kid = 'test-key'): string
    {
        $claims = array_replace([
            'ver' => '2.0', 'iss' => self::settings()->issuer(), 'aud' => self::API,
            'tid' => self::TENANT, 'oid' => self::OBJECT, 'azp' => self::CLIENT,
            'iat' => time() - 10, 'nbf' => time() - 10, 'exp' => time() + 600,
            'scp' => 'access_as_user',
        ], $overrides);
        return JWT::encode($claims, self::privateKey(), 'RS256', $kid);
    }

    private static function privateKey(): string
    {
        if (self::$privateKey === null) {
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $config = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            if (is_file($config)) { $options['config'] = $config; }
            $resource = openssl_pkey_new($options);
            if ($resource === false || !openssl_pkey_export($resource, $pem, null, $options)) {
                throw new \RuntimeException('Cannot generate RSA key for tests.');
            }
            self::$privateKey = $pem;
            self::$publicDetails = openssl_pkey_get_details($resource);
        }
        return self::$privateKey;
    }
}
