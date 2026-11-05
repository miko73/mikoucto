<?php
namespace App\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261105010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Atomic editable correction drafts linked to their original and reversal.'; }
    public function up(Schema $schema): void { $this->addSql(file_get_contents(__DIR__.'/sql/20261105010000_corrections.sql')); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Correction relations belong to accounting history.'); }
}
