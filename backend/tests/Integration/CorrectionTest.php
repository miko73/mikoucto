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
final class CorrectionTest extends WebTestCase
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
    private function sql(callable $callback,string $role='manager',string $unit='a'): mixed
    {
        return $this->fixture->app->transactional(function(Connection $c) use($callback,$role,$unit) { $this->fixture->context($c,$role,$unit); return $callback($c); });
    }
    private function posted(string $amount='100.00',string $unit='a'): string
    {
        $p=$this->fixture->payload($unit); $p['lines'][0]['md']=$amount; $p['lines'][1]['dal']=$amount;
        $role=$unit==='a'?'manager':'foreign'; $id=$this->fixture->save($p,role:$role,unit:$unit);
        $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units[$unit],$id]),$role,$unit); return $id;
    }
    private function reversed(string $amount='100.00'): array
    {
        $id=$this->posted($amount);
        $inverse=$this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.reverse_document(?::uuid,?::uuid,2,?::uuid,?::date,?)',[$this->fixture->units['a'],$id,$this->fixture->periods['a'],'2026-11-04','Oprava částky']));
        return [$id,$inverse];
    }
    private function request(string $method,string $suffix,?array $payload=null,string $role='manager',string $unit='a',array $headers=[]): array
    {
        $this->client->request($method,'/api/v1/accounting-units/'.$this->fixture->units[$unit].'/'.$suffix,
            server:array_replace(['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects[$role]]),'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'],$headers),
            content:$payload===null?null:json_encode($payload,JSON_THROW_ON_ERROR));
        return json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR);
    }
    private function correction(string $id,array $changes=[],?string $key=null,string $role='manager',array $headers=[]): array
    {
        return $this->request('POST','documents/'.$id.'/correction-draft',array_replace(['periodId'=>$this->fixture->periods['a'],'postingDate'=>'2026-11-05'],$changes),$role,
            headers:array_replace(['HTTP_IF_MATCH'=>'"'.$id.':2"','HTTP_IDEMPOTENCY_KEY'=>$key??Uuid::v7()->toRfc4122()],$headers));
    }
    private function patch(array $document,array $changes): array
    {
        return $this->request('PATCH','documents/'.$document['id'],$changes,headers:['HTTP_IF_MATCH'=>'"'.$document['id'].':'.$document['version'].'"']);
    }
    private function post(array $document): array
    {
        // Command endpoints require a JSON object, not an array.
        $this->client->request('POST','/api/v1/accounting-units/'.$this->fixture->units['a'].'/documents/'.$document['id'].'/post',
            server:['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects['manager']]),'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json',
                'HTTP_IF_MATCH'=>'"'.$document['id'].':'.$document['version'].'"','HTTP_IDEMPOTENCY_KEY'=>Uuid::v7()->toRfc4122()],content:'{}');
        return json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR);
    }
    private function trial(string $period): array
    {
        $report=$this->request('GET','reports/trial-balance?periodId='.$period,role:'reader'); self::assertResponseIsSuccessful(); return $report['totals'];
    }
    private function csv(string $period): string
    {
        $this->client->request('GET','/api/v1/accounting-units/'.$this->fixture->units['a'].'/reports/trial-balance.csv?periodId='.$period,
            server:['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects['reader']]),'HTTP_ACCEPT'=>'text/csv']);
        self::assertResponseIsSuccessful(); return $this->client->getInternalResponse()->getContent();
    }
    public function testExactCopyKeepsBothPostedDocumentsAndExposesAuditedThreeWayRelations(): void
    {
        [$id,$inverse]=$this->reversed('9999999999999999.99');
        $source=$this->request('GET','documents/'.$id); $inverseBefore=$this->request('GET','documents/'.$inverse);
        $draft=$this->correction($id,role:'accountant'); self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('ETag','"'.$draft['id'].':1"'); self::assertResponseHeaderSame('Idempotency-Replayed','false');
        self::assertResponseHeaderSame('Location','/api/v1/accounting-units/'.$this->fixture->units['a'].'/documents/'.$draft['id']);
        self::assertSame('draft',$draft['status']); self::assertNull($draft['number']); self::assertNull($draft['postedBy']);
        foreach (['text','partnerId','currency','totalMd','totalDal'] as $field) { self::assertSame($source[$field],$draft[$field]); }
        foreach ($source['lines'] as $i=>$line) {
            self::assertNotSame($line['id'],$draft['lines'][$i]['id']);
            foreach (['lineNo','accountId','text','md','dal'] as $field) { self::assertSame($line[$field],$draft['lines'][$i][$field]); }
        }
        self::assertSame($source,$this->request('GET','documents/'.$id)); self::assertResponseHeaderSame('ETag','"'.$id.':2"');
        self::assertSame($inverseBefore,$this->request('GET','documents/'.$inverse)); self::assertResponseHeaderSame('ETag','"'.$inverse.':2"');
        foreach ([$id,$inverse] as $document) {
            $relations=$this->request('GET','documents/'.$document.'/relations',role:'reader'); self::assertResponseIsSuccessful();
            self::assertSame($draft['id'],$relations['correction']['id']); self::assertNull($relations['correction']['number']);
            self::assertSame('draft',$relations['correction']['status']); self::assertSame($this->fixture->users['accountant'],$relations['correction']['createdBy']);
            self::assertResponseHeaderSame('Cache-Control','no-store, private'); self::assertFalse($this->client->getResponse()->headers->has('ETag'));
        }
        $relations=$this->request('GET','documents/'.$draft['id'].'/relations',role:'auditor');
        self::assertSame($id,$relations['corrects']['id']); self::assertSame($inverse,$relations['reversal']['id']); self::assertNull($relations['correction']);
        foreach ([$id,$inverse,$draft['id']] as $document) {
            $history=$this->request('GET','documents/'.$document.'/history');
            $events=array_values(array_filter($history['items'],static fn($row)=>$row['table_name']==='ucto.document_corrections'));
            self::assertCount(1,$events); self::assertSame($draft['id'],$events[0]['new_data']['correction_document_id']);
        }
        self::assertSame(2,(int)$this->fixture->owner->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
    #[DataProvider('periods')]
    public function testEditableUnbalancedDraftBlocksClosingAndOnlySuccessfulPostingChangesReports(bool $nextPeriod): void
    {
        $id=$this->posted(); $period=$this->fixture->periods['a'];
        if ($nextPeriod) {
            $period=Uuid::v7()->toRfc4122();
            $this->fixture->owner->transactional(function(Connection $c) use($period): void {
                $this->fixture->context($c); $c->executeStatement("INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on) VALUES (?,?,'2027','2027-01-01','2027-12-31')",[$period,$this->fixture->units['a']]);
            });
        }
        $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.reverse_document(?::uuid,?::uuid,2,?::uuid,?::date,?)',[$this->fixture->units['a'],$id,$period,$nextPeriod?'2027-01-04':'2026-11-04','Oprava částky']));
        if ($nextPeriod) { $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$this->fixture->periods['a']])); }
        $before=$this->trial($period);
        $draft=$this->correction($id,['periodId'=>$period,'postingDate'=>$nextPeriod?'2027-01-05':'2026-11-05']); self::assertResponseStatusCodeSame(201);
        self::assertSame($before,$this->trial($period));
        // Change partner, text and amounts through ordinary PATCH.
        $lines=[['accountId'=>$this->fixture->accounts['a']['md'],'text'=>'Opravené MD','md'=>'80.00','dal'=>'0.00'],
            ['accountId'=>$this->fixture->accounts['a']['dal'],'text'=>'Opravené DAL','md'=>'0.00','dal'=>'79.00']];
        $edited=$this->patch($draft,['text'=>'Opravený doklad','partnerId'=>null,'lines'=>$lines]); self::assertResponseIsSuccessful();
        self::assertFalse($edited['balanced']); self::assertSame('Opravený doklad',$edited['text']); self::assertNull($edited['partnerId']);
        $this->patch($draft,['text'=>'Stale edit']); self::assertResponseStatusCodeSame(412);
        $this->patch($edited,['correctionId'=>$id]); self::assertResponseStatusCodeSame(400);
        $this->request('POST','documents/'.$edited['id'].'/post',['originalDocumentId'=>$id],headers:['HTTP_IF_MATCH'=>'"'.$edited['id'].':'.$edited['version'].'"','HTTP_IDEMPOTENCY_KEY'=>Uuid::v7()->toRfc4122()]); self::assertResponseStatusCodeSame(400);
        $this->post($edited); self::assertResponseStatusCodeSame(409); self::assertSame($before,$this->trial($period));
        self::assertStringContainsString($nextPeriod?';100,00;100,00;100,00;100,00':';200,00;200,00;0,00;0,00',$this->csv($period));
        try { $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$period])); self::fail('Draft did not block closing.'); }
        catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        $lines[1]['dal']='80.00'; $ready=$this->patch($edited,['lines'=>$lines]); self::assertResponseIsSuccessful();
        $posted=$this->post($ready); self::assertResponseIsSuccessful(); self::assertSame('posted',$posted['status']); self::assertNotNull($posted['number']);
        $after=$this->trial($period); self::assertSame($nextPeriod?'180.00':'280.00',$after['turnoverMd']); self::assertSame($after['turnoverMd'],$after['turnoverDal']);
        self::assertSame($nextPeriod?'20.00':'80.00',$after['closingMd']); self::assertTrue($after['balanced']);
        self::assertStringContainsString($nextPeriod?';180,00;180,00;20,00;20,00':';280,00;280,00;80,00;80,00',$this->csv($period));
        $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$period]));
        $relations=$this->request('GET','documents/'.$id.'/relations'); self::assertSame($posted['number'],$relations['correction']['number']); self::assertSame('posted',$relations['correction']['status']);
        $this->request('POST','documents/'.$posted['id'].'/reverse',['periodId'=>$period,'postingDate'=>$posted['postingDate'],'reason'=>'Again'],headers:['HTTP_IF_MATCH'=>'"'.$posted['id'].':'.$posted['version'].'"','HTTP_IDEMPOTENCY_KEY'=>Uuid::v7()->toRfc4122()]); self::assertResponseStatusCodeSame(409);
        $this->correction($posted['id'],headers:['HTTP_IF_MATCH'=>'"'.$posted['id'].':'.$posted['version'].'"']); self::assertResponseStatusCodeSame(409);
        if ($nextPeriod) { self::assertSame('100.00',$this->trial($this->fixture->periods['a'])['turnoverMd']); }
    }
    public static function periods(): iterable { yield 'same period'=>[false]; yield 'closed original period'=>[true]; }
    public function testIdempotencySurvivesEditingPostingExpiryOtherUsersAndClosedPeriod(): void
    {
        [$id]=$this->reversed(); $key=Uuid::v7()->toRfc4122();
        $draft=$this->correction($id,key:$key); self::assertResponseStatusCodeSame(201);
        $edited=$this->patch($draft,['postingDate'=>'2026-11-06','text'=>'Edited']); self::assertResponseIsSuccessful();
        self::assertSame($draft,$this->correction($id,key:$key)); self::assertResponseStatusCodeSame(201); self::assertResponseHeaderSame('Idempotency-Replayed','true');
        self::assertSame($edited,$this->correction($id,role:'accountant')); self::assertResponseStatusCodeSame(200);
        $this->correction($id,['postingDate'=>'2026-11-06']); self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Location','/api/v1/accounting-units/'.$this->fixture->units['a'].'/documents/'.$draft['id']);
        $this->correction($id,['postingDate'=>'2026-11-06'],$key); self::assertResponseStatusCodeSame(409);
        $this->correction($id,headers:['HTTP_IF_MATCH'=>'"'.$id.':1"']); self::assertResponseStatusCodeSame(412);
        $posted=$this->post($edited); self::assertResponseIsSuccessful();
        $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$this->fixture->periods['a']]));
        $this->fixture->owner->executeStatement("UPDATE ucto.document_correction_requests SET expires_at=clock_timestamp()-interval '1 second' WHERE source_id=?",[$id]);
        self::assertSame($posted,$this->correction($id,key:$key)); self::assertResponseStatusCodeSame(200); self::assertResponseHeaderSame('Idempotency-Replayed','false');
        self::assertSame($posted,$this->correction($id,key:$key)); self::assertResponseStatusCodeSame(200); self::assertResponseHeaderSame('Idempotency-Replayed','true');
        self::assertSame(1,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_corrections WHERE original_document_id=?',[$id]));
        self::assertSame(3,(int)$this->fixture->owner->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
    public function testRolesUnitsInputAndStateAreEnforcedBeforeReplay(): void
    {
        $id=$this->posted(); $this->correction($id); self::assertResponseStatusCodeSame(409);
        [$id,$inverse]=$this->reversed();
        foreach (['reader','auditor'] as $role) {
            $this->correction($id,role:$role); self::assertResponseStatusCodeSame(403);
            try { $this->sql(fn(Connection $c)=>$c->fetchOne('SELECT ucto.create_correction_draft(?::uuid,?::uuid,2,?::uuid,?::date)',[$this->fixture->units['a'],$id,$this->fixture->periods['a'],'2026-11-05']),$role); self::fail(); }
            catch(Exception $e) { self::assertSame('42501',$e->getSQLState()); }
        }
        $this->correction($inverse); self::assertResponseStatusCodeSame(409);
        $this->correction($id,headers:['HTTP_IF_MATCH'=>'']); self::assertResponseStatusCodeSame(428);
        $this->correction($id,headers:['HTTP_IDEMPOTENCY_KEY'=>'']); self::assertResponseStatusCodeSame(400);
        $this->request('POST','documents/'.$id.'/correction-draft?extra=1',['periodId'=>$this->fixture->periods['a'],'postingDate'=>'2026-11-05']); self::assertResponseStatusCodeSame(400);
        foreach ([['periodId'=>null],['postingDate'=>'2026-02-30'],['lines'=>[]],['correctionId'=>'x']] as $values) { $this->correction($id,$values); self::assertResponseStatusCodeSame(422); }
        foreach ([['periodId'=>$this->fixture->periods['b']],['postingDate'=>'2026-11-02'],['postingDate'=>'2027-01-01']] as $values) { $this->correction($id,$values); self::assertResponseStatusCodeSame(409); }
        $foreign=$this->posted(unit:'b'); $this->correction($foreign); self::assertResponseStatusCodeSame(404);
        $this->request('GET','documents/'.$id.'/relations',unit:'b'); self::assertResponseStatusCodeSame(403);
        $this->sql(fn(Connection $c)=>$c->executeStatement('UPDATE ucto.accounts SET active=false,version=version+1 WHERE id=?',[$this->fixture->accounts['a']['md']]));
        $this->correction($id); self::assertResponseStatusCodeSame(409);
        $this->sql(fn(Connection $c)=>$c->executeStatement('UPDATE ucto.accounts SET active=true,version=version+1 WHERE id=?',[$this->fixture->accounts['a']['md']]));
        foreach (['correctionId','originalDocumentId','reversalId'] as $field) {
            $this->request('POST','documents',$this->fixture->payload()+[$field=>$id],headers:['HTTP_IDEMPOTENCY_KEY'=>Uuid::v7()->toRfc4122()]); self::assertResponseStatusCodeSame(400);
        }
        $key=Uuid::v7()->toRfc4122(); $this->correction($id,key:$key); self::assertResponseStatusCodeSame(201);
        $this->fixture->owner->transactional(function(Connection $c): void {
            $this->fixture->context($c); $c->executeStatement("UPDATE core.user_roles SET role_code='reader' WHERE user_id=? AND accounting_unit_id=?",[$this->fixture->users['manager'],$this->fixture->units['a']]);
        });
        $this->correction($id,key:$key); self::assertResponseStatusCodeSame(403);
    }
    public function testWriterCanPrepareWithoutPermissionToPostOrReverse(): void
    {
        [$id]=$this->reversed(); $role='correction_'.bin2hex(random_bytes(8)); $c=$this->fixture->owner;
        $c->transactional(function(Connection $c) use($role): void {
            $this->fixture->context($c); $c->executeStatement('INSERT INTO core.roles(code,name) VALUES (?,?)',[$role,'Prepare corrections']);
            foreach (['core.units.read','core.ucto.documents.read','core.ucto.documents.write'] as $permission) { $c->executeStatement('INSERT INTO core.role_permissions(role_code,permission_code) VALUES (?,?)',[$role,$permission]); }
            $c->executeStatement('UPDATE core.user_roles SET role_code=? WHERE user_id=? AND accounting_unit_id=?',[$role,$this->fixture->users['accountant'],$this->fixture->units['a']]);
        });
        try { $draft=$this->correction($id,role:'accountant'); self::assertResponseStatusCodeSame(201); self::assertSame('draft',$draft['status']); }
        finally {
            $c->transactional(function(Connection $c) use($role): void {
                $this->fixture->context($c); $c->executeStatement("UPDATE core.user_roles SET role_code='accountant' WHERE role_code=?",[$role]);
                $c->executeStatement('DELETE FROM core.role_permissions WHERE role_code=?',[$role]); $c->executeStatement('DELETE FROM core.roles WHERE code=?',[$role]);
            });
        }
    }
    public function testLateFailureRollsBackDraftRelationAuditAndRequest(): void
    {
        [$id]=$this->reversed(); $c=$this->fixture->owner; $unit=$this->fixture->units['a']; $key=Uuid::v7()->toRfc4122();
        $before=(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE new_data->>'accounting_unit_id'=?",[$unit]);
        $c->executeStatement("CREATE FUNCTION ucto._test_correction_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Injected correction failure' USING ERRCODE='22012'; END \$\$");
        $c->executeStatement("CREATE TRIGGER zzz_test_correction_failure AFTER INSERT ON ucto.document_correction_requests FOR EACH ROW WHEN (NEW.source_id='$id'::uuid) EXECUTE FUNCTION ucto._test_correction_failure()");
        try { $this->correction($id,key:$key); self::assertResponseStatusCodeSame(503); }
        finally { $c->executeStatement('DROP TRIGGER zzz_test_correction_failure ON ucto.document_correction_requests'); $c->executeStatement('DROP FUNCTION ucto._test_correction_failure()'); }
        self::assertSame(2,(int)$c->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$unit]));
        self::assertSame(4,(int)$c->fetchOne('SELECT count(*) FROM ucto.document_lines WHERE accounting_unit_id=?',[$unit]));
        foreach (['document_corrections','document_correction_requests'] as $table) { self::assertSame(0,(int)$c->fetchOne("SELECT count(*) FROM ucto.$table WHERE accounting_unit_id=?",[$unit])); }
        self::assertSame($before,(int)$c->fetchOne("SELECT count(*) FROM core.audit_log WHERE new_data->>'accounting_unit_id'=?",[$unit]));
        self::assertSame(2,(int)$c->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$unit]));
        $this->correction($id,key:$key); self::assertResponseStatusCodeSame(201);
    }
    public function testSqlGuardsRelationDateIsolationAndCorrectionSources(): void
    {
        [$id]=$this->reversed(); $draft=$this->correction($id); self::assertResponseStatusCodeSame(201); $c=$this->fixture->owner;
        self::assertFalse((bool)$c->fetchOne("SELECT has_function_privilege('mikoucto_read','ucto.create_correction_draft(uuid,uuid,integer,uuid,date)','EXECUTE')"));
        foreach (['UPDATE ucto.document_corrections SET requested_posting_date=requested_posting_date WHERE original_document_id=?','DELETE FROM ucto.document_corrections WHERE original_document_id=?'] as $sql) {
            try { $this->sql(fn(Connection $db)=>$db->executeStatement($sql,[$id])); self::fail('Application changed a relation.'); }
            catch(Exception $e) { self::assertSame('42501',$e->getSQLState()); }
        }
        try { $c->transactional(function(Connection $db) use($id): void { $this->fixture->context($db); $db->executeStatement('UPDATE ucto.document_corrections SET created_at=created_at WHERE original_document_id=?',[$id]); }); self::fail(); }
        catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        $this->patch($draft,['postingDate'=>'2026-11-02']); self::assertResponseStatusCodeSame(409);
        try { $this->fixture->app->transactional(function(Connection $db) use($id): void {
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $this->fixture->context($db);
            $db->fetchOne('SELECT ucto.create_correction_draft(?::uuid,?::uuid,2,?::uuid,?::date)',[$this->fixture->units['a'],$id,$this->fixture->periods['a'],'2026-11-05']);
        }); self::fail(); } catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        $posted=$this->post($draft); self::assertResponseIsSuccessful();
        try { $this->sql(fn(Connection $db)=>$db->fetchOne('SELECT ucto.reverse_document(?::uuid,?::uuid,?::integer,?::uuid,?::date,?)',[$this->fixture->units['a'],$posted['id'],$posted['version'],$this->fixture->periods['a'],'2026-11-06','Again'])); self::fail(); }
        catch(Exception $e) { self::assertSame('23514',$e->getSQLState()); }
        self::assertSame(3,(int)$c->fetchOne('SELECT count(*) FROM ucto.documents WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
        self::assertSame(3,(int)$c->fetchOne('SELECT last_number FROM ucto.document_sequences WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
    public function testSqlRejectsForgedCopyAndMismatchedReversal(): void
    {
        [$id,$inverse]=$this->reversed(); [, $otherInverse]=$this->reversed();
        $payload=$this->fixture->payload(); $payload['postingDate']='2026-11-05'; $payload['lines'][1]['dal']='100.00';
        $copy=$this->fixture->save($payload); $payload['lines'][0]['md']='80.00'; $payload['lines'][1]['dal']='80.00'; $wrong=$this->fixture->save($payload);
        foreach ([[$wrong,$inverse,'23514'],[$copy,$otherInverse,'23503']] as [$target,$reversal,$state]) {
            try {
                $this->fixture->owner->transactional(function(Connection $c) use($id,$target,$reversal): void {
                    $this->fixture->context($c);
                    $c->executeStatement('INSERT INTO ucto.document_corrections(id,accounting_unit_id,original_document_id,reversal_document_id,correction_document_id,requested_period_id,requested_posting_date,created_by) VALUES (?,?,?,?,?,?,?,?)',
                        [Uuid::v7()->toRfc4122(),$this->fixture->units['a'],$id,$reversal,$target,$this->fixture->periods['a'],'2026-11-05',$this->fixture->users['manager']]);
                }); self::fail('SQL accepted a forged relation.');
            } catch(Exception $e) { self::assertSame($state,$e->getSQLState()); }
        }
        self::assertSame(0,(int)$this->fixture->owner->fetchOne('SELECT count(*) FROM ucto.document_corrections WHERE accounting_unit_id=?',[$this->fixture->units['a']]));
    }
}
