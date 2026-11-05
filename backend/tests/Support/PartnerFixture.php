<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final class PartnerFixture
{
    public Connection $owner;
    public Connection $app;
    public array $users=[];
    public array $objects=[];
    public array $units=[];

    public function __construct()
    {
        $this->owner=TestDatabase::connection('owner'); $this->app=TestDatabase::connection('app');
        foreach (['manager','accountant','reader','auditor','foreign'] as $name) { $this->users[$name]=Uuid::v7()->toRfc4122(); $this->objects[$name]=Uuid::v7()->toRfc4122(); }
        foreach (['a','b'] as $name) { $this->units[$name]=Uuid::v7()->toRfc4122(); }
        $this->owner->transactional(function (Connection $connection): void {
            $connection->fetchOne('SELECT core.set_context(?::uuid,?,?)',[$this->users['manager'],'partners-fixture','test']);
            $params=[];
            foreach ($this->users as $name=>$id) { array_push($params,$id,TestTokens::TENANT,$this->objects[$name],$name); }
            $connection->executeStatement('INSERT INTO core.users(id,entra_tenant_id,entra_object_id,display_name) VALUES '.implode(',',array_fill(0,5,'(?,?,?,?)')),$params);
            $params=[];
            foreach ($this->units as $name=>$id) { array_push($params,$id,'P'.strtoupper(bin2hex(random_bytes(8))),'Partners '.$name); }
            $connection->executeStatement('INSERT INTO core.accounting_units(id,code,name) VALUES (?,?,?),(?,?,?)',$params);
            $params=[];
            foreach (['manager'=>'administrator','accountant'=>'accountant','reader'=>'reader','auditor'=>'auditor','foreign'=>'administrator'] as $name=>$role) {
                array_push($params,Uuid::v7()->toRfc4122(),$this->users[$name],$this->units[$name==='foreign'?'b':'a'],$role);
            }
            $connection->executeStatement('INSERT INTO core.user_roles(id,user_id,accounting_unit_id,role_code) VALUES '.implode(',',array_fill(0,5,'(?,?,?,?)')),$params);
        });
    }

    public function close(): void
    {
        if ($this->app->isTransactionActive()) { $this->app->rollBack(); }
        if ($this->owner->isTransactionActive()) { $this->owner->rollBack(); }
        $this->owner->transactional(function (Connection $connection): void {
            foreach ($this->units as $unit) {
                $connection->executeStatement('DELETE FROM core.idempotency_keys WHERE accounting_unit_id=?',[$unit]);
                $connection->executeStatement('DELETE FROM core.partners WHERE accounting_unit_id=?',[$unit]);
                $connection->executeStatement('DELETE FROM core.user_roles WHERE accounting_unit_id=?',[$unit]);
                $connection->executeStatement('DELETE FROM core.accounting_units WHERE id=?',[$unit]);
            }
            foreach ($this->users as $user) { $connection->executeStatement('DELETE FROM core.users WHERE id=?',[$user]); }
        });
        $this->app->close(); $this->owner->close();
    }
}
