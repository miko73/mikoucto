<?php
namespace App\Tests\Integration;
use App\Tests\Support\AccountingWorker;
use App\Tests\Support\DraftFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DraftConcurrencyTest extends TestCase
{
    private DraftFixture $fixture;
    private array $workers=[];
    protected function setUp(): void { $this->fixture=new DraftFixture(); }
    protected function tearDown(): void
    {
        foreach ([$this->fixture->app,$this->fixture->owner] as $c) { if ($c->isTransactionActive()) { $c->rollBack(); } }
        foreach ($this->workers as $worker) { $worker->close(); }
        $this->fixture->close();
    }
    private function worker(array $input,bool $start=true): AccountingWorker
    {
        $worker=new AccountingWorker($input+['unit'=>$this->fixture->units['a'],'user'=>$this->fixture->users['manager'],'object'=>$this->fixture->objects['manager'],'kind'=>'documents']);
        $this->workers[]=$worker; if ($start) { $worker->go(); } return $worker;
    }
    private function unitBarrier(): Connection
    {
        $c=$this->fixture->owner; $c->beginTransaction();
        $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.accounts:'.$this->fixture->units['a']]); return $c;
    }
    public function testSimultaneousHttpCreateReplaysSingleAggregate(): void
    {
        $c=$this->unitBarrier(); $input=['action'=>'http','key'=>Uuid::v7()->toRfc4122(),'payload'=>$this->fixture->payload()];
        $first=$this->worker($input,false); $second=$this->worker($input,false);
        $first->go(); $second->go();
        AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$first,$second); $c->commit();
        $one=$first->result(); $two=$second->result();
        self::assertSame(201,$one['status']); self::assertSame(201,$two['status']); self::assertSame($one['body'],$two['body']); self::assertNotSame($one['replayed'],$two['replayed']);
        self::assertSame(1,(int)$c->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(2,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_lines WHERE document_id=?',[$one['body']['id']]));
        self::assertSame(1,(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.documents' AND row_id=?",[$one['body']['id']]));
    }
    public function testSimultaneousHttpPatchHonorsAggregateEtag(): void
    {
        $id=$this->fixture->save($this->fixture->payload()); $c=$this->unitBarrier();
        $input=['action'=>'http','method'=>'PATCH','suffix'=>'/'.$id,'etag'=>'"'.$id.':1"'];
        $first=$this->worker($input+['payload'=>['text'=>'First']],false); $second=$this->worker($input+['payload'=>['text'=>'Second']],false);
        $first->go(); $second->go();
        AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$first,$second); $c->commit();
        $results=[$first->result(),$second->result()]; $codes=array_column($results,'status'); sort($codes);
        self::assertSame([200,412],$codes);
        self::assertSame(2,(int)$c->fetchOne('SELECT version FROM ucto.documents WHERE id=?',[$id]));
        self::assertSame(2,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_lines WHERE document_id=?',[$id]));
    }
    public function testUsedPeriodAndAccountChecksSeeConcurrentDocument(): void
    {
        $c=$this->fixture->app; $c->beginTransaction(); $this->fixture->context($c);
        $payload=$this->fixture->payload(); $id=Uuid::v7()->toRfc4122(); $lineIds='{'.Uuid::v7()->toRfc4122().','.Uuid::v7()->toRfc4122().'}';
        $c->fetchOne('SELECT ucto.save_draft(?::uuid,?::uuid,null,?::jsonb,?::uuid[])',[$this->fixture->units['a'],$id,json_encode($payload),$lineIds]);
        $period=$this->worker(['action'=>'sql','sql'=>"UPDATE ucto.periods SET ends_on='2026-12-30',version=version+1 WHERE id=?",'params'=>[$this->fixture->periods['a']]],false);
        $account=$this->worker(['action'=>'sql','sql'=>"UPDATE ucto.accounts SET code='999',version=version+1 WHERE id=?",'params'=>[$this->fixture->accounts['a']['md']]],false);
        $period->go(); $account->go();
        AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$period,$account); $c->commit();
        self::assertSame('23514',$period->result()['state']); self::assertSame('23514',$account->result()['state']);
        self::assertSame('2026-12-31',$this->fixture->owner->fetchOne('SELECT ends_on FROM ucto.periods WHERE id=?',[$this->fixture->periods['a']]));
        self::assertSame('0',$this->fixture->owner->fetchOne('SELECT code FROM ucto.accounts WHERE id=?',[$this->fixture->accounts['a']['md']]));
    }
    public function testConcurrentDeactivationIsRecheckedAfterWaiting(): void
    {
        $c=$this->fixture->app; $c->beginTransaction(); $this->fixture->context($c);
        $c->executeStatement('UPDATE ucto.accounts SET active=false,version=version+1 WHERE id=?',[$this->fixture->accounts['a']['md']]);
        $payload=$this->fixture->payload();
        $worker=$this->worker(['action'=>'sql','sql'=>'SELECT ucto.save_draft(?::uuid,?::uuid,null,?::jsonb,?::uuid[])',
            'params'=>[$this->fixture->units['a'],Uuid::v7()->toRfc4122(),json_encode($payload),'{'.Uuid::v7()->toRfc4122().','.Uuid::v7()->toRfc4122().'}']]);
        AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$worker); $c->commit(); self::assertSame('23514',$worker->result()['state']);
        self::assertSame(0,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
}
