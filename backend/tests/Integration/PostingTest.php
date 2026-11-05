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

final class PostingTest extends WebTestCase
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
    private function balanced(string $unit='a'): array { $p=$this->fixture->payload($unit); $p['lines'][1]['dal']='100.00'; return $p; }
    private function create(): string { return $this->fixture->save($this->balanced()); }
    private function etag(string $id,int $version=1): string { return '"'.$id.':'.$version.'"'; }
    private function body(): array { return json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR); }
    private function command(string $kind,string $id,string $suffix,string $user='manager',?string $tag=null,?string $key=null,string $body='{}',string $unit='a'): void
    {
        $this->client->request('POST','/api/v1/accounting-units/'.$this->fixture->units[$unit].'/'.$kind.'/'.$id.'/'.$suffix,
            server:['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects[$user]]),'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json',
                'HTTP_IF_MATCH'=>$tag??$this->etag($id),'HTTP_IDEMPOTENCY_KEY'=>$key??Uuid::v7()->toRfc4122()],content:$body);
    }
    public function testPostingAllocatesNumberAndAuditsOnlyStateTransition(): void
    {
        $id=$this->create(); $this->command('documents',$id,'post',user:'accountant'); self::assertResponseStatusCodeSame(200);
        $posted=$this->body(); self::assertSame('posted',$posted['status']); self::assertSame('INT/20260101/000001',$posted['number']);
        self::assertSame(2,$posted['version']); self::assertSame($this->fixture->users['accountant'],$posted['postedBy']); self::assertNotNull($posted['postedAt']);
        self::assertTrue($posted['balanced']); self::assertSame('100.00',$posted['totalMd']);
        self::assertResponseHeaderSame('etag',$this->etag($id,2));
        self::assertSame(2,(int)$this->fixture->owner->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.documents' AND row_id=?",[$id]));
        self::assertSame(2,(int)$this->fixture->owner->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.document_lines' AND new_data->>'document_id'=?",[$id]));
        $other=$this->create(); $this->command('documents',$other,'post'); self::assertResponseIsSuccessful(); self::assertSame('INT/20260101/000002',$this->body()['number']);
        $foreign=$this->fixture->save($this->balanced('b'),role:'foreign',unit:'b');
        $this->command('documents',$foreign,'post',user:'foreign',unit:'b'); self::assertResponseIsSuccessful(); self::assertSame('INT/20260101/000001',$this->body()['number']);
    }
    public function testReplayExpiryAndDifferentKeyNeverDuplicatePosting(): void
    {
        $id=$this->create(); $key=Uuid::v7()->toRfc4122();
        $this->command('documents',$id,'post',key:$key); self::assertResponseIsSuccessful(); $first=$this->body();
        $this->command('documents',$id,'post',key:$key); self::assertResponseIsSuccessful(); self::assertSame($first,$this->body()); self::assertResponseHeaderSame('idempotency-replayed','true');
        $this->command('documents',$id,'post',tag:$this->etag($id,2),key:$key); self::assertResponseStatusCodeSame(409);
        $this->command('documents',$id,'post'); self::assertResponseStatusCodeSame(412);
        $this->command('documents',$id,'post',tag:$this->etag($id,2)); self::assertResponseIsSuccessful(); self::assertSame($first,$this->body());
        $this->fixture->owner->executeStatement("UPDATE ucto.document_post_requests SET expires_at=clock_timestamp()-interval '1 second' WHERE resource_id=?",[$id]);
        $this->command('documents',$id,'post',key:$key); self::assertResponseStatusCodeSame(412);
        self::assertSame(1,(int)$this->fixture->owner->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(2,(int)$this->fixture->owner->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.documents' AND row_id=?",[$id]));
    }
    public function testUnbalancedEmptyAndInactiveDraftsDoNotConsumeNumbers(): void
    {
        foreach ([$this->fixture->payload(),array_replace($this->balanced(),['lines'=>[]])] as $p) {
            $id=$this->fixture->save($p); $this->command('documents',$id,'post'); self::assertResponseStatusCodeSame(409);
            self::assertSame('draft',$this->fixture->owner->fetchOne('SELECT status FROM ucto.documents WHERE id=?',[$id]));
        }
        $id=$this->create();
        $this->fixture->app->transactional(function(Connection $c): void { $this->fixture->context($c); $c->executeStatement('UPDATE ucto.accounts SET postable=false,version=version+1 WHERE id=?',[$this->fixture->accounts['a']['md']]); });
        $this->command('documents',$id,'post'); self::assertResponseStatusCodeSame(409);
        self::assertSame(0,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(0,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_post_requests WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
    public function testClosingRequiresNoDraftsAndLocksAllFurtherWrites(): void
    {
        $id=$this->create(); $period=$this->fixture->periods['a'];
        $this->command('periods',$period,'close'); self::assertResponseStatusCodeSame(409);
        $this->command('documents',$id,'post'); self::assertResponseIsSuccessful();
        $key=Uuid::v7()->toRfc4122(); $this->command('periods',$period,'close',key:$key); self::assertResponseIsSuccessful(); $closed=$this->body();
        self::assertSame('closed',$closed['status']); self::assertSame(2,$closed['version']); self::assertSame($this->fixture->users['manager'],$closed['closedBy']); self::assertNotNull($closed['closedAt']);
        $this->command('periods',$period,'close',key:$key); self::assertResponseIsSuccessful(); self::assertSame($closed,$this->body()); self::assertResponseHeaderSame('idempotency-replayed','true');
        $this->command('periods',$period,'close',tag:$this->etag($period,2)); self::assertResponseIsSuccessful(); self::assertSame($closed,$this->body());
        try { $this->fixture->save($this->balanced()); self::fail(); } catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        try { $this->fixture->app->transactional(function(Connection $c) use($period): void { $this->fixture->context($c); $c->executeStatement("UPDATE ucto.periods SET status='open',closed_at=null,closed_by=null,version=version+1 WHERE id=?",[$period]); }); self::fail(); }
        catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        self::assertSame(2,(int)$this->fixture->owner->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.periods' AND row_id=?",[$period]));
    }
    public function testPostedDocumentCannotBePatchedOrChangedThroughSqlFunctions(): void
    {
        $id=$this->create(); $this->command('documents',$id,'post'); self::assertResponseIsSuccessful();
        $this->client->request('PATCH','/api/v1/accounting-units/'.$this->fixture->units['a'].'/documents/'.$id,
            server:['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects['manager']]),'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/merge-patch+json','HTTP_IF_MATCH'=>$this->etag($id,2)],content:'{}');
        self::assertResponseStatusCodeSame(409);
        try { $this->fixture->save($this->balanced(),$id,2); self::fail(); } catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        self::assertFalse((bool)$this->fixture->owner->fetchOne("SELECT has_function_privilege('mikoucto_app','ucto.save_draft_72(uuid,uuid,integer,jsonb,uuid[])','EXECUTE')"));
        foreach (['UPDATE ucto.documents SET text=? WHERE id=?','UPDATE ucto.document_lines SET text=? WHERE document_id=?'] as $sql) {
            try { $this->fixture->owner->transactional(function(Connection $c) use($sql,$id): void { $this->fixture->context($c); $c->executeStatement($sql,['Forbidden',$id]); }); self::fail(); }
            catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        }
        self::assertSame('posted',$this->fixture->owner->fetchOne('SELECT status FROM ucto.documents WHERE id=?',[$id]));
    }
    public function testCommandRolesForeignUnitsAndPreconditions(): void
    {
        $id=$this->create();
        foreach (['reader','auditor'] as $user) { $this->command('documents',$id,'post',user:$user); self::assertResponseStatusCodeSame(403); }
        $this->command('documents',$id,'post',tag:''); self::assertResponseStatusCodeSame(428);
        $this->command('documents',$id,'post',key:''); self::assertResponseStatusCodeSame(400);
        $this->command('documents',$id,'post',body:'{"status":"posted"}'); self::assertResponseStatusCodeSame(400);
        $this->command('documents',$id,'post',body:'[]'); self::assertResponseStatusCodeSame(400);
        $foreign=$this->fixture->save($this->balanced('b'),role:'foreign',unit:'b');
        $this->command('documents',$foreign,'post'); self::assertResponseStatusCodeSame(404);
        $this->command('documents',$foreign,'post',unit:'b'); self::assertResponseStatusCodeSame(403);
        $this->command('documents',$id,'post',user:'accountant'); self::assertResponseIsSuccessful();
        $this->command('periods',$this->fixture->periods['a'],'close',user:'accountant'); self::assertResponseStatusCodeSame(403);
        try { $this->fixture->app->transactional(function(Connection $c): void { $this->fixture->context($c); $c->executeStatement('SELECT ucto.assert_postable(?::uuid,?::uuid,?::uuid,?::date)',[$this->fixture->units['a'],Uuid::v7()->toRfc4122(),$this->fixture->periods['a'],'2026-11-03']); }); self::fail(); }
        catch(Exception $e) { self::assertSame('42501',$e->getSQLState()); }
    }
    public function testLateFailureRollsBackNumberStatusAuditAndRetryKey(): void
    {
        $id=$this->create(); $key=Uuid::v7()->toRfc4122(); $c=$this->fixture->owner;
        $c->executeStatement("CREATE FUNCTION ucto._test_post_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Injected failure' USING ERRCODE='22012'; END \$\$");
        // Sort after zz_audit, so the test also rolls back an inserted audit row.
        $c->executeStatement("CREATE TRIGGER zzz_test_post_failure AFTER UPDATE ON ucto.documents FOR EACH ROW WHEN (NEW.id='$id'::uuid AND NEW.status='posted') EXECUTE FUNCTION ucto._test_post_failure()");
        try { $this->command('documents',$id,'post',key:$key); self::assertResponseStatusCodeSame(503); }
        finally { $c->executeStatement('DROP TRIGGER zzz_test_post_failure ON ucto.documents'); $c->executeStatement('DROP FUNCTION ucto._test_post_failure()'); }
        self::assertSame('draft',$c->fetchOne('SELECT status FROM ucto.documents WHERE id=?',[$id]));
        self::assertSame(1,(int)$c->fetchOne('SELECT version FROM ucto.documents WHERE id=?',[$id]));
        self::assertSame(0,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(0,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_post_requests WHERE resource_id=?',[$id]));
        self::assertSame(1,(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='ucto.documents' AND row_id=?",[$id]));
        $this->command('documents',$id,'post',key:$key); self::assertResponseIsSuccessful(); self::assertSame('INT/20260101/000001',$this->body()['number']);
    }
}
