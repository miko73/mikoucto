<?php

namespace App\Modules\Core\Infrastructure\Identity;

final readonly class EntraSettings
{
    public string $tenantId;
    public string $apiClientId;
    public array $allowedClients;

    public function __construct(string $tenantId, string $apiClientId, string $allowedClientIds, public string $requiredScope)
    {
        $this->tenantId = strtolower(trim($tenantId));
        $this->apiClientId = strtolower(trim($apiClientId));
        $this->allowedClients = array_values(array_filter(array_map(static fn (string $id): string => strtolower(trim($id)), explode(',', $allowedClientIds))));
    }

    public static function isGuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value) === 1
            && $value !== '00000000-0000-0000-0000-000000000000';
    }

    public function assertConfigured(): void
    {
        if (!self::isGuid($this->tenantId) || !self::isGuid($this->apiClientId) || $this->allowedClients === []
            || preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $this->requiredScope) !== 1) {
            throw new IdentityConfigurationException('Entra is not configured.');
        }
        foreach ($this->allowedClients as $client) {
            if (!self::isGuid($client) || $client === $this->apiClientId) {
                throw new IdentityConfigurationException('Register separate API and public clients.');
            }
        }
    }

    public function issuer(): string { return 'https://login.microsoftonline.com/'.$this->tenantId.'/v2.0'; }
    public function keysUrl(): string { return 'https://login.microsoftonline.com/'.$this->tenantId.'/discovery/v2.0/keys'; }
}
