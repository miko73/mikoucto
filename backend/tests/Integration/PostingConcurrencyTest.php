<?php
namespace App\Tests\Integration;
use App\Tests\Support\AccountingWorker;
use App\Tests\Support\DraftFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PostingConcurrencyTest extends TestCase
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
    private function draft(): string { $p=$this->fixture->payload(); $p['lines'][1]['dal']='100.00'; return $this->fixture->save($p); }
    private function barrier(): Connection
    {
        $c=$this->fixture->owner; $c->beginTransaction();
        $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.accounts:'.$this->fixture->units['a']]); return $c;
    }
    private function worker(string $kind,string $id,string $action,?string $key=null,?array $payload=null): AccountingWorker
    {
        $input=['action'=>'http','kind'=>$kind,'unit'=>$this->fixture->units['a'],'object'=>$this->fixture->objects['manager'],
            'suffix'=>$id===''?'':'/'.$id.'/'.$action,'etag'=>'"'.$id.':1"','key'=>$key??Uuid::v7()->toRfc4122()];
        if ($payload===null) { $input['rawBody']='{}'; } else { $input['payload']=$payload; }
        $worker=new AccountingWorker($input); $this->workers[]=$worker; return $worker;
    }
    private function runPair(AccountingWorker $first,AccountingWorker $second,Connection $barrier): array
    {
        $first->go(); $second->go();
        AccountingWorker::awaitBlockedBy($this->fixture->owner,$barrier,$first,$second);
        $barrier->commit(); return [$first->result(),$second->result()];
    }
    public function testSamePostingCommandIsReplayedWithoutAnotherNumberOrAudit(): void
    {
        $id=$this->draft(); $key=Uuid::v7()->toRfc4122(); $c=$this->barrier();
        [$a,$b]=$this->runPair($this->worker('documents',$id,'post',$key),$this->worker('documents',$id,'post',$key),$c);
        self::assertSame(200,$a['status']); self::assertSame(200,$b['status']); self::assertSame($a['body'],$b['body']); self::assertNotSame($a['replayed'],$b['replayed']);
        self::assertSame(1,(int)$c->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(2,(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.documents' AND row_id=?",[$id]));
    }
    public function testTwoConcurrentPostingsAllocateDifferentNumbers(): void
    {
        $one=$this->draft(); $two=$this->draft(); $c=$this->barrier();
        [$a,$b]=$this->runPair($this->worker('documents',$one,'post'),$this->worker('documents',$two,'post'),$c);
        self::assertSame(200,$a['status']); self::assertSame(200,$b['status']); self::assertNotSame($a['body']['number'],$b['body']['number']);
        $numbers=[$a['body']['number'],$b['body']['number']]; sort($numbers);
        self::assertSame(['INT/20260101/000001','INT/20260101/000002'],$numbers);
    }
    public function testCloseVersusPostingCannotLeaveDraftInClosedPeriod(): void
    {
        $id=$this->draft(); $period=$this->fixture->periods['a']; $c=$this->barrier();
        [$post,$close]=$this->runPair($this->worker('documents',$id,'post'),$this->worker('periods',$period,'close'),$c);
        self::assertSame(200,$post['status']); self::assertContains($close['status'],[200,409]);
        self::assertSame('posted',$c->fetchOne('SELECT status FROM ucto.documents WHERE id=?',[$id]));
        self::assertSame($close['status']===200?'closed':'open',$c->fetchOne('SELECT status FROM ucto.periods WHERE id=?',[$period]));
        self::assertSame(0,(int)$c->fetchOne("SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=? AND period_id=? AND status='draft'",[$this->fixture->units['a'],$period]));
    }
    public function testCloseVersusNewDraftHasOnlyConsistentOutcomes(): void
    {
        $period=$this->fixture->periods['a']; $c=$this->barrier();
        [$close,$create]=$this->runPair($this->worker('periods',$period,'close'),$this->worker('documents','','',payload:$this->fixture->payload()),$c);
        self::assertContains([$close['status'],$create['status']],[[200,409],[409,201]]);
        self::assertSame($close['status']===200?'closed':'open',$c->fetchOne('SELECT status FROM ucto.periods WHERE id=?',[$period]));
        self::assertSame($create['status']===201?1:0,(int)$c->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
    public function testConcurrentClosingHasOneAuditedTransition(): void
    {
        $period=$this->fixture->periods['a']; $key=Uuid::v7()->toRfc4122(); $c=$this->barrier();
        [$a,$b]=$this->runPair($this->worker('periods',$period,'close',$key),$this->worker('periods',$period,'close',$key),$c);
        self::assertSame(200,$a['status']); self::assertSame(200,$b['status']); self::assertSame($a['body'],$b['body']); self::assertNotSame($a['replayed'],$b['replayed']);
        self::assertSame(2,(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.periods' AND row_id=?",[$period]));
    }
}
