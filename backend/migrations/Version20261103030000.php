<?php
namespace App\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261103030000 extends AbstractMigration
{
    public function getDescription(): string { return 'Post balanced documents, allocate transactional numbers and close accounting periods.'; }
    public function up(Schema $schema): void { $this->addSql(file_get_contents(__DIR__.'/sql/20261103030000_posting.sql')); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Posted documents and closed periods require an explicit recovery plan.'); }
}
