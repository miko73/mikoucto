<?php

namespace App\Modules\Core\Application\Identity;

use Doctrine\DBAL\Connection;
use App\Modules\Core\Infrastructure\Identity\ApplicationRoleGuard;
use Symfony\Component\Uid\Uuid;

final class IdentitySession
{
    public function __construct(private Connection $connection, private ApplicationRoleGuard $roleGuard) { }

    public function run(ApplicationUser $user, ?string $unit, callable $operation, ?string $requestId = null, bool $readOnlySnapshot = false): mixed
    {
        $this->roleGuard->check();
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('Identity context must start its own transaction.');
        }
        return $this->connection->transactional(function (Connection $connection) use ($user, $unit, $operation, $requestId, $readOnlySnapshot): mixed {
            if ($readOnlySnapshot) { $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'); }
            $connection->fetchOne('SELECT core.set_context(?::uuid, ?, ?)', [$user->id, $requestId ?? Uuid::v7()->toRfc4122(), 'api']);
            $connection->fetchOne("SELECT set_config('app.accounting_unit_id', ?, true)", [$unit ?? '']);
            return $operation($connection);
        });
    }
}
