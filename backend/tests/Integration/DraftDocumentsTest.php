<?php
namespace App\Tests\Integration;
use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Tests\Support\DraftFixture;
use App\Tests\Support\TestTokens;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class DraftDocumentsTest extends WebTestCase
{
    private DraftFixture $fixture;
    private KernelBrowser $client;
    protected function setUp(): void
    {
        $this->fixture=new DraftFixture(); $this->client=self::createClient(); $this->client->disableReboot();
        self::getContainer()->set(Connection::class,$this->fixture->app);
        self::getContainer()->set(AccessTokenValidator::class,TestTokens::validator());
    }
    protected function tearDown(): void
    {
        self::ensureKernelShutdown(); if (isset($this->fixture)) { $this->fixture->close(); } parent::tearDown();
    }
    public function testLifecycleExactTotalsPartialUpdateAndCompleteHistory(): void
    {
        $payload=$this->fixture->payload();
        $this->request('POST',$payload); self::assertResponseStatusCodeSame(201);
        $first=$this->body(); $id=$first['id']; $tag=$this->tag(); $oldLine=$first['lines'][0]['id'];
        self::assertSame('draft',$first['status']); self::assertNull($first['number']); self::assertSame('CZK',$first['currency']);
        self::assertSame('100.00',$first['totalMd']); self::assertSame('99.00',$first['totalDal']); self::assertSame('1.00',$first['difference']); self::assertFalse($first['balanced']);
        self::assertSame('7',$id[14]); self::assertSame('7',$oldLine[14]); self::assertSame(1,$first['lines'][0]['lineNo']);
        self::assertResponseHeaderSame('location',$this->path().'/'.$id);
        $this->request('GET',suffix:'/'.$id); self::assertResponseIsSuccessful(); self::assertSame($first,$this->body());
        $this->request('PATCH',['text'=>'Header only','partnerId'=>null],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful();
        self::assertSame($oldLine,$this->body()['lines'][0]['id']); self::assertNull($this->body()['partnerId']); $tag=$this->tag();
        $payload['lines'][1]['dal']='100.00';
        $this->request('PATCH',['lines'=>$payload['lines']],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful();
        self::assertTrue($this->body()['balanced']); self::assertSame('0.00',$this->body()['difference']); self::assertSame(3,$this->body()['version']); self::assertNotSame($oldLine,$this->body()['lines'][0]['id']);
        $this->request('GET',suffix:'/'.$id.'/history'); self::assertResponseIsSuccessful();
        self::assertSame(9,$this->body()['total']);
        self::assertContains($oldLine,array_column($this->body()['items'],'row_id'));
        self::assertSame([$this->fixture->users['manager']],array_values(array_unique(array_column($this->body()['items'],'user_id'))));
        $this->request('GET',suffix:'?itemsPerPage=1&search=Header&from=2026-11-03&to=2026-11-03&periodId='.$this->fixture->periods['a']);
        self::assertResponseIsSuccessful(); self::assertCount(1,$this->body()); self::assertArrayNotHasKey('lines',$this->body()[0]); self::assertResponseHeaderSame('x-total-count','1');
        $this->request('DELETE',suffix:'/'.$id); self::assertResponseStatusCodeSame(405);
    }
    public function testEmptyOneLineLargeTotalsNoopAndStaleEtag(): void
    {
        $payload=$this->fixture->payload(); $payload['lines']=[];
        $this->request('POST',$payload); self::assertResponseStatusCodeSame(201); $id=$this->body()['id']; $tag=$this->tag();
        self::assertSame('0.00',$this->body()['totalMd']); self::assertTrue($this->body()['balanced']);
        $this->request('PATCH',[],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseStatusCodeSame(400); // JSON [] is not an object
        $line=$this->fixture->payload()['lines'][0]; $line['md']='9999999999999999.99';
        $this->request('PATCH',['lines'=>[$line]],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful(); $tag=$this->tag(); $one=$this->body(); self::assertCount(1,$one['lines']);
        $this->request('PATCH',['lines'=>[$line]],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful(); self::assertSame($one,$this->body());
        $this->request('PATCH',['lines'=>[$line,$line]],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful();
        self::assertSame('19999999999999999.98',$this->body()['totalMd']); $newTag=$this->tag();
        $this->request('GET',suffix:'/'.$id.'/history'); self::assertResponseIsSuccessful();
        $createdLines=array_values(array_filter($this->body()['items'],static fn($row)=>$row['table_name']==='ucto.document_lines' && $row['operation']==='I'));
        self::assertNotEmpty($createdLines);
        foreach ($createdLines as $row) { self::assertSame('9999999999999999.99',$row['new_data']['md']); self::assertSame('0.00',$row['new_data']['dal']); }
        $this->client->request('GET','/api/v1/accounting-units/'.$this->fixture->units['a'].'/audit',server:['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects['manager']]),'HTTP_ACCEPT'=>'application/json']);
        self::assertResponseIsSuccessful();
        $auditLines=array_values(array_filter($this->body()['items'],static fn($row)=>$row['table_name']==='ucto.document_lines' && $row['operation']==='I'));
        self::assertNotEmpty($auditLines);
        foreach ($auditLines as $row) { self::assertSame('9999999999999999.99',$row['new_data']['md']); }
        $this->request('PATCH',['text'=>'Lost'],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseStatusCodeSame(412);
        $this->request('PATCH',['text'=>'Missing'],'/'.$id); self::assertResponseStatusCodeSame(428);
        $this->request('PATCH',['lines'=>[]],'/'.$id,['HTTP_IF_MATCH'=>$newTag]); self::assertResponseIsSuccessful(); self::assertCount(0,$this->body()['lines']);
    }
    public function testIdempotentReplayConflictExpiryAndOriginalSnapshot(): void
    {
        $payload=$this->fixture->payload(); $headers=['HTTP_IDEMPOTENCY_KEY'=>Uuid::v7()->toRfc4122()];
        $this->request('POST',$payload,headers:$headers); self::assertResponseStatusCodeSame(201); $first=$this->body(); $tag=$this->tag();
        $this->request('PATCH',['text'=>'Changed'],'/'.$first['id'],['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful();
        $this->request('POST',$payload,headers:$headers); self::assertResponseStatusCodeSame(201); self::assertSame($first,$this->body()); self::assertSame($tag,$this->tag()); self::assertResponseHeaderSame('idempotency-replayed','true');
        $this->request('POST',array_replace($payload,['text'=>'Different']),headers:$headers); self::assertResponseStatusCodeSame(409);
        self::assertSame(1,$this->countDocuments());
        $this->fixture->owner->executeStatement("UPDATE ucto.document_create_requests SET expires_at=clock_timestamp()-interval '1 second' WHERE resource_id=?",[$first['id']]);
        $this->request('POST',$payload,headers:$headers); self::assertResponseStatusCodeSame(201); self::assertNotSame($first['id'],$this->body()['id']); self::assertSame(2,$this->countDocuments());
    }
    public function testInvalidValuesForeignLinksAndBadLastLineLeaveNoRows(): void
    {
        $payload=$this->fixture->payload();
        foreach ([1,'1.000','-1','1e2','01.00','10000000000000000.00'] as $amount) {
            $bad=$payload; $bad['lines'][1]['dal']=$amount; $this->request('POST',$bad); self::assertContains($this->client->getResponse()->getStatusCode(),[400,422]);
        }
        $bad=$payload; $bad['lines'][0]['text']=null; $this->request('POST',$bad); self::assertResponseStatusCodeSame(400);
        foreach (['periodId'=>$this->fixture->periods['b'],'partnerId'=>$this->fixture->partners['b'],'postingDate'=>'2027-01-01'] as $field=>$value) {
            $this->request('POST',array_replace($payload,[$field=>$value])); self::assertResponseStatusCodeSame(409);
        }
        foreach ([$this->fixture->accounts['b']['md'],$this->fixture->accounts['a']['inactive'],$this->fixture->accounts['a']['nonpostable']] as $account) {
            $bad=$payload; $bad['lines'][1]['accountId']=$account; $this->request('POST',$bad); self::assertResponseStatusCodeSame(409);
        }
        foreach (['status'=>'posted','totalMd'=>'100.00','version'=>2,'id'=>Uuid::v7()->toRfc4122()] as $field=>$value) { $this->request('POST',array_replace($payload,[$field=>$value])); self::assertResponseStatusCodeSame(400); }
        foreach ([['md'=>'0.00','dal'=>'0.00'],['md'=>'1.00','dal'=>'1.00']] as $sides) { $bad=$payload; $bad['lines'][1]=array_replace($bad['lines'][1],$sides); $this->request('POST',$bad); self::assertResponseStatusCodeSame(422); }
        $bad=$payload; $bad['lines']=array_fill(0,501,$payload['lines'][0]); $this->request('POST',$bad); self::assertResponseStatusCodeSame(422);
        $this->request('POST',$payload,headers:['HTTP_IDEMPOTENCY_KEY'=>'']); self::assertResponseStatusCodeSame(400);
        self::assertSame(0,$this->countDocuments());
        self::assertSame(0,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_create_requests WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
    public function testAllRolesRlsAndDatabaseWriteBoundary(): void
    {
        $this->request('POST',$this->fixture->payload(),user:'accountant'); self::assertResponseStatusCodeSame(201); $id=$this->body()['id'];
        foreach (['reader','auditor'] as $user) {
            $this->request('GET',suffix:'/'.$id,user:$user); self::assertResponseIsSuccessful();
            $this->request('POST',$this->fixture->payload(),user:$user); self::assertResponseStatusCodeSame(403);
            $this->request('GET',suffix:'/'.$id.'/history',user:$user); self::assertResponseStatusCodeSame($user==='auditor'?200:403);
        }
        $this->request('GET',suffix:'/'.$id.'/history',user:'accountant'); self::assertResponseStatusCodeSame(403);
        $this->request('GET',suffix:'/'.$id,user:'foreign',unit:'b'); self::assertResponseStatusCodeSame(404);
        self::assertSame(0,(int)$this->fixture->app->fetchOne('SELECT count(*) FROM ucto.documents'));
        foreach (['UPDATE ucto.documents SET version=version+1','DELETE FROM ucto.document_lines','INSERT INTO ucto.documents(id) VALUES (null)'] as $sql) {
            try { $this->fixture->app->transactional(function(Connection $c) use($sql): void { $this->fixture->context($c); $c->executeStatement($sql); }); self::fail(); }
            catch(Exception $e) { self::assertSame('42501',$e->getSQLState()); }
        }
        foreach (['reader','foreign'] as $role) {
            try { $this->fixture->save($this->fixture->payload(),role:$role); self::fail(); }
            catch(Exception $e) { self::assertSame('42501',$e->getSQLState()); }
        }
    }
    public function testDatabaseRejectsRoundingAndRollsBackAfterPartialReplacement(): void
    {
        $payload=$this->fixture->payload(); $id=$this->fixture->save($payload);
        $before=$this->fixture->owner->fetchOne('SELECT count(*) FROM core.audit_log WHERE table_name LIKE ? AND coalesce(new_data,old_data)->> ? = ?',['ucto.%','accounting_unit_id',$this->fixture->units['a']]);
        $bad=$payload; $bad['lines'][1]['dal']='1.001';
        try { $this->fixture->save($bad,$id,1); self::fail(); } catch(Exception $e) { self::assertSame('22023',$e->getSQLState()); }
        try {
            $this->fixture->owner->transactional(function(Connection $c) use($id): void {
                $this->fixture->context($c);
                $c->executeStatement("INSERT INTO ucto.document_lines(id,accounting_unit_id,document_id,line_no,account_id,md,dal) VALUES (?,?,?,3,?,0.001,0)",
                    [Uuid::v7()->toRfc4122(),$this->fixture->units['a'],$id,$this->fixture->accounts['a']['md']]);
            }); self::fail('The numeric column must reject excess precision rather than round it.');
        } catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        // Repeated generated row IDs force a late unique violation after the
        // header was updated and old rows deleted: all effects must roll back.
        try {
            $this->fixture->app->transactional(function(Connection $c) use($payload,$id): void {
                $this->fixture->context($c); $changed=$payload; $changed['text']='Must roll back'; $changed['lines'][1]['dal']='100.00'; $same=Uuid::v7()->toRfc4122();
                $c->fetchOne('SELECT ucto.save_draft(?::uuid,?::uuid,1,?::jsonb,?::uuid[])',[$this->fixture->units['a'],$id,json_encode($changed),'{'.$same.','.$same.'}']);
            }); self::fail();
        } catch(Exception $e) { self::assertSame('23505',$e->getSQLState()); }
        self::assertSame($before,$this->fixture->owner->fetchOne('SELECT count(*) FROM core.audit_log WHERE table_name LIKE ? AND coalesce(new_data,old_data)->> ? = ?',['ucto.%','accounting_unit_id',$this->fixture->units['a']]));
        self::assertSame(1,(int)$this->fixture->owner->fetchOne('SELECT version FROM ucto.documents WHERE id=?',[$id]));
        self::assertSame(2,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_lines WHERE document_id=?',[$id]));
    }
    public function testUsedReferencesAreProtectedAndDeactivatedLineCanBeRemoved(): void
    {
        try {
            $this->fixture->app->transactional(function(Connection $c): void {
                $c->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->fixture->context($c);
                $c->executeStatement("UPDATE ucto.periods SET ends_on='2026-12-30',version=version+1 WHERE id=?",[$this->fixture->periods['a']]);
            }); self::fail('Snapshot-isolated period changes must be refused.');
        } catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); self::assertStringContainsString('require read committed',$e->getMessage()); }
        $payload=$this->fixture->payload(); $id=$this->fixture->save($payload);
        foreach ([['UPDATE ucto.accounts SET code=?,version=version+1 WHERE id=?',['999',$this->fixture->accounts['a']['md']]],['UPDATE ucto.periods SET ends_on=?::date,version=version+1 WHERE id=?',['2026-12-30',$this->fixture->periods['a']]]] as [$sql,$params]) {
            try { $this->fixture->app->transactional(function(Connection $c) use($sql,$params): void { $this->fixture->context($c); $c->executeStatement($sql,$params); }); self::fail(); }
            catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        }
        $this->fixture->app->transactional(function(Connection $c): void { $this->fixture->context($c); $c->executeStatement('UPDATE ucto.accounts SET active=false,version=version+1 WHERE id=?',[$this->fixture->accounts['a']['md']]); });
        try { $this->fixture->save($payload,$id,1); self::fail(); } catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        $payload['lines']=[]; $this->fixture->save($payload,$id,1);
        self::assertSame(0,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_lines WHERE document_id=?',[$id]));
    }
    private function path(string $unit='a'): string { return '/api/v1/accounting-units/'.$this->fixture->units[$unit].'/documents'; }
    private function body(): array { return json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR); }
    private function tag(): string { return $this->client->getResponse()->headers->get('ETag')??''; }
    private function countDocuments(): int { return (int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$this->fixture->units['a']]); }
    private function request(string $method,?array $payload=null,string $suffix='',array $headers=[],string $user='manager',string $unit='a'): void
    {
        if ($method==='POST' && !array_key_exists('HTTP_IDEMPOTENCY_KEY',$headers)) { $headers['HTTP_IDEMPOTENCY_KEY']=Uuid::v7()->toRfc4122(); }
        $this->client->request($method,$this->path($unit).$suffix,server:array_merge(['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects[$user]]),'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>$method==='PATCH'?'application/merge-patch+json':'application/json'],$headers),content:$payload===null?null:json_encode($payload));
    }
}
