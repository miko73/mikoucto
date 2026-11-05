<?php

namespace App\Tests\Integration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// Jen pro integracni test rollbacku; produkcni adresar migraci ji neobsahuje.
final class FailingMigration extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE core._stage3_failed (id integer PRIMARY KEY)');
        $this->addSql('INSERT INTO core._stage3_failed VALUES (1)');
        $this->addSql('SELECT 1 / 0');
    }
}
