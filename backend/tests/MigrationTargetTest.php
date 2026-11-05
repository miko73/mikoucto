<?php

namespace App\Tests;

use App\Modules\Core\Infrastructure\Migrations\MigrationFactory;
use App\Modules\Core\Infrastructure\Migrations\MigrationGuardException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class MigrationTargetTest extends TestCase
{
    public function testUnknownDatabaseIsRejectedBeforeConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');
        $this->expectException(MigrationGuardException::class);
        MigrationFactory::assertTarget($connection, 'production');
    }

    public function testDevelopmentConnectionCannotBeUsedAsTestDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn(['database' => 'playground', 'username' => 'mikoucto_owner']);
        $connection->expects(self::never())->method('executeStatement');
        $this->expectException(MigrationGuardException::class);
        MigrationFactory::assertTarget($connection, 'mikoucto_test');
    }

    public function testApplicationRoleCannotRunMigrations(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn(['database' => 'mikoucto_test', 'username' => 'mikoucto_app']);
        $connection->expects(self::never())->method('executeStatement');
        $this->expectException(MigrationGuardException::class);
        MigrationFactory::assertTarget($connection, 'mikoucto_test');
    }
}
