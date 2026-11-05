<?php

namespace App\Modules\Core\Infrastructure\Identity;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class EntraSigningKeys implements SigningKeys
{
    public function __construct(private EntraSettings $settings, private HttpClientInterface $http, private CacheInterface $cache) { }

    public function get(): array
    {
        $this->settings->assertConfigured();
        try {
            return $this->cache->get('entra.jwks.'.$this->settings->tenantId, function (ItemInterface $item): array {
                $item->expiresAfter(300);
                $keys = $this->http->request('GET', $this->settings->keysUrl(), ['timeout' => 5, 'max_duration' => 10, 'max_redirects' => 0])->toArray();
                if (!isset($keys['keys']) || !is_array($keys['keys']) || $keys['keys'] === []) {
                    throw new \RuntimeException('Missing signing keys.');
                }
                return $keys;
            });
        } catch (\Throwable) {
            throw new IdentityProviderUnavailable('Signing keys are unavailable.');
        }
    }
}
