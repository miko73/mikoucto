<?php

namespace App\Modules\Core\Infrastructure\Identity;

use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Modules\Core\Application\Identity\EntraIdentity;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

final class EntraAccessTokenValidator implements AccessTokenValidator
{
    public function __construct(private EntraSettings $settings, private SigningKeys $keys) { }

    public function validate(string $token): EntraIdentity
    {
        $this->settings->assertConfigured();
        if (strlen($token) > 16384 || count(explode('.', $token)) !== 3) {
            throw new InvalidAccessToken('Invalid access token.');
        }
        try {
            $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new InvalidAccessToken('Invalid access token.');
        }
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null) || strlen($header['kid']) > 256) {
            throw new InvalidAccessToken('Invalid signing key.');
        }
        // Pouze pevny tenant endpoint. URL v tokenu (jku/x5u) se nepouzivaji.
        $keySet = $this->keys->get();
        try {
            $matching = array_values(array_filter($keySet['keys'] ?? [], function (array $key) use ($header): bool {
                $issuer = str_replace('{tenantid}', $this->settings->tenantId, $key['issuer'] ?? $this->settings->issuer());
                return ($key['kid'] ?? '') === $header['kid'] && ($key['kty'] ?? '') === 'RSA'
                    && ($key['alg'] ?? 'RS256') === 'RS256' && ($key['use'] ?? 'sig') === 'sig'
                    && $issuer === $this->settings->issuer();
            }));
            if (count($matching) !== 1) { throw new \RuntimeException('Unknown key.'); }
            $claims = JWT::decode($token, JWK::parseKeySet(['keys' => $matching], 'RS256'));
            if (($claims->ver ?? null) !== '2.0' || ($claims->iss ?? null) !== $this->settings->issuer()
                || ($claims->aud ?? null) !== $this->settings->apiClientId
                || strtolower($claims->tid ?? '') !== $this->settings->tenantId
                || !EntraSettings::isGuid($claims->oid ?? null)
                || !is_int($claims->exp ?? null) || !is_int($claims->nbf ?? null) || !is_int($claims->iat ?? null)
                || $claims->exp <= $claims->iat || $claims->nbf > $claims->exp || $claims->iat > time() + 60
                || !is_string($claims->scp ?? null) || !in_array($this->settings->requiredScope, explode(' ', $claims->scp), true)
                || !is_string($claims->azp ?? null) || !in_array(strtolower($claims->azp), $this->settings->allowedClients, true)) {
                throw new \RuntimeException('Invalid claims.');
            }
            return new EntraIdentity($this->settings->tenantId, strtolower($claims->oid));
        } catch (\Throwable) {
            throw new InvalidAccessToken('Invalid access token.');
        }
    }
}
