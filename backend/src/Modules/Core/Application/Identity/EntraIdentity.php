<?php

namespace App\Modules\Core\Application\Identity;

final readonly class EntraIdentity
{
    public function __construct(public string $tenantId, public string $objectId)
    {
    }
}
