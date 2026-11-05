<?php

namespace App\Modules\Core\Infrastructure\Identity;

use App\Modules\Core\Application\Identity\ApplicationUser;
use App\Modules\Core\Application\Identity\EntraIdentity;
use App\Modules\Core\Application\Identity\UserDirectory;
use Doctrine\DBAL\Connection;

final class DatabaseUserDirectory implements UserDirectory
{
    public function __construct(private Connection $connection, private ApplicationRoleGuard $roleGuard) { }

    public function findActive(EntraIdentity $identity): ?ApplicationUser
    {
        $this->roleGuard->check();
        $row = $this->connection->fetchAssociative('SELECT * FROM core.resolve_identity(?::uuid, ?::uuid)', [$identity->tenantId, $identity->objectId]);
        return $row === false ? null : new ApplicationUser($row['id'], $row['display_name']);
    }
}
