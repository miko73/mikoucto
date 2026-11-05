<?php

namespace App\Modules\Core\Infrastructure\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;

final class MigrationFactory
{
    public static function assertTarget(Connection $connection, string $expectedDatabase): void
    {
        if (!in_array($expectedDatabase, ['playground', 'mikoucto_test'], true)) {
            throw new MigrationGuardException('Unknown migration database. Use the project scripts.');
        }
        $identity = $connection->fetchAssociative('SELECT current_database() AS database, current_user AS username');
        if ($identity['database'] !== $expectedDatabase || $identity['username'] !== 'mikoucto_owner') {
            throw new MigrationGuardException('Migration target or role does not match the requested configuration.');
        }
        if ((int) $connection->fetchOne("SELECT current_setting('server_version_num')") < 160000) {
            throw new MigrationGuardException('PostgreSQL 16 or newer is required.');
        }
    }

    public static function create(Connection $connection): DependencyFactory
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory('App\\Migrations', dirname(__DIR__, 5).'/migrations');
        $configuration->setAllOrNothing(true);
        $configuration->setCheckDatabasePlatform(true);
        $storage = new TableMetadataStorageConfiguration();
        $storage->setTableName('migration.versions');
        $configuration->setMetadataStorageConfiguration($storage);
        return DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($connection));
    }

    public static function prepareStorage(Connection $connection, DependencyFactory $factory): void
    {
        $connection->executeStatement('CREATE SCHEMA IF NOT EXISTS migration AUTHORIZATION mikoucto_owner');
        $connection->executeStatement('REVOKE ALL ON SCHEMA migration FROM PUBLIC, mikoucto_app, mikoucto_read');
        $factory->getMetadataStorage()->ensureInitialized();
        $connection->executeStatement('REVOKE ALL ON TABLE migration.versions FROM PUBLIC, mikoucto_app, mikoucto_read');
    }
}
