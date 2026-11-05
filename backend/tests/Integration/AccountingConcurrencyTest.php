<?php
namespace App\Tests\Integration;
use App\Tests\Support\AccountingFixture;
use App\Tests\Support\AccountingWorker;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AccountingConcurrencyTest extends TestCase
{
    private AccountingFixture $fixture;
    private array $workers=[];
    protected function setUp(): void { $this->fixture=new AccountingFixture(); }
    protected function tearDown(): void
    {
        if ($this->fixture->app->isTransactionActive()) { $this->fixture->app->rollBack(); }
        if ($this->fixture->owner->isTransactionActive()) { $this->fixture->owner->rollBack(); }
        foreach ($this->workers as $worker) { $worker->close(); }
        $this->fixture->close();
    }
    private function start(array $input,bool $start=true): AccountingWorker
    {
        $worker=new AccountingWorker($input+['unit'=>$this->fixture->units['a'],'user'=>$this->fixture->users['manager']]);
        $this->workers[]=$worker; if ($start) { $worker->go(); } return $worker;
    }
    public function testConcurrentOverlappingInserts(): void
    {
        $c=$this->fixture->app; $c->beginTransaction(); $this->fixture->context($c);
        $c->executeStatement('INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on) VALUES (?,?,?,?,?)',[Uuid::v7()->toRfc4122(),$this->fixture->units['a'],'First','2026-01-01','2026-12-31']);
        $worker=$this->start(['action'=>'sql','sql'=>'INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on) VALUES (?,?,?,?,?)','params'=>[Uuid::v7()->toRfc4122(),$this->fixture->units['a'],'Second','2026-12-31','2027-12-31']]);
        AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$worker); $c->commit();
        self::assertSame('23P01',$worker->result()['state']);
        self::assertSame(1,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.periods WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
    public function testConcurrentOverlappingUpdates(): void
    {
        $a=Uuid::v7()->toRfc4122(); $b=Uuid::v7()->toRfc4122(); $c=$this->fixture->app;
        $c->transactional(function(Connection $c) use($a,$b): void {
            $this->fixture->context($c);
            foreach ([[$a,'2026-01-01','2026-06-30'],[$b,'2026-07-01','2026-12-31']] as [$id,$start,$end]) { $c->executeStatement('INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on) VALUES (?,?,?,?,?)',[$id,$this->fixture->units['a'],$id,$start,$end]); }
        });
        $c->beginTransaction(); $this->fixture->context($c);
        $c->executeStatement("UPDATE ucto.periods SET ends_on='2026-05-31',version=version+1 WHERE id=?",[$a]);
        // The second update targets the first transaction's new range and must wait.
        $worker=$this->start(['action'=>'sql','sql'=>"UPDATE ucto.periods SET starts_on='2026-05-31',version=version+1 WHERE id=?",'params'=>[$b]]);
        AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$worker); $c->commit();
        self::assertSame('23P01',$worker->result()['state']);
        self::assertSame('2026-07-01',$this->fixture->owner->fetchOne('SELECT starts_on FROM ucto.periods WHERE id=?',[$b]));
    }
    public function testConcurrentReparentingCannotCreateCycle(): void
    {
        $a=Uuid::v7()->toRfc4122(); $b=Uuid::v7()->toRfc4122(); $c=$this->fixture->app;
        $c->transactional(function(Connection $c) use($a,$b): void {
            $this->fixture->context($c);
            foreach ([$a,$b] as $i=>$id) { $c->executeStatement('INSERT INTO ucto.accounts(id,accounting_unit_id,code,name) VALUES (?,?,?,?)',[$id,$this->fixture->units['a'],(string)$i,'Account']); }
        });
        $c->beginTransaction(); $this->fixture->context($c);
        $c->executeStatement('UPDATE ucto.accounts SET parent_id=?,version=version+1 WHERE id=?',[$b,$a]);
        $worker=$this->start(['action'=>'sql','sql'=>'UPDATE ucto.accounts SET parent_id=?,version=version+1 WHERE id=?','params'=>[$a,$b]]);
        AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$worker); $c->commit();
        self::assertSame('23514',$worker->result()['state']);
        self::assertNull($this->fixture->owner->fetchOne('SELECT parent_id FROM ucto.accounts WHERE id=?',[$b]));
    }
    public function testConcurrentHttpIdempotencyCreatesOneObjectPerKind(): void
    {
        foreach (['accounts'=>['code'=>'001','name'=>'Concurrent'],'periods'=>['name'=>'Concurrent','startsOn'=>'2026-01-01','endsOn'=>'2026-12-31']] as $kind=>$payload) {
            $key=Uuid::v7()->toRfc4122(); $c=$this->fixture->owner; $c->beginTransaction();
            $lock=$kind==='accounts'?'ucto.accounts:'.$this->fixture->units['a']:'ucto.periods.create:'.$this->fixture->units['a'].':'.$this->fixture->users['manager'].':'.$key;
            $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$lock]);
            $input=['action'=>'http','kind'=>$kind,'payload'=>$payload,'key'=>$key,'object'=>$this->fixture->objects['manager']];
            $first=$this->start($input,false); $second=$this->start($input,false);
            $first->go(); $second->go();
            AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$first,$second); $c->commit();
            $one=$first->result(); $two=$second->result();
            self::assertSame(201,$one['status']); self::assertSame(201,$two['status']); self::assertSame($one['body'],$two['body']); self::assertNotSame($one['replayed'],$two['replayed']);
            self::assertSame(1,(int)$c->fetchOne("SELECT count(*) FROM ucto.$kind WHERE accounting_unit_id=?",[$this->fixture->units['a']]));
            self::assertSame(1,(int)$c->fetchOne('SELECT count(*) FROM core.audit_log WHERE table_name=? AND row_id=?',['ucto.'.$kind,$one['body']['id']]));
        }
    }
}
