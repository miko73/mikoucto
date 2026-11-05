<?php
namespace App\Tests\Integration;
use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Tests\Support\AccountingFixture;
use App\Tests\Support\TestTokens;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AccountingTest extends WebTestCase
{
    private AccountingFixture $fixture;
    private KernelBrowser $client;
    protected function setUp(): void
    {
        $this->fixture=new AccountingFixture();
        $this->client=self::createClient(); $this->client->disableReboot();
        self::getContainer()->set(Connection::class,$this->fixture->app);
        self::getContainer()->set(AccessTokenValidator::class,TestTokens::validator());
    }
    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        if (isset($this->fixture)) { $this->fixture->close(); }
        parent::tearDown();
    }
    public function testAccountLifecycleAndHistory(): void
    {
        $this->request('POST','accounts',['code'=>'001','name'=>'  Test  ']); self::assertResponseStatusCodeSame(201);
        $account=$this->body(); $tag=$this->tag(); $id=$account['id'];
        self::assertSame('001',$account['code']); self::assertSame('Test',$account['name']); self::assertTrue($account['active']); self::assertTrue($account['postable']); self::assertNull($account['parentId']); self::assertSame('7',$id[14]);
        self::assertResponseHeaderSame('location',$this->path('accounts').'/'.$id);
        $this->request('GET','accounts',suffix:'/'.$id); self::assertResponseIsSuccessful(); self::assertSame($tag,$this->tag());
        $this->request('PATCH','accounts',['active'=>false,'postable'=>false],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful(); self::assertSame(2,$this->body()['version']); self::assertFalse($this->body()['active']);
        $this->request('GET','accounts',suffix:'/'.$id.'/history'); self::assertResponseIsSuccessful(); self::assertSame(2,$this->body()['total']); self::assertSame($this->fixture->users['manager'],$this->body()['items'][0]['user_id']);
        $this->request('GET','accounts',suffix:'?active=false&search=001'); self::assertResponseIsSuccessful(); self::assertCount(1,$this->body()); self::assertResponseHeaderSame('x-total-count','1');
        $this->request('DELETE','accounts',suffix:'/'.$id); self::assertResponseStatusCodeSame(405);
    }
    public function testPeriodLifecycleBoundariesAndFilters(): void
    {
        $payload=['name'=>'2026','startsOn'=>'2026-01-01','endsOn'=>'2026-12-31'];
        $this->request('POST','periods',$payload); self::assertResponseStatusCodeSame(201); $id=$this->body()['id']; $tag=$this->tag(); self::assertSame('open',$this->body()['status']);
        $this->request('PATCH','periods',['name'=>'Year 2026'],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful(); self::assertSame(2,$this->body()['version']);
        $this->request('POST','periods',['name'=>'Overlap','startsOn'=>'2026-12-31','endsOn'=>'2027-01-31']); self::assertResponseStatusCodeSame(409);
        $this->request('POST','periods',['name'=>'Next','startsOn'=>'2027-01-01','endsOn'=>'2027-01-01']); self::assertResponseStatusCodeSame(201);
        $this->request('GET','periods',suffix:'?on=2026-12-31'); self::assertResponseIsSuccessful(); self::assertCount(1,$this->body()); self::assertSame($id,$this->body()[0]['id']);
        $this->request('GET','periods',suffix:'/'.$id.'/history'); self::assertSame(2,$this->body()['total']);
        $this->request('POST','periods',$payload,unit:'b',user:'foreign'); self::assertResponseStatusCodeSame(201);
    }
    public function testDuplicatesParentAndCycle(): void
    {
        $this->request('POST','accounts',['code'=>'1','name'=>'Parent']); self::assertResponseStatusCodeSame(201); $a=$this->body()['id']; $tag=$this->tag();
        $this->request('POST','accounts',['code'=>'1','name'=>'Duplicate']); self::assertResponseStatusCodeSame(409);
        $this->request('POST','accounts',['code'=>'1','name'=>'Other unit'],unit:'b',user:'foreign'); self::assertResponseStatusCodeSame(201); $foreign=$this->body()['id'];
        $this->request('POST','accounts',['code'=>'2','name'=>'Child','parentId'=>$a]); self::assertResponseStatusCodeSame(201); $b=$this->body()['id'];
        foreach ([$a,$b,$foreign] as $parent) { $this->request('PATCH','accounts',['parentId'=>$parent],'/'.$a,['HTTP_IF_MATCH'=>$tag]); self::assertResponseStatusCodeSame(409); }
        self::assertSame(2,$this->countRows('accounts')); self::assertSame(1,$this->audit('accounts',$a));
        $this->request('GET','accounts',suffix:'?page=2&itemsPerPage=1'); self::assertResponseIsSuccessful(); self::assertCount(1,$this->body()); self::assertResponseHeaderSame('x-total-count','2');
    }
    public function testInvalidInputsNeverLeaveRows(): void
    {
        foreach ([['code'=>1,'name'=>'Bad'],['code'=>'ABC','name'=>'Bad'],['code'=>'1','name'=>' '],['code'=>'1','name'=>'Bad','active'=>'false'],['code'=>'1','name'=>'Bad','parentId'=>'x'],['code'=>'1','name'=>'Bad','version'=>9]] as $payload) {
            $this->request('POST','accounts',$payload); self::assertContains($this->client->getResponse()->getStatusCode(),[400,422]);
        }
        foreach ([['name'=>'Bad','startsOn'=>'2026-02-30','endsOn'=>'2026-03-01'],['name'=>'Bad','startsOn'=>'2027-01-01','endsOn'=>'2026-01-01'],['name'=>'Bad','startsOn'=>null,'endsOn'=>'2026-01-01'],['name'=>'Bad','startsOn'=>'2026-01-01','endsOn'=>'2026-12-31','status'=>'closed']] as $payload) {
            $this->request('POST','periods',$payload); self::assertContains($this->client->getResponse()->getStatusCode(),[400,422]);
        }
        foreach (['accounts'=>['code'=>'1','name'=>'Missing key'],'periods'=>['name'=>'P','startsOn'=>'2026-01-01','endsOn'=>'2026-01-01']] as $kind=>$payload) {
            $this->request('POST',$kind,$payload,headers:['HTTP_IDEMPOTENCY_KEY'=>'']); self::assertResponseStatusCodeSame(400); self::assertSame(0,$this->countRows($kind));
        }
        foreach (['[]','{"name":','null'] as $raw) {
            $this->client->request('POST',$this->path('accounts'),server:['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects['manager']]),'HTTP_IDEMPOTENCY_KEY'=>Uuid::v7()->toRfc4122(),'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'],content:$raw);
            self::assertResponseStatusCodeSame(400);
        }
    }
    public function testRolesAndForeignObjects(): void
    {
        foreach (['accounts'=>['code'=>'1','name'=>'A'],'periods'=>['name'=>'P','startsOn'=>'2026-01-01','endsOn'=>'2026-12-31']] as $kind=>$payload) {
            $this->request('POST',$kind,$payload); self::assertResponseStatusCodeSame(201); $id=$this->body()['id']; $tag=$this->tag();
            foreach (['accountant','reader','auditor'] as $user) {
                $this->request('GET',$kind,user:$user); self::assertResponseIsSuccessful();
                $this->request('POST',$kind,$payload,user:$user); self::assertResponseStatusCodeSame(403);
                $this->request('PATCH',$kind,['name'=>'Denied'],'/'.$id,['HTTP_IF_MATCH'=>$tag],$user); self::assertResponseStatusCodeSame(403);
                $this->request('GET',$kind,suffix:'/'.$id.'/history',user:$user); self::assertResponseStatusCodeSame($user==='auditor'?200:403);
            }
            $this->request('GET',$kind,suffix:'/'.$id,user:'foreign',unit:'b'); self::assertResponseStatusCodeSame(404);
            $this->request('GET',$kind,unit:'b'); self::assertResponseStatusCodeSame(403);
        }
    }
    public function testEtagNoOpIdempotencyAndExpiry(): void
    {
        foreach (['accounts'=>['code'=>'01','name'=>'A'],'periods'=>['name'=>'P','startsOn'=>'2026-01-01','endsOn'=>'2026-12-31']] as $kind=>$payload) {
            $headers=['HTTP_IDEMPOTENCY_KEY'=>Uuid::v7()->toRfc4122()];
            $this->request('POST',$kind,$payload,headers:$headers); self::assertResponseStatusCodeSame(201); $first=$this->body(); $id=$first['id']; $tag=$this->tag();
            $this->request('POST',$kind,$payload,headers:$headers); self::assertResponseStatusCodeSame(201); self::assertSame($first,$this->body()); self::assertResponseHeaderSame('idempotency-replayed','true');
            $this->request('POST',$kind,array_replace($payload,['name'=>'Other']),headers:$headers); self::assertResponseStatusCodeSame(409);
            $this->request('PATCH',$kind,['name'=>$first['name']],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful(); self::assertSame($tag,$this->tag()); self::assertSame(1,$this->audit($kind,$id));
            $this->request('PATCH',$kind,['name'=>'New'],'/'.$id); self::assertResponseStatusCodeSame(428);
            $this->request('PATCH',$kind,['name'=>'New'],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful();
            $this->request('PATCH',$kind,['name'=>'Lost'],'/'.$id,['HTTP_IF_MATCH'=>$tag]); self::assertResponseStatusCodeSame(412); self::assertSame(2,$this->audit($kind,$id));
            $table=$kind==='accounts'?'account_create_requests':'period_create_requests';
            $this->fixture->owner->executeStatement("UPDATE ucto.$table SET expires_at=clock_timestamp()-interval '1 second' WHERE accounting_unit_id=?",[$this->fixture->units['a']]);
            $this->request('POST',$kind,$payload,headers:$headers); self::assertResponseStatusCodeSame(409); // uniqueness/overlap still enforced after expiry
            $newPayload=$kind==='accounts'?['code'=>'02','name'=>'New after expiry']:['name'=>'Next after expiry','startsOn'=>'2027-01-01','endsOn'=>'2027-12-31'];
            $this->request('POST',$kind,$newPayload,headers:$headers); self::assertResponseStatusCodeSame(201);
            self::assertNotSame($id,$this->body()['id']); self::assertResponseHeaderSame('idempotency-replayed','false');
            self::assertSame(2,$this->countRows($kind));
        }
    }
    public function testDirectSqlRlsConstraintsAndRollback(): void
    {
        $this->request('POST','accounts',['code'=>'1','name'=>'DB']); self::assertResponseStatusCodeSame(201); $id=$this->body()['id'];
        foreach (['accounts','periods'] as $kind) { self::assertSame(0,(int)$this->fixture->app->fetchOne("SELECT count(*) FROM ucto.$kind")); }
        foreach (['reader','accountant','auditor','foreign'] as $role) {
            try {
                $this->fixture->app->transactional(function(Connection $c) use($role) { $this->fixture->context($c,$role); $c->executeStatement('INSERT INTO ucto.accounts(id,accounting_unit_id,code,name) VALUES (?,?,?,?)',[Uuid::v7()->toRfc4122(),$this->fixture->units['a'],'2','Denied']); }); self::fail('Write should fail.');
            } catch (Exception $e) { self::assertSame('42501',$e->getSQLState()); }
        }
        foreach ([['parent_id'=>$id],['accounting_unit_id'=>$this->fixture->units['b']],['created_at'=>'2000-01-01']] as $values) {
            try {
                $this->fixture->app->transactional(function(Connection $c) use($id,$values) { $this->fixture->context($c); $column=array_key_first($values); $c->executeStatement("UPDATE ucto.accounts SET $column=?,version=version+1 WHERE id=?",[reset($values),$id]); }); self::fail('Update should fail.');
            } catch (Exception $e) { self::assertContains($e->getSQLState(),['23514','42501']); }
        }
        self::assertSame(1,$this->audit('accounts',$id));
        $this->fixture->app->transactional(function(Connection $c) use($id): void {
            $this->fixture->context($c,'foreign','b');
            self::assertSame(0,(int)$c->fetchOne('SELECT count(*) FROM ucto.accounts WHERE id=?',[$id]));
            self::assertSame(0,(int)$c->fetchOne('SELECT count(*) FROM core.audit_log WHERE row_id=?',[$id]));
        });
        foreach (['periods'=>"INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on,status) VALUES (?,?,?,'2026-01-01','2026-12-31','closed')",'accounts'=>"INSERT INTO ucto.accounts(id,accounting_unit_id,code,name,version) VALUES (?,?,?,'Bad version',99)"] as $sql) {
            try { $this->fixture->app->transactional(function(Connection $c) use($sql): void { $this->fixture->context($c); $c->executeStatement($sql,[Uuid::v7()->toRfc4122(),$this->fixture->units['a'],'2']); }); self::fail(); }
            catch (Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        }
        $read=\App\Tests\Support\TestDatabase::connection('read');
        try { $read->executeStatement('DELETE FROM ucto.accounts'); self::fail(); } catch (Exception $e) { self::assertContains($e->getSQLState(),['42501','25006']); } finally { $read->close(); }
    }
    public function testRepeatedMigrationPreservesAccountingSchemaAndData(): void
    {
        $this->request('POST','accounts',['code'=>'001','name'=>'Migration test']); self::assertResponseStatusCodeSame(201);
        $owner=$this->fixture->owner;
        $snapshot=static fn()=>[
            $owner->fetchAllAssociative("SELECT c.relname,c.relacl,a.attname,format_type(a.atttypid,a.atttypmod) AS type FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attribute a ON a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped WHERE n.nspname='ucto' ORDER BY c.relname,a.attnum"),
            $owner->fetchAllAssociative("SELECT conname,pg_get_constraintdef(oid) FROM pg_constraint WHERE connamespace='ucto'::regnamespace ORDER BY conname"),
            $owner->fetchAllAssociative('SELECT * FROM ucto.accounts ORDER BY id'),
            $owner->fetchAllAssociative('SELECT * FROM ucto.periods ORDER BY id'),
            $owner->fetchAllAssociative('SELECT * FROM migration.versions ORDER BY version'),
            $owner->fetchFirstColumn("SELECT to_jsonb(a)::text FROM core.audit_log a WHERE table_name LIKE 'ucto.%' ORDER BY id,occurred_at")
        ];
        $before=$snapshot();
        $factory=\App\Modules\Core\Infrastructure\Migrations\MigrationFactory::create($owner);
        $plan=$factory->getMigrationPlanCalculator()->getPlanUntilVersion($factory->getVersionAliasResolver()->resolveVersionAlias('latest'));
        self::assertCount(0,$plan);
        $factory->getMigrator()->migrate($plan,(new \Doctrine\Migrations\MigratorConfiguration())->setAllOrNothing(true));
        self::assertSame($before,$snapshot());
    }
    private function path(string $kind,string $unit='a'): string { return '/api/v1/accounting-units/'.$this->fixture->units[$unit].'/'.$kind; }
    private function body(): array { return json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR); }
    private function tag(): string { return $this->client->getResponse()->headers->get('ETag')??''; }
    private function countRows(string $kind): int { return (int)$this->fixture->owner->fetchOne("SELECT count(*) FROM ucto.$kind WHERE accounting_unit_id=?",[$this->fixture->units['a']]); }
    private function audit(string $kind,string $id): int { return (int)$this->fixture->owner->fetchOne('SELECT count(*) FROM core.audit_log WHERE table_name=? AND row_id=?',['ucto.'.$kind,$id]); }
    private function request(string $method,string $kind,?array $payload=null,string $suffix='',array $headers=[],string $user='manager',string $unit='a'): void
    {
        if ($method==='POST' && !array_key_exists('HTTP_IDEMPOTENCY_KEY',$headers)) { $headers['HTTP_IDEMPOTENCY_KEY']=Uuid::v7()->toRfc4122(); }
        $this->client->request($method,$this->path($kind,$unit).$suffix,server:array_merge(['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects[$user]]),'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>$method==='PATCH'?'application/merge-patch+json':'application/json'],$headers),content:$payload===null?null:json_encode($payload));
    }
}
