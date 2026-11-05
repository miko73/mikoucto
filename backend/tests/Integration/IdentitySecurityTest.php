<?php

namespace App\Tests\Integration;

use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Modules\Core\Infrastructure\Identity\IdentityProvisioner;
use App\Tests\Support\TestDatabase;
use App\Tests\Support\TestTokens;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class IdentitySecurityTest extends WebTestCase
{
    private Connection $owner;
    private Connection $app;
    private Connection $reader;
    private array $users = [];
    private array $objects = [];
    private array $units = [];
    private ?string $table = null;

    protected function setUp(): void
    {
        $this->owner = TestDatabase::connection('owner');
        $this->app = TestDatabase::connection('app');
        $this->reader = TestDatabase::connection('read');
        foreach (['alice', 'bob'] as $name) { $this->users[$name] = Uuid::v7()->toRfc4122(); $this->objects[$name] = Uuid::v7()->toRfc4122(); }
        foreach (['a', 'b'] as $name) { $this->units[$name] = Uuid::v7()->toRfc4122(); }
        $this->owner->transactional(function (Connection $connection): void {
            $connection->fetchOne('SELECT core.set_context(?::uuid, ?, ?)', [$this->users['alice'], 'stage4-fixture', 'test']);
            foreach ($this->users as $name => $id) {
                $connection->executeStatement('INSERT INTO core.users(id, entra_tenant_id, entra_object_id, display_name) VALUES (?, ?, ?, ?)', [$id, TestTokens::TENANT, $this->objects[$name], $name]);
            }
            foreach ($this->units as $name => $id) {
                $connection->executeStatement('INSERT INTO core.accounting_units(id, code, name) VALUES (?, ?, ?)', [$id, 'T'.strtoupper(bin2hex(random_bytes(8))), 'Unit '.$name]);
            }
            foreach ([['alice', 'a', 'administrator'], ['bob', 'b', 'administrator']] as [$user, $unit, $role]) {
                $connection->executeStatement('INSERT INTO core.user_roles(id, user_id, accounting_unit_id, role_code) VALUES (?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $this->users[$user], $this->units[$unit], $role]);
            }
        });
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        foreach (['app', 'reader', 'owner'] as $property) {
            if (isset($this->$property) && $this->$property->isTransactionActive()) { $this->$property->rollBack(); }
        }
        if (isset($this->owner)) {
            if ($this->table !== null) { $this->owner->executeStatement('DROP TABLE IF EXISTS '.$this->table); }
            $this->owner->transactional(function (Connection $connection): void {
                foreach ($this->units as $id) {
                    $connection->executeStatement('DELETE FROM core.user_roles WHERE accounting_unit_id=?', [$id]);
                    $connection->executeStatement('DELETE FROM core.accounting_units WHERE id=?', [$id]);
                }
                foreach ($this->users as $id) { $connection->executeStatement('DELETE FROM core.users WHERE id=?', [$id]); }
            });
        }
        foreach (['app', 'reader', 'owner'] as $property) { if (isset($this->$property)) { $this->$property->close(); } }
        parent::tearDown();
    }

    public function testSignedHttpRequestsSeeOnlyOwnUnitsAndCannotSelectAnotherUnit(): void
    {
        $client = $this->client();
        $this->request($client, 'alice', 'GET', '/api/v1/accounting-units');
        self::assertResponseIsSuccessful();
        self::assertSame([$this->units['a']], array_column($this->body($client)['items'], 'id'));
        $this->request($client, 'alice', 'GET', '/api/v1/accounting-units/'.$this->units['b'].'/users');
        self::assertResponseStatusCodeSame(403);
        $this->request($client, 'bob', 'GET', '/api/v1/accounting-units');
        self::assertResponseIsSuccessful();
        self::assertSame([$this->units['b']], array_column($this->body($client)['items'], 'id'));
        self::assertTrue((bool) $this->app->fetchOne("SELECT nullif(current_setting('app.user_id', true), '') IS NULL"));
    }

    public function testRoleChangesAreScopedAuditedAndCannotRemoveLastAdministrator(): void
    {
        $client = $this->client();
        $path = '/api/v1/accounting-units/'.$this->units['a'].'/users/'.$this->users['bob'].'/roles';
        $this->request($client, 'alice', 'POST', $path, ['role' => 'auditor']);
        self::assertResponseIsSuccessful();
        $membership = $this->body($client)['id'];
        $requestId = $client->getResponse()->headers->get('X-Request-Id');
        $audit = $this->owner->fetchAssociative("SELECT user_id, request_id FROM core.audit_log WHERE table_name='core.user_roles' AND row_id=? ORDER BY id DESC LIMIT 1", [$membership]);
        self::assertSame($this->users['alice'], $audit['user_id']);
        self::assertSame($requestId, $audit['request_id']);
        $this->request($client, 'alice', 'POST', $path, ['role' => 'auditor']);
        self::assertResponseIsSuccessful();
        self::assertSame($membership, $this->body($client)['id']);
        self::assertSame(1, (int) $this->owner->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='core.user_roles' AND row_id=?", [$membership]));
        $this->request($client, 'bob', 'GET', '/api/v1/accounting-units/'.$this->units['a'].'/audit');
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($this->body($client)['items']);
        $this->request($client, 'bob', 'POST', $path, ['role' => 'administrator']);
        self::assertResponseStatusCodeSame(403);
        $this->request($client, 'alice', 'DELETE', '/api/v1/accounting-units/'.$this->units['a'].'/users/'.$this->users['alice'].'/roles/administrator');
        self::assertResponseStatusCodeSame(409);
        $this->request($client, 'alice', 'DELETE', $path.'/auditor');
        self::assertResponseStatusCodeSame(204);
        $this->request($client, 'bob', 'GET', '/api/v1/accounting-units/'.$this->units['a'].'/audit');
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnregisteredAndInactiveIdentitiesCannotSignIn(): void
    {
        $client = $this->client();
        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokens::token(['oid' => Uuid::v7()->toRfc4122()])]);
        self::assertResponseStatusCodeSame(403);
        $this->owner->executeStatement('UPDATE core.users SET active=false, version=version+1 WHERE id=?', [$this->users['alice']]);
        $this->request($client, 'alice', 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerConnectionCannotServeProtectedApi(): void
    {
        $client = $this->client($this->owner);
        $this->request($client, 'alice', 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(503);
        self::assertStringNotContainsString('mikoucto_owner', $client->getResponse()->getContent());
    }

    public function testUnitUsersRolesAndPermissionsHaveStructuredResponses(): void
    {
        $client = $this->client();
        $base = '/api/v1/accounting-units/'.$this->units['a'];
        $this->request($client, 'alice', 'GET', $base.'/users');
        self::assertResponseIsSuccessful();
        self::assertSame([$this->users['alice']], array_column($this->body($client)['items'], 'id'));
        self::assertSame(['administrator'], $this->body($client)['items'][0]['roles']);
        $this->request($client, 'alice', 'GET', $base.'/roles');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $this->body($client)['items']);
        $this->request($client, 'alice', 'GET', $base.'/permissions');
        self::assertResponseIsSuccessful();
        self::assertContains('core.roles.manage', $this->body($client)['items']);
        $this->request($client, 'alice', 'POST', $base.'/users/'.$this->users['bob'].'/roles', ['role' => 'unknown']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testProvisioningExistingUserAndMembershipIsIdempotent(): void
    {
        $code = $this->owner->fetchOne('SELECT code FROM core.accounting_units WHERE id=?', [$this->units['a']]);
        $input = ['tenant' => TestTokens::TENANT, 'object' => $this->objects['alice'], 'display_name' => 'alice',
            'unit_code' => $code, 'unit_name' => 'Unit a', 'role' => 'administrator'];
        $before = (int) $this->owner->fetchOne('SELECT count(*) FROM core.audit_log');
        $first = IdentityProvisioner::provision($this->owner, $input);
        self::assertSame($first, IdentityProvisioner::provision($this->owner, $input));
        self::assertSame($this->users['alice'], $first['user_id']);
        self::assertSame($this->units['a'], $first['accounting_unit_id']);
        self::assertSame($before, (int) $this->owner->fetchOne('SELECT count(*) FROM core.audit_log'));
    }

    public function testNewIdentityProvisioningCreatesAuditedUuidV7Records(): void
    {
        $object = Uuid::v7()->toRfc4122();
        $input = ['tenant' => TestTokens::TENANT, 'object' => $object, 'display_name' => 'Nový uživatel',
            'unit_code' => 'NEW'.strtoupper(bin2hex(random_bytes(6))), 'unit_name' => 'Nová jednotka', 'role' => 'administrator'];
        $created = IdentityProvisioner::provision($this->owner, $input);
        $this->users['new'] = $created['user_id'];
        $this->units['new'] = $created['accounting_unit_id'];
        self::assertSame('7', $created['user_id'][14]);
        self::assertSame('7', $created['accounting_unit_id'][14]);
        $identity = $this->app->fetchAssociative('SELECT * FROM core.resolve_identity(?::uuid, ?::uuid)', [TestTokens::TENANT, $object]);
        self::assertSame($created['user_id'], $identity['id']);
        self::assertSame('Nový uživatel', $identity['display_name']);
        self::assertSame(1, (int) $this->owner->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='core.users' AND row_id=? AND operation='I'", [$created['user_id']]));
        $count = (int) $this->owner->fetchOne('SELECT count(*) FROM core.audit_log');
        self::assertSame($created, IdentityProvisioner::provision($this->owner, $input));
        self::assertSame($count, (int) $this->owner->fetchOne('SELECT count(*) FROM core.audit_log'));
    }

    public function testRlsRejectsForeignReadsAndWritesEvenWithoutApi(): void
    {
        $this->table = 'core._stage4_'.bin2hex(random_bytes(6));
        $this->owner->executeStatement('CREATE TABLE '.$this->table.' (id uuid PRIMARY KEY, accounting_unit_id uuid NOT NULL REFERENCES core.accounting_units(id), name text NOT NULL)');
        $this->owner->executeStatement('ALTER TABLE '.$this->table.' ENABLE ROW LEVEL SECURITY');
        $this->owner->executeStatement('CREATE POLICY isolated ON '.$this->table.' TO mikoucto_app, mikoucto_read USING (core.can_access_unit(accounting_unit_id)) WITH CHECK (core.can_access_unit(accounting_unit_id))');
        foreach ($this->units as $unit) { $this->owner->executeStatement('INSERT INTO '.$this->table.' VALUES (?, ?, ?)', [Uuid::v7()->toRfc4122(), $unit, 'scoped']); }
        self::assertSame(0, (int) $this->app->fetchOne('SELECT count(*) FROM '.$this->table));
        $this->withContext($this->app, 'alice', 'a', function (Connection $connection): void {
            self::assertSame([$this->units['a']], $connection->fetchFirstColumn('SELECT accounting_unit_id FROM '.$this->table));
            self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM '.$this->table.' WHERE accounting_unit_id=?', [$this->units['b']]));
        });
        try {
            $this->withContext($this->app, 'alice', 'a', function (Connection $connection): void {
                $connection->executeStatement('INSERT INTO '.$this->table.' VALUES (?, ?, ?)', [Uuid::v7()->toRfc4122(), $this->units['b'], 'forbidden']);
            });
            self::fail('Foreign insert must fail at the database layer.');
        } catch (Exception $exception) { self::assertSame('42501', $exception->getSQLState()); }
        $this->withContext($this->app, 'alice', 'b', function (Connection $connection): void {
            self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM '.$this->table));
        });
        $this->withContext($this->reader, 'bob', 'b', function (Connection $connection): void {
            self::assertSame([$this->units['b']], $connection->fetchFirstColumn('SELECT accounting_unit_id FROM '.$this->table));
        });
    }

    public function testApplicationCannotGrantItselfMembershipWithRawSql(): void
    {
        try {
            $this->withContext($this->app, 'alice', 'a', function (Connection $connection): void {
                $connection->executeStatement('INSERT INTO core.user_roles VALUES (?, ?, ?, ?, 1)', [Uuid::v7()->toRfc4122(), $this->users['alice'], $this->units['b'], 'administrator']);
            });
            self::fail('Direct membership insert must be denied.');
        } catch (Exception $exception) { self::assertSame('42501', $exception->getSQLState()); }
        try {
            $this->withContext($this->app, 'alice', 'a', function (Connection $connection): void {
                $connection->fetchOne('SELECT core.assign_role(?::uuid, ?::uuid, ?, ?::uuid)', [$this->users['alice'], $this->units['b'], 'administrator', Uuid::v7()->toRfc4122()]);
            });
            self::fail('Role function must independently reject a foreign unit.');
        } catch (Exception $exception) { self::assertSame('42501', $exception->getSQLState()); }
    }

    public function testAuditDoesNotExposeForeignUnitsOrUnscopedHistory(): void
    {
        $this->withContext($this->app, 'alice', 'a', function (Connection $connection): void {
            $units = $connection->fetchFirstColumn('SELECT DISTINCT core.visible_audit_unit(table_name,new_data,old_data)::text FROM core.audit_log');
            self::assertSame([$this->units['a']], $units);
        });
        self::assertSame(0, (int) $this->app->fetchOne('SELECT count(*) FROM core.audit_log'));
    }

    private function client(?Connection $connection = null): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(Connection::class, $connection ?? $this->app);
        self::getContainer()->set(AccessTokenValidator::class, TestTokens::validator());
        return $client;
    }

    private function request(KernelBrowser $client, string $user, string $method, string $path, ?array $body = null): void
    {
        $client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokens::token(['oid' => $this->objects[$user]]), 'CONTENT_TYPE' => 'application/json'], content: $body === null ? null : json_encode($body));
    }

    private function body(KernelBrowser $client): array { return json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR); }

    private function withContext(Connection $connection, string $user, string $unit, callable $operation): mixed
    {
        return $connection->transactional(function (Connection $connection) use ($user, $unit, $operation): mixed {
            if ($connection === $this->reader) {
                $connection->fetchOne("SELECT set_config('app.user_id', ?, true)", [$this->users[$user]]);
            } else {
                $connection->fetchOne('SELECT core.set_context(?::uuid, ?, ?)', [$this->users[$user], 'stage4-rls', 'test']);
            }
            $connection->fetchOne("SELECT set_config('app.accounting_unit_id', ?, true)", [$this->units[$unit]]);
            return $operation($connection);
        });
    }
}
