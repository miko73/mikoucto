<?php

namespace App\Tests\Integration;

use App\Modules\Core\Infrastructure\ConnectionFactory;
use App\Modules\Core\Infrastructure\Migrations\BaselineSchema;
use App\Modules\Core\Infrastructure\Migrations\MigrationFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    private Connection $owner;
    private Connection $app;
    private Connection $reader;
    private string $table;
    private string $plainTable;

    protected function setUp(): void
    {
        // Vsechny tri URL se overuji pred otevrenim prvniho spojeni.
        $urls = [];
        foreach (['owner', 'app', 'read'] as $role) {
            $url = getenv('MIKOUCTO_TEST_'.strtoupper($role).'_URL') ?: '';
            $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
            if (($parameters['dbname'] ?? '') !== 'mikoucto_test' || ($parameters['user'] ?? '') !== 'mikoucto_'.$role) {
                throw new \RuntimeException('Integration tests require explicit mikoucto_test URLs and the expected roles.');
            }
            $urls[$role] = $url;
        }
        $this->owner = ConnectionFactory::create($urls['owner']);
        MigrationFactory::assertTarget($this->owner, 'mikoucto_test');
        self::assertSame(16, intdiv((int) $this->owner->fetchOne("SELECT current_setting('server_version_num')"), 10000));
        $this->app = ConnectionFactory::create($urls['app']);
        $this->reader = ConnectionFactory::create($urls['read']);
        foreach (['app' => $this->app, 'read' => $this->reader] as $role => $connection) {
            self::assertSame('mikoucto_test', $connection->fetchOne('SELECT current_database()'));
            self::assertSame('mikoucto_'.$role, $connection->fetchOne('SELECT session_user'));
        }
        $this->plainTable = '_stage3_'.bin2hex(random_bytes(6));
        $this->table = 'core.'.$this->plainTable;
        $this->owner->executeStatement('CREATE TABLE '.$this->table.' (id uuid PRIMARY KEY, name text NOT NULL, version integer NOT NULL DEFAULT 1)');
        $this->owner->fetchOne('SELECT core.audit_attach(?::regclass)', [$this->table]);
    }

    protected function tearDown(): void
    {
        foreach (['app', 'reader', 'owner'] as $property) {
            if (isset($this->$property) && $this->$property->isTransactionActive()) {
                $this->$property->rollBack();
            }
        }
        if (isset($this->owner, $this->table)) {
            $this->owner->executeStatement('DROP TABLE IF EXISTS '.$this->table);
        }
        foreach (['app', 'reader', 'owner'] as $property) {
            if (isset($this->$property)) {
                $this->$property->close();
            }
        }
    }

    public function testCommittedWriteHasAuditAndCanBeReadByReportRole(): void
    {
        $id = '00000000-0000-7000-8000-000000000001';
        $user = '00000000-0000-7000-8000-000000000002';
        $request = bin2hex(random_bytes(8));
        $this->app->beginTransaction();
        $this->app->fetchOne('SELECT core.set_context(?::uuid, ?, ?)', [$user, $request, 'integration']);
        $this->app->executeStatement('INSERT INTO '.$this->table.' (id, name) VALUES (?, ?)', [$id, 'first']);
        $this->app->executeStatement('UPDATE '.$this->table.' SET name=?, version=version+1 WHERE id=?', ['second', $id]);
        $this->app->executeStatement('UPDATE '.$this->table.' SET name=? WHERE id=?', ['second', $id]);
        $this->app->commit();

        self::assertSame('second', $this->reader->fetchOne('SELECT name FROM '.$this->table.' WHERE id=?', [$id]));
        // Obecny audit bez jednotky kontroluje vlastnik; audit jednotek chrani RLS faze 4.
        $audit = $this->owner->fetchAllAssociative('SELECT operation, user_id, request_id, client FROM core.audit_log WHERE table_name=? ORDER BY id', [$this->table]);
        self::assertCount(2, $audit);
        self::assertSame(['I', 'U'], array_column($audit, 'operation'));
        foreach ($audit as $row) {
            self::assertSame($user, $row['user_id']);
            self::assertSame($request, $row['request_id']);
            self::assertSame('integration', $row['client']);
        }
        self::assertTrue((bool) $this->app->fetchOne("SELECT nullif(current_setting('app.user_id', true), '') IS NULL"));
        self::assertGreaterThanOrEqual(2, (int) $this->owner->fetchOne('SELECT core.audit_seal()'));
        self::assertTrue((bool) $this->reader->fetchOne('SELECT ok FROM core.audit_verify()'));
    }

    public function testRealApplicationLoginCannotWriteWithoutAuditContext(): void
    {
        $this->app->beginTransaction();
        try {
            $this->app->executeStatement("INSERT INTO {$this->table} (id, name) VALUES ('00000000-0000-7000-8000-000000000003', 'missing-context')");
            self::fail('Missing audit context must reject the application write.');
        } catch (Exception $exception) {
            self::assertSame('P0001', $exception->getSQLState());
        } finally {
            $this->app->rollBack();
        }
        self::assertSame(0, (int) $this->owner->fetchOne('SELECT count(*) FROM '.$this->table));
        self::assertSame(0, $this->auditCount());
    }

    public function testFailedTransactionRollsBackBusinessRowsAndAudit(): void
    {
        $this->app->beginTransaction();
        try {
            $this->app->fetchOne("SELECT core.set_context('00000000-0000-7000-8000-000000000002', 'rollback', 'integration')");
            $this->app->executeStatement("INSERT INTO {$this->table} (id, name) VALUES ('00000000-0000-7000-8000-000000000004', 'first')");
            $this->app->executeStatement("INSERT INTO {$this->table} (id, name) VALUES ('00000000-0000-7000-8000-000000000004', 'duplicate')");
            self::fail('Duplicate primary key must fail.');
        } catch (Exception $exception) {
            self::assertSame('23505', $exception->getSQLState());
        } finally {
            $this->app->rollBack();
        }
        self::assertSame(0, (int) $this->owner->fetchOne('SELECT count(*) FROM '.$this->table));
        self::assertSame(0, $this->auditCount());
    }

    public function testMigrationMetadataAndAuditAreProtectedFromApplicationWrites(): void
    {
        self::assertFalse((bool) $this->owner->fetchOne("SELECT has_schema_privilege('mikoucto_app', 'migration', 'USAGE')"));
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $permission) {
            self::assertFalse((bool) $this->owner->fetchOne("SELECT has_table_privilege('mikoucto_app', 'core.audit_log', ?)", [$permission]));
        }
        $this->app->beginTransaction();
        try {
            $this->app->executeStatement("INSERT INTO core.audit_log (client, table_name, row_id, operation) VALUES ('x', 'x', 'x', 'I')");
            self::fail('Direct audit write must fail.');
        } catch (Exception $exception) {
            self::assertSame('42501', $exception->getSQLState());
        } finally {
            $this->app->rollBack();
        }
    }

    public function testRepeatedMigrationDoesNotChangeSchemaDataOrVersionRecords(): void
    {
        $before = $this->snapshot();
        $factory = MigrationFactory::create($this->owner);
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($factory->getVersionAliasResolver()->resolveVersionAlias('latest'));
        self::assertSame(0, count($plan));
        $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(true));
        self::assertSame($before, $this->snapshot());
    }

    public function testExistingBaselineIsAdoptedWithoutRecreatingAudit(): void
    {
        $metadataTable = 'migration._adopt_'.bin2hex(random_bytes(6));
        $factory = MigrationFactory::create($this->owner);
        $storage = new TableMetadataStorageConfiguration();
        $storage->setTableName($metadataTable);
        $factory->getConfiguration()->setMetadataStorageConfiguration($storage);
        $factory->getMetadataStorage()->ensureInitialized();
        $before = $this->snapshot();
        try {
            $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(new Version('App\\Migrations\\Version20261102000000'));
            self::assertSame(1, count($plan));
            $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(true));
            self::assertSame(1, (int) $this->owner->fetchOne('SELECT count(*) FROM '.$metadataTable));
            self::assertSame($before, $this->snapshot());
        } finally {
            $this->owner->executeStatement('DROP TABLE '.$metadataTable);
        }
    }

    public function testIncompatibleExistingBaselineIsRejected(): void
    {
        $this->owner->beginTransaction();
        try {
            $this->owner->executeStatement('ALTER TABLE core.audit_log RENAME COLUMN row_id TO unexpected_column');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Incompatible baseline columns');
            BaselineSchema::assertCompatible($this->owner);
        } finally {
            $this->owner->rollBack();
        }
    }

    public function testFailingMigrationRollsBackDdlAndItsVersionRecord(): void
    {
        $factory = MigrationFactory::create($this->owner);
        $factory->getMigrationRepository()->registerMigration(FailingMigration::class);
        $version = new Version(FailingMigration::class);
        $plan = $factory->getMigrationPlanCalculator()->getPlanForVersions([$version], Direction::UP);
        try {
            $factory->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(true));
            self::fail('The deliberately failing migration must fail.');
        } catch (Exception $exception) {
            self::assertSame('22012', $exception->getSQLState());
        }
        self::assertNull($this->owner->fetchOne("SELECT to_regclass('core._stage3_failed')"));
        self::assertSame(0, (int) $this->owner->fetchOne('SELECT count(*) FROM migration.versions WHERE version=?', [FailingMigration::class]));
    }

    private function auditCount(): int
    {
        return (int) $this->owner->fetchOne('SELECT count(*) FROM core.audit_log WHERE table_name=?', [$this->table]);
    }

    private function snapshot(): array
    {
        return [
            $this->owner->fetchAllAssociative('SELECT * FROM migration.versions ORDER BY version'),
            $this->owner->fetchAllAssociative("SELECT p.oid, pg_get_functiondef(p.oid) AS definition FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='core' ORDER BY p.oid"),
            $this->owner->fetchFirstColumn('SELECT to_jsonb(t)::text FROM core.audit_log t ORDER BY id, occurred_at'),
            $this->owner->fetchFirstColumn('SELECT to_jsonb(t)::text FROM core.audit_seal_state t ORDER BY id'),
            $this->owner->fetchAllAssociative("SELECT c.oid, c.relname, c.relkind, a.attname, format_type(a.atttypid,a.atttypmod) AS type, a.attnotnull FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attribute a ON a.attrelid=c.oid AND a.attnum > 0 AND NOT a.attisdropped WHERE n.nspname='core' ORDER BY c.oid,a.attnum"),
        ];
    }
}
