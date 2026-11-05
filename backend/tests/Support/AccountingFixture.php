<?php
namespace App\Tests\Support;
use Doctrine\DBAL\Connection;
final class AccountingFixture
{
    private PartnerFixture $identity;
    public Connection $owner;
    public Connection $app;
    public array $users;
    public array $objects;
    public array $units;
    public function __construct()
    {
        $this->identity=new PartnerFixture();
        foreach (['owner','app','users','objects','units'] as $property) { $this->$property=$this->identity->$property; }
    }
    public function context(Connection $connection,string $role='manager',string $unit='a'): void
    {
        $connection->fetchOne('SELECT core.set_context(?::uuid,?,?)',[$this->users[$role],'accounting-test','test']);
        $connection->fetchOne("SELECT set_config('app.accounting_unit_id',?,true)",[$this->units[$unit]]);
        $connection->executeStatement("SET LOCAL statement_timeout='15s'");
        $connection->executeStatement("SET LOCAL lock_timeout='10s'");
    }
    public function close(): void
    {
        if ($this->app->isTransactionActive()) { $this->app->rollBack(); }
        $this->owner->transactional(function(Connection $c) {
            $this->context($c);
            foreach ($this->units as $unit) {
                foreach (['account_create_requests','period_create_requests'] as $table) { $c->executeStatement("DELETE FROM ucto.$table WHERE accounting_unit_id=?",[$unit]); }
                $c->executeStatement('UPDATE ucto.accounts SET parent_id=null,version=version+1 WHERE accounting_unit_id=? AND parent_id IS NOT NULL',[$unit]);
                foreach (['accounts','periods'] as $table) { $c->executeStatement("DELETE FROM ucto.$table WHERE accounting_unit_id=?",[$unit]); }
            }
        });
        $this->identity->close();
    }
}
