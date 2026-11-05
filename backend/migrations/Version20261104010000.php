<?php
namespace App\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261104010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Atomic posted reversal documents, immutable audited relations and idempotent requests.'; }
    public function up(Schema $schema): void { $this->addSql(file_get_contents(__DIR__.'/sql/20261104010000_reversals.sql')); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Reversals are posted accounting records.'); }
}
