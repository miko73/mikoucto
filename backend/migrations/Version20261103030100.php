<?php
namespace App\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261103030100 extends AbstractMigration
{
    public function getDescription(): string { return 'Require new periods to start open, including direct SQL inserts.'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $migration$ BEGIN
CREATE FUNCTION ucto.guard_period_initial_state() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 IF NEW.status<>'open' THEN RAISE EXCEPTION 'A new period must be open.' USING ERRCODE='23514',CONSTRAINT='posting_period_initial'; END IF;
 RETURN NEW;
END $$;
REVOKE ALL ON FUNCTION ucto.guard_period_initial_state() FROM PUBLIC;
CREATE TRIGGER period_initial_guard BEFORE INSERT ON ucto.periods FOR EACH ROW EXECUTE FUNCTION ucto.guard_period_initial_state();
END $migration$
SQL);
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Initial period state protection must remain enabled.'); }
}
