<?php
namespace App\Migrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261103020100 extends AbstractMigration
{
    public function getDescription(): string { return 'Require fresh snapshots when changing period ranges used by draft documents.'; }
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION ucto.guard_used_period() RETURNS trigger LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 IF NEW.starts_on IS DISTINCT FROM OLD.starts_on OR NEW.ends_on IS DISTINCT FROM OLD.ends_on THEN
  IF current_setting('transaction_isolation')<>'read committed' THEN
   RAISE EXCEPTION 'Period range writes require read committed.' USING ERRCODE='23514',CONSTRAINT='periods_isolation';
  END IF;
  IF EXISTS(SELECT 1 FROM ucto.documents WHERE accounting_unit_id=OLD.accounting_unit_id AND period_id=OLD.id) THEN
   RAISE EXCEPTION 'A used period range is immutable.' USING ERRCODE='23514',CONSTRAINT='periods_used_range';
  END IF;
 END IF;
 RETURN NEW;
END $$
SQL);
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Snapshot protection must remain enabled.'); }
}
