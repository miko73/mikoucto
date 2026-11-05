<?php
namespace App\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261103020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Atomic draft documents and exact MD/DAL lines with guarded database writes.'; }
    public function up(Schema $schema): void { $this->addSql(file_get_contents(__DIR__.'/sql/20261103020000_drafts.sql')); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Drafts and their audit require an explicit recovery plan.'); }
}
