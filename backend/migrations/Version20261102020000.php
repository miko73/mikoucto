<?php

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Unit-scoped partners, audited changes and idempotent creation.'; }
    public function up(Schema $schema): void { $this->addSql(file_get_contents(__DIR__.'/sql/20261102020000_partners.sql')); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Partner data and history require an explicit recovery plan.'); }
}
