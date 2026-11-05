<?php

namespace App\Tests\Integration;

use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Tests\Support\PartnerFixture;
use App\Tests\Support\TestTokens;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class PartnersTest extends WebTestCase
{
    private PartnerFixture $fixture;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->fixture=new PartnerFixture();
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

    public function testCreateReadEditHistoryAndDeactivate(): void
    {
        $this->request('POST',$this->path(),['name'=>'  Firma A  ','registrationNumber'=>'12345678','email'=>'contact@example.com'],headers:['HTTP_X_REQUEST_ID'=>'partners-create-test']);
        self::assertResponseStatusCodeSame(201);
        $partner=$this->body(); $tag=$this->tag();
        self::assertSame('Firma A',$partner['name']); self::assertSame(1,$partner['version']);
        self::assertSame($this->fixture->units['a'],$partner['accountingUnitId']); self::assertSame('7',$partner['id'][14]);
        self::assertResponseHeaderSame('location',$this->path().'/'.$partner['id']);
        $this->request('GET',$this->path().'/'.$partner['id']); self::assertResponseIsSuccessful(); self::assertSame($tag,$this->tag());
        $this->request('PATCH',$this->path().'/'.$partner['id'],['city'=>'Praha','email'=>null],headers:['HTTP_IF_MATCH'=>$tag]);
        self::assertResponseIsSuccessful(); $updated=$this->body(); $newTag=$this->tag();
        self::assertSame(2,$updated['version']); self::assertNull($updated['email']); self::assertSame('Firma A',$updated['name']); self::assertNotSame($tag,$newTag);
        $this->request('GET',$this->path().'/'.$partner['id'].'/history'); self::assertResponseIsSuccessful();
        self::assertSame(2,$this->body()['total']); self::assertSame('U',$this->body()['items'][0]['operation']);
        self::assertSame($this->fixture->users['manager'],$this->body()['items'][0]['user_id']);
        self::assertSame('partners-create-test',$this->body()['items'][1]['request_id']);
        $this->request('PATCH',$this->path().'/'.$partner['id'],['active'=>false],headers:['HTTP_IF_MATCH'=>$newTag]);
        self::assertResponseIsSuccessful(); self::assertFalse($this->body()['active']);
        $this->request('GET',$this->path().'?active=false'); self::assertResponseIsSuccessful(); self::assertCount(1,$this->body());
        $this->request('DELETE',$this->path().'/'.$partner['id']); self::assertResponseStatusCodeSame(405);
    }

    public function testIdempotentCreateAndConflictingRetry(): void
    {
        $key=Uuid::v7()->toRfc4122(); $headers=['HTTP_IDEMPOTENCY_KEY'=>$key];
        $this->request('POST',$this->path(),['name'=>'Retry'],$headers); self::assertResponseStatusCodeSame(201); $first=$this->body();
        self::assertResponseHeaderSame('idempotency-replayed','false');
        $alternateCase=str_replace($this->fixture->units['a'],strtoupper($this->fixture->units['a']),$this->path());
        $this->request('POST',$alternateCase,['name'=>'Retry'],$headers); self::assertResponseStatusCodeSame(201);
        self::assertSame($first,$this->body()); self::assertResponseHeaderSame('idempotency-replayed','true');
        $this->request('POST',$this->path(),['name'=>'Different'],$headers); self::assertResponseStatusCodeSame(409);
        self::assertSame(1,$this->countPartners()); self::assertSame(1,$this->auditCount($first['id']));
        self::assertSame(1,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM core.idempotency_keys WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }

    public function testStaleAndMissingEtagNeverOverwriteOrAudit(): void
    {
        $this->request('POST',$this->path(),['name'=>'First']); self::assertResponseStatusCodeSame(201); $id=$this->body()['id']; $old=$this->tag();
        $this->request('GET',$this->path().'/'.$id); self::assertSame($old,$this->tag());
        $this->request('PATCH',$this->path().'/'.$id,['name'=>'Missing']); self::assertResponseStatusCodeSame(428);
        $this->request('PATCH',$this->path().'/'.$id,['name'=>'Second'],headers:['HTTP_IF_MATCH'=>$old]); self::assertResponseIsSuccessful(); $new=$this->tag();
        $this->request('PATCH',$this->path().'/'.$id,['name'=>'Lost update'],headers:['HTTP_IF_MATCH'=>$old]); self::assertResponseStatusCodeSame(412);
        $this->request('GET',$this->path().'/'.$id); self::assertSame('Second',$this->body()['name']); self::assertSame($new,$this->tag());
        self::assertSame(2,$this->auditCount($id));
    }

    public function testNoOpPatchKeepsVersionAndAudit(): void
    {
        $this->request('POST',$this->path(),['name'=>'Same']); self::assertResponseStatusCodeSame(201); $id=$this->body()['id']; $tag=$this->tag();
        $this->request('PATCH',$this->path().'/'.$id,['name'=>'Same'],headers:['HTTP_IF_MATCH'=>$tag]); self::assertResponseIsSuccessful();
        self::assertSame(1,$this->body()['version']); self::assertSame($tag,$this->tag()); self::assertSame(1,$this->auditCount($id));
    }

    public function testValidationOverpostingAndMissingIdempotencyLeaveNoRows(): void
    {
        foreach ([['name'=>' '],['name'=>'Bad','email'=>'wrong'],['name'=>'Bad','countryCode'=>'ZZ'],['name'=>'Bad','registrationNumber'=>'123']] as $payload) {
            $this->request('POST',$this->path(),$payload); self::assertResponseStatusCodeSame(422);
            self::assertNotEmpty($this->body()['violations']);
        }
        foreach (['id','accountingUnitId','version','createdAt'] as $field) {
            $this->request('POST',$this->path(),['name'=>'Injected',$field=>$field==='version'?99:Uuid::v7()->toRfc4122()]);
            self::assertContains($this->client->getResponse()->getStatusCode(),[400,422]);
        }
        foreach ([['name'=>123],['name'=>'Bad type','active'=>'false']] as $payload) {
            $this->request('POST',$this->path(),$payload); self::assertContains($this->client->getResponse()->getStatusCode(),[400,422]);
        }
        $this->request('POST',$this->path(),['name'=>'Missing key'],headers:['HTTP_IDEMPOTENCY_KEY'=>'']); self::assertResponseStatusCodeSame(400);
        self::assertSame(0,$this->countPartners());
        self::assertSame(0,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM core.idempotency_keys WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }

    public function testRolePermissionsAndForeignUnitIsolation(): void
    {
        $this->request('POST',$this->path(),['name'=>'Scoped']); self::assertResponseStatusCodeSame(201); $id=$this->body()['id']; $tag=$this->tag();
        foreach (['reader','auditor'] as $user) {
            $this->request('GET',$this->path().'/'.$id,user:$user); self::assertResponseIsSuccessful();
            $this->request('POST',$this->path(),['name'=>'Denied'],user:$user); self::assertResponseStatusCodeSame(403);
            $this->request('PATCH',$this->path().'/'.$id,['name'=>'Denied'],headers:['HTTP_IF_MATCH'=>$tag],user:$user); self::assertResponseStatusCodeSame(403);
        }
        $this->request('PATCH',$this->path().'/'.$id,['city'=>'Brno'],headers:['HTTP_IF_MATCH'=>$tag],user:'accountant'); self::assertResponseIsSuccessful();
        $this->request('GET',$this->path().'/'.$id.'/history',user:'accountant'); self::assertResponseStatusCodeSame(403);
        $this->request('GET',$this->path().'/'.$id.'/history',user:'auditor'); self::assertResponseIsSuccessful();
        $this->request('GET',$this->path('b').'/'.$id,user:'foreign'); self::assertResponseStatusCodeSame(404);
        $this->request('GET',$this->path('b')); self::assertResponseStatusCodeSame(403);
    }

    public function testDuplicateRegistrationRollsBackPartnerAuditAndRetryKey(): void
    {
        $payload=['name'=>'Unique','registrationNumber'=>'12345678'];
        $this->request('POST',$this->path(),$payload); self::assertResponseStatusCodeSame(201); $id=$this->body()['id'];
        $this->request('POST',$this->path(),$payload); self::assertResponseStatusCodeSame(409);
        self::assertSame(1,$this->countPartners()); self::assertSame(1,$this->auditCount($id));
        self::assertSame(1,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM core.idempotency_keys WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        $this->request('POST',$this->path('b'),$payload,user:'foreign'); self::assertResponseStatusCodeSame(201);
    }

    public function testPaginationSearchAndActiveFilter(): void
    {
        foreach ([['name'=>'Alpha'],['name'=>'Beta','active'=>false],['name'=>'100% real']] as $payload) { $this->request('POST',$this->path(),$payload); self::assertResponseStatusCodeSame(201); }
        $this->request('GET',$this->path().'?page=2&itemsPerPage=1'); self::assertResponseIsSuccessful(); self::assertCount(1,$this->body()); self::assertResponseHeaderSame('x-total-count','3');
        $this->request('GET',$this->path().'?search='.rawurlencode('%')); self::assertResponseIsSuccessful(); self::assertCount(1,$this->body()); self::assertSame('100% real',$this->body()[0]['name']);
        $this->request('GET',$this->path().'?active=true'); self::assertResponseIsSuccessful(); self::assertCount(2,$this->body());
        $this->request('GET',$this->path().'?itemsPerPage=101'); self::assertContains($this->client->getResponse()->getStatusCode(),[400,422]);
    }

    public function testExpiredIdempotencyKeyCanCreateAgain(): void
    {
        $key=Uuid::v7()->toRfc4122(); $headers=['HTTP_IDEMPOTENCY_KEY'=>$key];
        $this->request('POST',$this->path(),['name'=>'Expired'],$headers); self::assertResponseStatusCodeSame(201); $old=$this->body()['id'];
        $this->fixture->owner->executeStatement("UPDATE core.idempotency_keys SET expires_at=clock_timestamp()-interval '1 minute' WHERE accounting_unit_id=?",[$this->fixture->units['a']]);
        $this->request('POST',$this->path(),['name'=>'Expired'],$headers); self::assertResponseStatusCodeSame(201); self::assertNotSame($old,$this->body()['id']); self::assertSame(2,$this->countPartners());
    }

    public function testDatabaseRlsAndImmutableIdentityProtectDirectSql(): void
    {
        $this->request('POST',$this->path(),['name'=>'Direct SQL']); self::assertResponseStatusCodeSame(201); $id=$this->body()['id'];
        self::assertSame(0,(int)$this->fixture->app->fetchOne('SELECT count(*) FROM core.partners WHERE id=?',[$id]));
        try {
            $this->fixture->app->transactional(function (Connection $connection): void {
                $connection->fetchOne('SELECT core.set_context(?::uuid,?,?)',[$this->fixture->users['reader'],'raw-test','test']);
                $connection->fetchOne("SELECT set_config('app.accounting_unit_id',?,true)",[$this->fixture->units['a']]);
                $connection->executeStatement('INSERT INTO core.partners(id,accounting_unit_id,name) VALUES (?,?,?)',[Uuid::v7()->toRfc4122(),$this->fixture->units['a'],'Denied']);
            }); self::fail();
        } catch (Exception $exception) { self::assertSame('42501',$exception->getSQLState()); }
        try {
            $this->fixture->app->transactional(function (Connection $connection) use ($id): void {
                $connection->fetchOne('SELECT core.set_context(?::uuid,?,?)',[$this->fixture->users['manager'],'raw-test','test']);
                $connection->fetchOne("SELECT set_config('app.accounting_unit_id',?,true)",[$this->fixture->units['a']]);
                $connection->executeStatement('UPDATE core.partners SET accounting_unit_id=?,version=version+1 WHERE id=?',[$this->fixture->units['b'],$id]);
            }); self::fail();
        } catch (Exception $exception) { self::assertContains($exception->getSQLState(),['23514','42501']); }
        self::assertSame($this->fixture->units['a'],$this->fixture->owner->fetchOne('SELECT accounting_unit_id FROM core.partners WHERE id=?',[$id]));
    }

    private function path(string $unit='a'): string { return '/api/v1/accounting-units/'.$this->fixture->units[$unit].'/partners'; }
    private function body(): array { return json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR); }
    private function tag(): string { return $this->client->getResponse()->headers->get('ETag')??''; }
    private function countPartners(): int { return (int)$this->fixture->owner->fetchOne('SELECT count(*) FROM core.partners WHERE accounting_unit_id=?',[$this->fixture->units['a']]); }
    private function auditCount(string $id): int { return (int)$this->fixture->owner->fetchOne("SELECT count(*) FROM core.audit_log WHERE table_name='core.partners' AND row_id=?",[$id]); }

    private function request(string $method,string $path,?array $payload=null,array $headers=[],string $user='manager'): void
    {
        if ($method==='POST' && !array_key_exists('HTTP_IDEMPOTENCY_KEY',$headers)) { $headers['HTTP_IDEMPOTENCY_KEY']=Uuid::v7()->toRfc4122(); }
        $this->client->request($method,$path,server:array_merge(['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects[$user]]),'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>$method==='PATCH'?'application/merge-patch+json':'application/json'],$headers),content:$payload===null?null:json_encode($payload));
    }
}
