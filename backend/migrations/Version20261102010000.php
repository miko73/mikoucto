<?php

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Entra identities, unit-scoped RBAC and row-level security.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(file_get_contents(__DIR__.'/sql/20261102010000_identity.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Identity and access history must not be removed automatically.');
    }
}
