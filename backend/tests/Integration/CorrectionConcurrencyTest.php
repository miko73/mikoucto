<?php
namespace App\Tests\Integration;
use App\Tests\Support\AccountingWorker;
use App\Tests\Support\DraftFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
final class CorrectionConcurrencyTest extends TestCase
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
    private function reversed(): string
    {
        $p=$this->fixture->payload(); $p['lines'][1]['dal']='100.00'; $id=$this->fixture->save($p);
        $this->fixture->app->transactional(function(Connection $c) use($id): void {
            $this->fixture->context($c); $c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$id]);
            $c->fetchOne('SELECT ucto.reverse_document(?::uuid,?::uuid,2,?::uuid,?::date,?)',[$this->fixture->units['a'],$id,$this->fixture->periods['a'],'2026-11-04','Correction test']);
        }); return $id;
    }
    private function barrier(): Connection
    {
        $c=$this->fixture->owner; $c->beginTransaction();
        $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.accounts:'.$this->fixture->units['a']]); return $c;
    }
    private function worker(array $input): AccountingWorker
    {
        $w=new AccountingWorker($input+['action'=>'http','kind'=>'documents','unit'=>$this->fixture->units['a'],'object'=>$this->fixture->objects['manager'],'user'=>$this->fixture->users['manager']]);
        $this->workers[]=$w; return $w;
    }
    private function correction(string $id,?string $key=null): AccountingWorker
    {
        return $this->worker(['suffix'=>'/'.$id.'/correction-draft','etag'=>'"'.$id.':2"','key'=>$key??Uuid::v7()->toRfc4122(),
            'payload'=>['periodId'=>$this->fixture->periods['a'],'postingDate'=>'2026-11-05']]);
    }
    private function pair(AccountingWorker $one,AccountingWorker $two,Connection $c): array
    {
        $one->go(); $two->go(); AccountingWorker::awaitBlockedBy($this->fixture->owner,$c,$one,$two); $c->commit();
        $results=[$one->result(),$two->result()];
        foreach ([$one,$two] as $i=>$worker) { if (($results[$i]['status']??0)>=500) { self::fail('HTTP worker failed. Diagnostics: '.$worker->retainDiagnostics().'.*'); } }
        return $results;
    }
    #[DataProvider('keys')]
    public function testConcurrentRequestsCreateOneDraftWithoutAllocatingNumber(bool $sameKey): void
    {
        $id=$this->reversed(); $key=Uuid::v7()->toRfc4122(); $c=$this->barrier();
        [$a,$b]=$this->pair($this->correction($id,$key),$this->correction($id,$sameKey?$key:null),$c);
        self::assertSame($a['body'],$b['body']); $statuses=[$a['status'],$b['status']]; sort($statuses);
        self::assertSame($sameKey?[201,201]:[200,201],$statuses);
        if ($sameKey) { self::assertNotSame($a['replayed'],$b['replayed']); }
        self::assertSame(1,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_corrections WHERE original_document_id=?',[$id]));
        self::assertSame(3,(int)$c->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(2,(int)$c->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(1,(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.documents' AND row_id=?",[$a['body']['id']]));
    }
    public static function keys(): iterable { yield 'same key'=>[true]; yield 'different keys'=>[false]; }
    public function testCorrectionAgainstClosingNeverLeavesDraftInClosedPeriod(): void
    {
        $id=$this->reversed(); $period=$this->fixture->periods['a']; $c=$this->barrier();
        [$create,$close]=$this->pair($this->correction($id),$this->worker(['kind'=>'periods','suffix'=>'/'.$period.'/close','etag'=>'"'.$period.':1"','key'=>Uuid::v7()->toRfc4122(),'rawBody'=>'{}']),$c);
        self::assertContains([$create['status'],$close['status']],[[201,409],[409,200]]);
        self::assertSame($create['status']===201?'open':'closed',$c->fetchOne('SELECT status FROM ucto.periods WHERE id=?',[$period]));
        self::assertSame($create['status']===201?1:0,(int)$c->fetchOne("SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=? AND status='draft'",[$this->fixture->units['a']]));
        self::assertSame(2,(int)$c->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
    public function testRetryCannotOverwriteConcurrentDraftEdits(): void
    {
        $id=$this->reversed();
        $draft=$this->fixture->app->transactional(function(Connection $c) use($id): string {
            $this->fixture->context($c); return $c->fetchOne('SELECT ucto.create_correction_draft(?::uuid,?::uuid,2,?::uuid,?::date)',[$this->fixture->units['a'],$id,$this->fixture->periods['a'],'2026-11-05']);
        });
        $c=$this->barrier();
        [$retry,$edit]=$this->pair($this->correction($id),$this->worker(['method'=>'PATCH','suffix'=>'/'.$draft,'etag'=>'"'.$draft.':1"','payload'=>['text'=>'Concurrent edit']]),$c);
        self::assertSame(200,$retry['status']); self::assertSame($draft,$retry['body']['id']); self::assertSame(200,$edit['status']);
        self::assertSame('Concurrent edit',$c->fetchOne('SELECT text FROM ucto.documents WHERE id=?',[$draft]));
        self::assertSame(1,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_corrections WHERE original_document_id=?',[$id]));
    }
}
