<?php

namespace App\Migrations;

use App\Modules\Core\Infrastructure\Migrations\BaselineSchema;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schemas, privileges and audit; adopt a verified existing installation.';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->fetchOne("SELECT to_regclass('core.audit_log')") !== null) {
            BaselineSchema::assertCompatible($this->connection);
            $this->write('Existing database baseline verified; recording version without recreating audit.');
            return;
        }
        $this->abortIf((bool) $this->connection->fetchOne("SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname IN ('core', 'ext'))"),
            'Partial baseline detected. Repair the existing installation before recording its version.');
        $this->addSql(file_get_contents(__DIR__.'/sql/20261102000000_baseline.sql'));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The audit baseline must not be dropped automatically.');
    }

    public function postUp(Schema $schema): void
    {
        BaselineSchema::assertCompatible($this->connection);
    }
}
