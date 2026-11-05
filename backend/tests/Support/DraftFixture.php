<?php
namespace App\Tests\Support;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
final class DraftFixture
{
    private AccountingFixture $base;
    public Connection $owner;
    public Connection $app;
    public array $users;
    public array $objects;
    public array $units;
    public array $accounts=[];
    public array $periods=[];
    public array $partners=[];
    public function __construct()
    {
        $this->base=new AccountingFixture();
        foreach (['owner','app','users','objects','units'] as $name) { $this->$name=$this->base->$name; }
        $this->owner->transactional(function(Connection $c): void {
            $this->context($c);
            foreach ($this->units as $key=>$unit) {
                $this->periods[$key]=Uuid::v7()->toRfc4122(); $this->partners[$key]=Uuid::v7()->toRfc4122();
                $c->executeStatement("INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on) VALUES (?,?,'Test 2026','2026-01-01','2026-12-31')",[$this->periods[$key],$unit]);
                $c->executeStatement("INSERT INTO core.partners(id,accounting_unit_id,name) VALUES (?,?,'Draft partner')",[$this->partners[$key],$unit]);
                foreach (['md','dal','inactive','nonpostable'] as $i=>$name) {
                    $id=Uuid::v7()->toRfc4122(); $this->accounts[$key][$name]=$id;
                    $c->executeStatement('INSERT INTO ucto.accounts(id,accounting_unit_id,code,name,active,postable) VALUES (?,?,?,?,?,?)',[$id,$unit,(string)$i,$name,$name==='inactive'?'false':'true',$name==='nonpostable'?'false':'true']);
                }
            }
        });
    }
    public function context(Connection $c,string $role='manager',string $unit='a'): void { $this->base->context($c,$role,$unit); }
    public function payload(string $unit='a'): array
    {
        return ['periodId'=>$this->periods[$unit],'postingDate'=>'2026-11-03','text'=>'Draft test','partnerId'=>$this->partners[$unit],
            'lines'=>[['accountId'=>$this->accounts[$unit]['md'],'text'=>'MD','md'=>'100.00','dal'=>'0.00'],['accountId'=>$this->accounts[$unit]['dal'],'text'=>'DAL','md'=>'0.00','dal'=>'99.00']]];
    }
    public function save(array $payload,?string $id=null,?int $version=null,string $role='manager',string $unit='a'): string
    {
        $id??=Uuid::v7()->toRfc4122();
        return $this->app->transactional(function(Connection $c) use($payload,$id,$version,$role,$unit): string {
            $this->context($c,$role,$unit);
            $c->fetchOne('SELECT ucto.save_draft(?::uuid,?::uuid,?::integer,?::jsonb,?::uuid[])',[$this->units[$unit],$id,$version,json_encode($payload,JSON_THROW_ON_ERROR),'{'.implode(',',array_map(static fn()=>Uuid::v7()->toRfc4122(),$payload['lines'])).'}']);
            return $id;
        });
    }
    public function close(): void
    {
        if ($this->app->isTransactionActive()) { $this->app->rollBack(); }
        if ($this->owner->isTransactionActive()) { $this->owner->rollBack(); }
        $this->owner->transactional(function(Connection $c): void {
            $this->context($c);
            foreach ($this->units as $unit) {
                foreach (['document_correction_requests','document_corrections','document_reverse_requests','document_reversals','document_post_requests','period_close_requests','document_create_requests','document_lines','documents','document_sequences'] as $table) { $c->executeStatement("DELETE FROM ucto.$table WHERE accounting_unit_id=?",[$unit]); }
            }
        });
        $this->base->close();
    }
}
