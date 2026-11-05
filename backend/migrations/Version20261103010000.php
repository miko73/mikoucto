<?php

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Unit-scoped accounting accounts and periods with audit, RLS and idempotence.'; }
    public function up(Schema $schema): void { $this->addSql(file_get_contents(__DIR__.'/sql/20261103010000_accounting.sql')); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Accounting data and audit require an explicit recovery plan.'); }
}
