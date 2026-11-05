<?php

namespace App\Tests\Support;

use App\Modules\Core\Infrastructure\ConnectionFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\DsnParser;

final class TestDatabase
{
    public static function connection(string $role): Connection
    {
        if (!in_array($role, ['owner', 'app', 'read'], true)) { throw new \RuntimeException('Unexpected test role.'); }
        $url = getenv('MIKOUCTO_TEST_'.strtoupper($role).'_URL') ?: '';
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        if (($parameters['dbname'] ?? '') !== 'mikoucto_test' || ($parameters['user'] ?? '') !== 'mikoucto_'.$role) {
            throw new \RuntimeException('Only dedicated mikoucto_test URLs are accepted.');
        }
        $connection = ConnectionFactory::create($url);
        if ($connection->fetchOne('SELECT current_database()') !== 'mikoucto_test' || $connection->fetchOne('SELECT session_user') !== 'mikoucto_'.$role) {
            throw new \RuntimeException('Connected to an unexpected test database or role.');
        }
        return $connection;
    }
}
