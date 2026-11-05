<?php
namespace App\Tests\Integration;
use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Tests\Support\DraftFixture;
use App\Tests\Support\TestTokens;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ReversalTest extends WebTestCase
{
    private DraftFixture $fixture;
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    protected function setUp(): void
    {
        $this->fixture=new DraftFixture(); $this->client=self::createClient(); $this->client->disableReboot();
        self::getContainer()->set(Connection::class,$this->fixture->app);
        self::getContainer()->set(AccessTokenValidator::class,TestTokens::validator());
    }
    protected function tearDown(): void { self::ensureKernelShutdown(); if (isset($this->fixture)) { $this->fixture->close(); } parent::tearDown(); }
    private function posted(string $amount='100.00',string $unit='a'): string
    {
        $payload=$this->fixture->payload($unit); $payload['lines'][0]['md']=$amount; $payload['lines'][1]['dal']=$amount;
        $role=$unit==='a'?'manager':'foreign'; $id=$this->fixture->save($payload,role:$role,unit:$unit);
        $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units[$unit],$id]),$role,$unit);
        return $id;
    }
    private function sql(callable $callback,string $role='manager',string $unit='a'): mixed
    {
        return $this->fixture->app->transactional(function(Connection $c) use($callback,$role,$unit) { $this->fixture->context($c,$role,$unit); return $callback($c); });
    }
    private function request(string $method,string $suffix,?array $payload=null,string $role='manager',string $unit='a',array $headers=[]): array
    {
        $this->client->request($method,'/api/v1/accounting-units/'.$this->fixture->units[$unit].'/'.$suffix,
            server:array_replace(['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects[$role]]),'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'],$headers),
            content:$payload===null?null:json_encode($payload,JSON_THROW_ON_ERROR));
        return json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR);
    }
    private function input(array $changes=[]): array { return array_replace(['periodId'=>$this->fixture->periods['a'],'postingDate'=>'2026-11-04','reason'=>'Oprava částky'],$changes); }
    private function reverse(string $id,array $changes=[],?string $key=null,string $role='manager',array $headers=[]): array
    {
        return $this->request('POST','documents/'.$id.'/reverse',$this->input($changes),$role,headers:array_replace([
            'HTTP_IF_MATCH'=>'"'.$id.':2"','HTTP_IDEMPOTENCY_KEY'=>$key??Uuid::v7()->toRfc4122()],$headers));
    }
    private function originalRows(string $id): array
    {
        return [$this->fixture->owner->fetchAssociative('SELECT * FROM ucto.documents WHERE id=?',[$id]),
            $this->fixture->owner->fetchAllAssociative('SELECT * FROM ucto.document_lines WHERE document_id=? ORDER BY line_no',[$id])];
    }
    public function testAtomicInverseKeepsOriginalSnapshotAndExposesAuditedRelations(): void
    {
        $id=$this->posted(); $before=$this->originalRows($id);
        $original=$this->request('GET','documents/'.$id); $tag=$this->client->getResponse()->headers->get('ETag');
        $new=$this->reverse($id,role:'accountant'); self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('ETag','"'.$new['id'].':2"');
        self::assertResponseHeaderSame('Location','/api/v1/accounting-units/'.$this->fixture->units['a'].'/documents/'.$new['id']);
        self::assertResponseHeaderSame('Idempotency-Replayed','false');
        self::assertSame('posted',$new['status']); self::assertSame('INT/20260101/000002',$new['number']);
        self::assertNotSame($id,$new['id']); self::assertSame($this->fixture->users['accountant'],$new['postedBy']);
        foreach (['text','partnerId','currency','totalMd','totalDal','balanced'] as $field) { self::assertSame($original[$field],$new[$field]); }
        foreach ($original['lines'] as $i=>$line) {
            self::assertNotSame($line['id'],$new['lines'][$i]['id']);
            foreach (['accountId','text','lineNo'] as $field) { self::assertSame($line[$field],$new['lines'][$i][$field]); }
            self::assertSame($line['md'],$new['lines'][$i]['dal']); self::assertSame($line['dal'],$new['lines'][$i]['md']);
        }
        self::assertSame($before,$this->originalRows($id));
        self::assertSame($original,$this->request('GET','documents/'.$id)); self::assertResponseHeaderSame('ETag',$tag);
        $source=$this->request('GET','documents/'.$id.'/relations',role:'reader'); self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control','no-store, private'); self::assertFalse($this->client->getResponse()->headers->has('ETag'));
        self::assertSame($new['id'],$source['reversal']['id']); self::assertNull($source['reverses']);
        self::assertSame('Oprava částky',$source['reversal']['reason']); self::assertSame($this->fixture->users['accountant'],$source['reversal']['createdBy']);
        $target=$this->request('GET','documents/'.$new['id'].'/relations',role:'auditor'); self::assertSame($id,$target['reverses']['id']); self::assertNull($target['reversal']);
        foreach ([$id,$new['id']] as $document) {
            $history=$this->request('GET','documents/'.$document.'/history');
            $events=array_values(array_filter($history['items'],static fn($row)=>$row['table_name']==='ucto.document_reversals'));
            self::assertCount(1,$events); self::assertSame($id,$events[0]['new_data']['original_document_id']);
            self::assertSame($new['id'],$events[0]['new_data']['reversal_document_id']);
        }
        $this->reverse($new['id']); self::assertResponseStatusCodeSame(409);
        foreach (['UPDATE ucto.document_reversals SET reason=reason WHERE original_document_id=?','DELETE FROM ucto.document_reversals WHERE original_document_id=?'] as $sql) {
            try { $this->sql(fn(Connection $c)=>$c->executeStatement($sql,[$id])); self::fail('Direct application write was allowed.'); }
            catch(Exception $e) { self::assertSame('42501',$e->getSQLState()); }
        }
        try { $this->fixture->owner->transactional(function(Connection $c) use($id): void { $this->fixture->context($c); $c->executeStatement('UPDATE ucto.document_reversals SET reason=reason WHERE original_document_id=?',[$id]); }); self::fail(); }
        catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
    }
    public function testRetriesSurviveExpiryDifferentKeysUsersAndClosedTargetWithoutNewPosting(): void
    {
        $id=$this->posted(); $key=Uuid::v7()->toRfc4122();
        $first=$this->reverse($id,key:$key); self::assertResponseStatusCodeSame(201);
        self::assertSame($first,$this->reverse($id,['reason'=>'  Oprava částky  '],$key)); self::assertResponseStatusCodeSame(201); self::assertResponseHeaderSame('Idempotency-Replayed','true');
        $this->reverse($id,['reason'=>'Jiný důvod'],$key); self::assertResponseStatusCodeSame(409);
        self::assertSame($first,$this->reverse($id,role:'accountant')); self::assertResponseStatusCodeSame(200);
        $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$this->fixture->periods['a']]));
        $this->fixture->owner->executeStatement("UPDATE ucto.document_reverse_requests SET expires_at=clock_timestamp()-interval '1 second' WHERE source_id=?",[$id]);
        self::assertSame($first,$this->reverse($id,key:$key)); self::assertResponseStatusCodeSame(200); self::assertResponseHeaderSame('Idempotency-Replayed','false');
        self::assertSame($first,$this->reverse($id,key:$key)); self::assertResponseStatusCodeSame(200); self::assertResponseHeaderSame('Idempotency-Replayed','true');
        $this->reverse($id,['reason'=>'Jiný důvod']); self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Location','/api/v1/accounting-units/'.$this->fixture->units['a'].'/documents/'.$first['id']);
        $this->reverse($id,headers:['HTTP_IF_MATCH'=>'"'.$id.':1"']); self::assertResponseStatusCodeSame(412);
        self::assertSame(2,(int)$this->fixture->owner->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(1,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_reversals WHERE original_document_id=?',[$id]));
        self::assertSame(2,(int)$this->fixture->owner->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.documents' AND row_id=?",[$first['id']]));
    }
    public function testRolesUnitsInputAndPreconditionsAreCheckedBeforeReplay(): void
    {
        $id=$this->posted();
        foreach (['reader','auditor'] as $role) {
            $this->reverse($id,role:$role); self::assertResponseStatusCodeSame(403);
            try { $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.reverse_document(?::uuid,?::uuid,2,?::uuid,?::date,?)',[$this->fixture->units['a'],$id,$this->fixture->periods['a'],'2026-11-04','Reason']),$role); self::fail(); }
            catch(Exception $e) { self::assertSame('42501',$e->getSQLState()); }
        }
        $this->reverse($id,headers:['HTTP_IF_MATCH'=>'']); self::assertResponseStatusCodeSame(428);
        $this->reverse($id,headers:['HTTP_IDEMPOTENCY_KEY'=>'']); self::assertResponseStatusCodeSame(400);
        foreach ([['reason'=>' '],['lines'=>[]],['postingDate'=>'2026-02-30'],['periodId'=>null]] as $changes) { $this->reverse($id,$changes); self::assertResponseStatusCodeSame(422); }
        $foreign=$this->posted(unit:'b');
        $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.reverse_document(?::uuid,?::uuid,2,?::uuid,?::date,?)',[$this->fixture->units['b'],$foreign,$this->fixture->periods['b'],'2026-11-04','Foreign reversal']),'foreign','b');
        $this->reverse($foreign); self::assertResponseStatusCodeSame(404);
        $this->request('GET','documents/'.$foreign.'/relations'); self::assertResponseStatusCodeSame(404);
        $this->request('GET','documents/'.$foreign.'/relations',unit:'b'); self::assertResponseStatusCodeSame(403);
        $this->reverse($id,['periodId'=>$this->fixture->periods['b']]); self::assertResponseStatusCodeSame(409);
        self::assertSame(0,$this->sql(fn(Connection $c)=>(int)$c->fetchOne('SELECT count(*) FROM ucto.document_reversals WHERE accounting_unit_id=?',[$this->fixture->units['b']])));
        $key=Uuid::v7()->toRfc4122(); $this->reverse($id,key:$key); self::assertResponseStatusCodeSame(201);
        $this->fixture->owner->transactional(function(Connection $c): void {
            $this->fixture->context($c); $c->executeStatement("UPDATE core.user_roles SET role_code='reader' WHERE user_id=? AND accounting_unit_id=?",[$this->fixture->users['manager'],$this->fixture->units['a']]);
        });
        $this->reverse($id,key:$key); self::assertResponseStatusCodeSame(403);
    }
    public function testInvalidBusinessStatesLeaveNoNewDocumentOrNumber(): void
    {
        $draft=$this->fixture->save($this->fixture->payload());
        $this->reverse($draft,headers:['HTTP_IF_MATCH'=>'"'.$draft.':1"']); self::assertResponseStatusCodeSame(409);
        $id=$this->posted(); $count=(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$this->fixture->units['a']]);
        foreach ([['postingDate'=>'2026-11-02'],['postingDate'=>'2027-01-01']] as $changes) { $this->reverse($id,$changes); self::assertResponseStatusCodeSame(409); }
        foreach (['active','postable'] as $flag) {
            $this->sql(fn(Connection $c)=>$c->executeStatement("UPDATE ucto.accounts SET $flag=false,version=version+1 WHERE id=?",[$this->fixture->accounts['a']['md']]));
            $this->reverse($id); self::assertResponseStatusCodeSame(409);
            $this->sql(fn(Connection $c)=>$c->executeStatement("UPDATE ucto.accounts SET $flag=true,version=version+1 WHERE id=?",[$this->fixture->accounts['a']['md']]));
        }
        self::assertSame($count,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(1,(int)$this->fixture->owner->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(0,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_reverse_requests WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
    public function testClosedSourceCanReverseIntoOpenPeriodWithoutCarryForward(): void
    {
        $id=$this->posted(); $before=$this->originalRows($id); $other=Uuid::v7()->toRfc4122();
        $this->fixture->owner->transactional(function(Connection $c) use($other): void {
            $this->fixture->context($c); $c->executeStatement("INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on) VALUES (?,?,'2027','2027-01-01','2027-12-31')",[$other,$this->fixture->units['a']]);
        });
        $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$this->fixture->periods['a']]));
        $this->reverse($id); self::assertResponseStatusCodeSame(409);
        $new=$this->reverse($id,['periodId'=>$other,'postingDate'=>'2027-01-05']); self::assertResponseStatusCodeSame(201);
        self::assertSame('INT/20270101/000001',$new['number']); self::assertSame($before,$this->originalRows($id));
        foreach ([$this->fixture->periods['a']=>'100.00',$other=>'-100.00'] as $period=>$balance) {
            $report=$this->report($period); self::assertSame('0.00',$report['totals']['openingBalance']); self::assertSame($balance,$report['totals']['closingBalance']);
            $csv=$this->csv($period); self::assertStringContainsString($balance==='100.00'?';100,00;0,00;100,00':';0,00;100,00;-100,00',$csv);
        }
    }
    private function report(?string $period=null): array
    {
        return $this->request('GET','reports/general-ledger?'.http_build_query(['periodId'=>$period??$this->fixture->periods['a'],'accountId'=>$this->fixture->accounts['a']['md']]),role:'reader');
    }
    #[DataProvider('correctionPeriods')]
    public function testCorrectionExampleKeepsGrossTurnoversAndPeriodBoundaries(bool $nextPeriod): void
    {
        $source=$this->posted(); $period=$this->fixture->periods['a'];
        if ($nextPeriod) {
            $period=Uuid::v7()->toRfc4122();
            $this->fixture->owner->transactional(function(Connection $c) use($period): void {
                $this->fixture->context($c);
                $c->executeStatement("INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on) VALUES (?,?,'2027','2027-01-01','2027-12-31')",[$period,$this->fixture->units['a']]);
            });
            $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$this->fixture->periods['a']]));
        }
        $date=$nextPeriod?'2027-01-05':'2026-11-04';
        $inverse=$this->reverse($source,['periodId'=>$period,'postingDate'=>$date]); self::assertResponseStatusCodeSame(201);
        $before=$this->report($period);
        self::assertSame($nextPeriod?'-100.00':'0.00',$before['totals']['closingBalance']);
        $payload=$this->fixture->payload(); $payload['periodId']=$period; $payload['postingDate']=$nextPeriod?'2027-01-06':'2026-11-05';
        $payload['lines'][0]['md']='80.00'; $payload['lines'][1]['dal']='80.00';
        // 9.1 verifies report behavior through the existing draft command;
        // creating a linked correction draft belongs to 9.2.
        $correction=$this->fixture->save($payload);
        $withDraft=$this->report($period);
        self::assertSame($before['totals'],$withDraft['totals']);
        self::assertSame($before['items'],$withDraft['items']);
        $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$correction]));
        foreach (['md'=>['80.00','100.00',$nextPeriod?'-20.00':'80.00'],
                  'dal'=>['100.00','80.00',$nextPeriod?'20.00':'-80.00']] as $account=>$expected) {
            if (!$nextPeriod) { $expected[$account==='md'?0:1]='180.00'; }
            $ledger=$this->request('GET','reports/general-ledger?'.http_build_query(['periodId'=>$period,'accountId'=>$this->fixture->accounts['a'][$account]]),role:'reader');
            self::assertResponseIsSuccessful(); self::assertSame($nextPeriod?2:3,$ledger['total']);
            foreach (['turnoverMd','turnoverDal','closingBalance'] as $i=>$field) { self::assertSame($expected[$i],$ledger['totals'][$field],$account.' '.$field); }
            self::assertSame('0.00',$ledger['totals']['openingBalance']);
            self::assertContains($inverse['id'],array_column($ledger['items'],'documentId'));
        }
        $trial=$this->request('GET','reports/trial-balance?periodId='.$period,role:'reader'); self::assertResponseIsSuccessful();
        foreach (['turnoverMd','turnoverDal'] as $field) { self::assertSame($nextPeriod?'180.00':'280.00',$trial['totals'][$field]); }
        foreach (['closingMd','closingDal'] as $field) { self::assertSame($nextPeriod?'20.00':'80.00',$trial['totals'][$field]); }
        self::assertTrue($trial['totals']['balanced']);
        $csv=$this->csv($period);
        self::assertStringContainsString($nextPeriod?';0,00;100,00;-100,00':';100,00;0,00;100,00',$csv);
        self::assertStringContainsString($nextPeriod?';80,00;0,00;-20,00':';80,00;0,00;80,00',$csv);
        self::assertStringContainsString($inverse['number'],$csv);
        self::assertStringContainsString($nextPeriod?';180,00;180,00;20,00;20,00':';280,00;280,00;80,00;80,00',$this->csv($period,trial:true));
        if ($nextPeriod) {
            $original=$this->report(); self::assertSame(1,$original['total']); self::assertSame('100.00',$original['totals']['closingBalance']);
            self::assertStringContainsString(';100,00;0,00;100,00',$this->csv());
        }
    }
    public static function correctionPeriods(): iterable
    {
        yield 'same period'=>[false]; yield 'closed source and next period'=>[true];
    }
    private function csv(?string $period=null,bool $trial=false): string
    {
        $query=['periodId'=>$period??$this->fixture->periods['a']];
        if (!$trial) { $query['accountId']=$this->fixture->accounts['a']['md']; }
        $this->client->request('GET','/api/v1/accounting-units/'.$this->fixture->units['a'].'/reports/'.($trial?'trial-balance':'general-ledger').'.csv?'.http_build_query($query),server:['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects['reader']]),'HTTP_ACCEPT'=>'text/csv']);
        self::assertResponseIsSuccessful();
        return $this->client->getInternalResponse()->getContent();
    }
    public function testMaximumAmountsAndGrossTurnoversAreExactInJsonAndCsv(): void
    {
        $id=$this->posted('9999999999999999.99'); $inverse=$this->reverse($id); self::assertResponseStatusCodeSame(201);
        self::assertSame('9999999999999999.99',$inverse['lines'][0]['dal']);
        $report=$this->report(); self::assertSame(2,$report['total']);
        foreach (['turnoverMd','turnoverDal'] as $field) { self::assertSame('9999999999999999.99',$report['totals'][$field]); }
        self::assertSame('0.00',$report['totals']['closingBalance']);
        $trial=$this->request('GET','reports/trial-balance?periodId='.$this->fixture->periods['a'],role:'reader');
        self::assertSame('19999999999999999.98',$trial['totals']['turnoverMd']); self::assertSame('19999999999999999.98',$trial['totals']['turnoverDal']);
        self::assertSame('0.00',$trial['totals']['closingMd']); self::assertTrue($trial['totals']['balanced']);
        $bytes=$this->csv(); self::assertStringStartsWith("\xEF\xBB\xBF",$bytes);
        self::assertStringContainsString('9999999999999999,99',$bytes); self::assertStringContainsString($inverse['number'],$bytes);
        self::assertStringContainsString(';19999999999999999,98;19999999999999999,98;0,00;0,00',$this->csv(trial:true));
    }
    public function testLateFailureRollsBackEntireInverseAndAllowsSameKeyRetry(): void
    {
        $id=$this->posted(); $key=Uuid::v7()->toRfc4122(); $c=$this->fixture->owner; $unit=$this->fixture->units['a'];
        $before=(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE new_data->>'accounting_unit_id'=?",[$unit]);
        $c->executeStatement("CREATE FUNCTION ucto._test_reverse_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Injected reversal failure' USING ERRCODE='22012'; END \$\$");
        $c->executeStatement("CREATE TRIGGER zzz_test_reverse_failure AFTER INSERT ON ucto.document_reversals FOR EACH ROW WHEN (NEW.original_document_id='$id'::uuid) EXECUTE FUNCTION ucto._test_reverse_failure()");
        try { $this->reverse($id,key:$key); self::assertResponseStatusCodeSame(503); }
        finally { $c->executeStatement('DROP TRIGGER zzz_test_reverse_failure ON ucto.document_reversals'); $c->executeStatement('DROP FUNCTION ucto._test_reverse_failure()'); }
        self::assertSame(1,(int)$c->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$unit]));
        self::assertSame(2,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_lines WHERE accounting_unit_id=?',[$unit]));
        self::assertSame(1,(int)$c->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$unit]));
        self::assertSame(0,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_reversals WHERE accounting_unit_id=?',[$unit]));
        self::assertSame(0,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_reverse_requests WHERE accounting_unit_id=?',[$unit]));
        self::assertSame($before,(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE new_data->>'accounting_unit_id'=?",[$unit]));
        $new=$this->reverse($id,key:$key); self::assertResponseStatusCodeSame(201); self::assertSame('INT/20260101/000002',$new['number']);
    }
    public function testSqlEnforcesInverseAndIsolationWithoutDependingOnHttpValidation(): void
    {
        self::assertFalse((bool)$this->fixture->owner->fetchOne("SELECT has_function_privilege('mikoucto_read','ucto.reverse_document(uuid,uuid,integer,uuid,date,text)','EXECUTE')"));
        self::assertFalse((bool)$this->fixture->owner->fetchOne("SELECT has_table_privilege('mikoucto_app','ucto.document_reversals','INSERT')"));
        $source=$this->posted(); $wrong=$this->posted('80.00');
        try {
            $this->fixture->owner->transactional(function(Connection $c) use($source,$wrong): void {
                $this->fixture->context($c);
                $c->executeStatement('INSERT INTO ucto.document_reversals(id,accounting_unit_id,original_document_id,reversal_document_id,reason,created_by) VALUES (?,?,?,?,?,?)',
                    [Uuid::v7()->toRfc4122(),$this->fixture->units['a'],$source,$wrong,'Invalid inverse',$this->fixture->users['manager']]);
            }); self::fail('SQL accepted lines that are not exact inverses.');
        } catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        try {
            $this->fixture->app->transactional(function(Connection $c) use($source): void {
                $c->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $this->fixture->context($c);
                $c->fetchOne('SELECT ucto.reverse_document(?::uuid,?::uuid,2,?::uuid,?::date,?)',[$this->fixture->units['a'],$source,$this->fixture->periods['a'],'2026-11-04','Isolation']);
            }); self::fail('SQL accepted a stale-snapshot transaction.');
        } catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        self::assertSame(0,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_reversals WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(2,(int)$this->fixture->owner->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
}
