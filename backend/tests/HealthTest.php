<?php

namespace App\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testApplicationHealthDoesNotQueryDatabase(): void
    {
        $client = self::createClient();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        self::getContainer()->set(Connection::class, $connection);
        $client->request('GET', '/api/v1/health');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok', 'service' => 'mikoucto-api'], json_decode($client->getResponse()->getContent(), true));
    }

    public function testDatabaseReadinessQueriesDatabase(): void
    {
        $client = self::createClient();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->with('SELECT 1')->willReturn(1);
        self::getContainer()->set(Connection::class, $connection);
        $client->request('GET', '/api/v1/health/database');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok', 'database' => 'ok'], json_decode($client->getResponse()->getContent(), true));
    }

    public function testDatabaseFailureDoesNotExposeCredentials(): void
    {
        $client = self::createClient();
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('password=secret; host=private-server'));
        self::getContainer()->set(Connection::class, $connection);
        $client->request('GET', '/api/v1/health/database');

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertStringNotContainsString('secret', $client->getResponse()->getContent());
        self::assertStringNotContainsString('private-server', $client->getResponse()->getContent());
    }

    public function testUnreachableDatabaseReturns503(): void
    {
        $client = self::createClient();
        // Skutecny ovladac proti neobsazenemu portu, zadna vyvojova DB.
        $client->request('GET', '/api/v1/health/database');
        self::assertResponseStatusCodeSame(503);
    }
}
