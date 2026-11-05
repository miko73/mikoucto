<?php

namespace App\Modules\Core\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final class ConnectionFactory
{
    public static function create(string $url): Connection
    {
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
        return DriverManager::getConnection($parameters);
    }
}
