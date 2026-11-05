<?php
namespace App\Tests\Integration;
use App\Tests\Support\AccountingWorker;
use App\Tests\Support\DraftFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
final class ReversalConcurrencyTest extends TestCase
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
    private function posted(): string
    {
        $p=$this->fixture->payload(); $p['lines'][1]['dal']='100.00'; $id=$this->fixture->save($p);
        $this->fixture->app->transactional(function(Connection $c) use($id): void { $this->fixture->context($c); $c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$id]); });
        return $id;
    }
    private function barrier(): Connection
    {
        $c=$this->fixture->owner; $c->beginTransaction();
        $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.accounts:'.$this->fixture->units['a']]); return $c;
    }
    private function worker(array $input): AccountingWorker
    {
        $w=new AccountingWorker($input+['action'=>'http','unit'=>$this->fixture->units['a'],'object'=>$this->fixture->objects['manager'],'user'=>$this->fixture->users['manager']]);
        $this->workers[]=$w; return $w;
    }
    private function reverse(string $id,?string $key=null): AccountingWorker
    {
        return $this->worker(['kind'=>'documents','suffix'=>'/'.$id.'/reverse','etag'=>'"'.$id.':2"','key'=>$key??Uuid::v7()->toRfc4122(),
            'payload'=>['periodId'=>$this->fixture->periods['a'],'postingDate'=>'2026-11-04','reason'=>'Concurrent reversal']]);
    }
    private function pair(AccountingWorker $one,AccountingWorker $two,Connection $c): array
    {
        $one->go(); $two->go(); AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$one,$two); $c->commit();
        $results=[$one->result(),$two->result()];
        foreach ([$one,$two] as $i=>$worker) {
            if (($results[$i]['status']??0)>=500) { self::fail('HTTP worker failed. Diagnostics: '.$worker->retainDiagnostics().'.*'); }
        }
        return $results;
    }
    #[DataProvider('keys')]
    public function testConcurrentReversalCreatesExactlyOneInverse(bool $sameKey): void
    {
        $id=$this->posted(); $key=Uuid::v7()->toRfc4122(); $c=$this->barrier();
        [$a,$b]=$this->pair($this->reverse($id,$key),$this->reverse($id,$sameKey?$key:null),$c);
        self::assertSame($a['body'],$b['body']); $statuses=[$a['status'],$b['status']]; sort($statuses);
        self::assertSame($sameKey?[201,201]:[200,201],$statuses,json_encode([$a,$b],JSON_THROW_ON_ERROR));
        if ($sameKey) { self::assertNotSame($a['replayed'],$b['replayed']); }
        self::assertSame(1,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_reversals WHERE original_document_id=?',[$id]));
        self::assertSame(2,(int)$c->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(2,(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.documents' AND row_id=?",[$a['body']['id']]));
    }
    public static function keys(): iterable { yield 'same key'=>[true]; yield 'different keys'=>[false]; }
    public function testReversalAgainstClosingCannotWriteIntoClosedPeriod(): void
    {
        $id=$this->posted(); $period=$this->fixture->periods['a']; $c=$this->barrier();
        [$reverse,$close]=$this->pair($this->reverse($id),$this->worker(['kind'=>'periods','suffix'=>'/'.$period.'/close','etag'=>'"'.$period.':1"','key'=>Uuid::v7()->toRfc4122(),'rawBody'=>'{}']),$c);
        self::assertSame(200,$close['status']); self::assertContains($reverse['status'],[201,409]);
        self::assertSame('closed',$c->fetchOne('SELECT status FROM ucto.periods WHERE id=?',[$period]));
        self::assertSame($reverse['status']===201?2:1,(int)$c->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(0,(int)$c->fetchOne("SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=? AND status='draft'",[$this->fixture->units['a']]));
    }
    public function testReversalAgainstAccountDeactivationHasOnlyAtomicOutcomes(): void
    {
        $id=$this->posted(); $c=$this->barrier();
        [$reverse,$change]=$this->pair($this->reverse($id),$this->worker(['action'=>'sql',
            'sql'=>'UPDATE ucto.accounts SET active=false,version=version+1 WHERE id=?','params'=>[$this->fixture->accounts['a']['md']]]),$c);
        self::assertSame('OK',$change['state']); self::assertContains($reverse['status'],[201,409]);
        self::assertSame($reverse['status']===201?1:0,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_reversals WHERE original_document_id=?',[$id]));
        self::assertSame($reverse['status']===201?2:1,(int)$c->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertFalse((bool)$c->fetchOne('SELECT active FROM ucto.accounts WHERE id=?',[$this->fixture->accounts['a']['md']]));
    }
}
