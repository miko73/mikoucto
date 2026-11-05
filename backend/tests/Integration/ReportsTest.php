<?php
namespace App\Tests\Integration;

use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Tests\Support\DraftFixture;
use App\Tests\Support\TestTokens;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ReportsTest extends WebTestCase
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
    private function get(bool $ledger=false,array $query=[],string $role='reader',string $unit='a',bool $anonymous=false): array
    {
        $defaults=['periodId'=>$this->fixture->periods[$unit]];
        if ($ledger) { $defaults['accountId']=$this->fixture->accounts[$unit]['md']; }
        $headers=['HTTP_ACCEPT'=>'application/json'];
        if (!$anonymous) { $headers['HTTP_AUTHORIZATION']='Bearer '.TestTokens::token(['oid'=>$this->fixture->objects[$role]]); }
        $this->client->request('GET','/api/v1/accounting-units/'.$this->fixture->units[$unit].'/reports/'.($ledger?'general-ledger':'trial-balance').'?'.http_build_query(array_replace($defaults,$query)),server:$headers);
        return json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR);
    }
    private function post(string $amount,string $date='2026-11-03',string $unit='a',bool $reverse=false): string
    {
        $p=$this->fixture->payload($unit); $p['postingDate']=$date;
        $p['lines'][0]['md']=$amount; $p['lines'][1]['dal']=$amount;
        if ($reverse) { [$p['lines'][0]['accountId'],$p['lines'][1]['accountId']]=[$p['lines'][1]['accountId'],$p['lines'][0]['accountId']]; }
        $role=$unit==='a'?'manager':'foreign'; $id=$this->fixture->save($p,role:$role,unit:$unit);
        $this->fixture->app->transactional(function(Connection $c) use($id,$role,$unit): void {
            $this->fixture->context($c,$role,$unit); $c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units[$unit],$id]);
        });
        return $id;
    }
    public function testOpeningTurnoversPaginationAndIsolation(): void
    {
        $this->post('100.00','2026-11-02'); $first=$this->post('50.00'); $second=$this->post('25.00','2026-11-04');
        $this->post('999.00',unit:'b'); $this->fixture->save($this->fixture->payload());
        $q=['from'=>'2026-11-03','to'=>'2026-11-04','itemsPerPage'=>1];
        $a=$this->get(true,$q); self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('Cache-Control','no-store, private');
        self::assertSame(2,$a['total']); self::assertCount(1,$a['items']); self::assertSame($first,$a['items'][0]['documentId']);
        foreach (['closingBalance'=>'175.00','openingBalance'=>'100.00','turnoverDal'=>'0.00','turnoverMd'=>'75.00'] as $key=>$value) { self::assertSame($value,$a['totals'][$key],$key); }
        self::assertSame('150.00',$a['items'][0]['runningBalance']);
        $b=$this->get(true,$q+['page'=>2]); self::assertSame($second,$b['items'][0]['documentId']); self::assertSame('175.00',$b['items'][0]['runningBalance']); self::assertSame($a['totals'],$b['totals']);
        $emptyPage=$this->get(true,$q+['page'=>9]); self::assertSame([],$emptyPage['items']); self::assertSame(2,$emptyPage['total']); self::assertSame($a['totals'],$emptyPage['totals']);
        $boundary=$this->get(true,['from'=>'2026-11-03','to'=>'2026-11-03']); self::assertSame(1,$boundary['total']); self::assertSame('150.00',$boundary['totals']['closingBalance']);
        $trial=$this->get(query:$q); self::assertSame(2,$trial['total']); self::assertCount(1,$trial['items']); self::assertTrue($trial['totals']['balanced']);
        foreach (['openingMd'=>'100.00','openingDal'=>'100.00','turnoverMd'=>'75.00','turnoverDal'=>'75.00','closingMd'=>'175.00','closingDal'=>'175.00',
            'openingDifference'=>'0.00','turnoverDifference'=>'0.00','closingDifference'=>'0.00'] as $key=>$value) { self::assertSame($value,$trial['totals'][$key],$key); }
        $dal=$this->get(true,['accountId'=>$this->fixture->accounts['a']['dal']]+$q);
        self::assertSame('-100.00',$dal['totals']['openingBalance']); self::assertSame('-175.00',$dal['totals']['closingBalance']); self::assertSame('-150.00',$dal['items'][0]['runningBalance']);
        $whole=$this->get(true); self::assertSame('2026-01-01',$whole['filters']['from']); self::assertSame('2026-12-31',$whole['filters']['to']); self::assertSame('0.00',$whole['totals']['openingBalance']);
        self::assertSame('CZK',$whole['currency']); self::assertSame('posted_movements_within_period_no_carry_forward',$whole['basis']);
        self::assertSame('999.00',$this->get(true,role:'foreign',unit:'b')['totals']['closingBalance']);
        $this->get(unit:'b'); self::assertResponseStatusCodeSame(403);
        $this->get(query:['periodId'=>$this->fixture->periods['b']]); self::assertResponseStatusCodeSame(404);
        $this->get(true,['accountId'=>$this->fixture->accounts['b']['md']]); self::assertResponseStatusCodeSame(404);
    }
    public function testExactLargeSumsHierarchyHistoricalAccountsAndOtherPeriod(): void
    {
        $this->fixture->owner->transactional(function(Connection $c): void {
            $this->fixture->context($c);
            $c->executeStatement("UPDATE ucto.accounts SET code='001',version=version+1 WHERE id=?",[$this->fixture->accounts['a']['md']]);
        });
        $this->post('9999999999999999.99','2026-11-02'); $this->post('9999999999999999.99');
        $this->fixture->owner->transactional(function(Connection $c): void {
            $this->fixture->context($c);
            $c->executeStatement('UPDATE ucto.accounts SET active=false,postable=false,version=version+1 WHERE id=?',[$this->fixture->accounts['a']['md']]);
            $c->executeStatement('UPDATE ucto.accounts SET parent_id=?,version=version+1 WHERE id=?',[$this->fixture->accounts['a']['inactive'],$this->fixture->accounts['a']['dal']]);
        });
        $ledger=$this->get(true); self::assertResponseIsSuccessful(); self::assertSame('19999999999999999.98',$ledger['totals']['turnoverMd']); self::assertSame('19999999999999999.98',$ledger['items'][1]['runningBalance']);
        self::assertSame('001',$ledger['filters']['accountCode']);
        $trial=$this->get(); self::assertSame(2,$trial['total']); self::assertSame('19999999999999999.98',$trial['totals']['closingMd']);
        self::assertSame('001',$trial['items'][0]['accountCode']);
        $all=$this->get(query:['includeZero'=>'true']); self::assertSame(4,$all['total']); self::assertSame($trial['totals'],$all['totals']);
        $empty=$this->get(true,['accountId'=>$this->fixture->accounts['a']['inactive']]); self::assertSame(0,$empty['total']); self::assertSame('0.00',$empty['totals']['closingBalance']);
        $other=Uuid::v7()->toRfc4122();
        $this->fixture->owner->transactional(function(Connection $c) use($other): void {
            $this->fixture->context($c); $c->executeStatement("INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on) VALUES (?,?,'Other','2027-01-01','2027-12-31')",[$other,$this->fixture->units['a']]);
        });
        $outside=$this->get(true,['periodId'=>$other]); self::assertSame(0,$outside['total']); self::assertSame('0.00',$outside['totals']['openingBalance']);
        $trial=$this->get(query:['periodId'=>$other]); self::assertSame([],$trial['items']); self::assertTrue($trial['totals']['balanced']);
    }
    public function testZeroClosingStillHasTurnoversAndClosedPeriodRemainsReadable(): void
    {
        $this->post('100.00'); $this->post('100.00',reverse:true);
        $trial=$this->get(); self::assertSame(2,$trial['total']); self::assertSame('200.00',$trial['totals']['turnoverMd']); self::assertSame('0.00',$trial['totals']['closingMd']);
        foreach ($trial['items'] as $item) { self::assertSame('100.00',$item['turnoverMd']); self::assertSame('100.00',$item['turnoverDal']); }
        $ledger=$this->get(true,['itemsPerPage'=>1]); $next=$this->get(true,['itemsPerPage'=>1,'page'=>2]);
        self::assertSame('100.00',$ledger['items'][0]['runningBalance']); self::assertSame('0.00',$next['items'][0]['runningBalance']);
        $this->fixture->app->transactional(function(Connection $c): void {
            $this->fixture->context($c); $c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$this->fixture->periods['a']]);
        });
        self::assertSame($trial['totals'],$this->get()['totals']); self::assertResponseIsSuccessful();
    }
    public function testValidationAndPermissions(): void
    {
        foreach (['manager','accountant','reader','auditor'] as $role) {
            foreach ([false,true] as $ledger) { $r=$this->get($ledger,role:$role); self::assertResponseIsSuccessful(); self::assertSame(0,$r['total']); }
        }
        $this->get(anonymous:true); self::assertResponseStatusCodeSame(401);
        $this->get(role:'foreign'); self::assertResponseStatusCodeSame(403);
        foreach ([['periodId'=>''],['from'=>'2025-12-31'],['to'=>'2027-01-01'],['to'=>'2025-12-31'],['from'=>'2027-01-01'],['from'=>'2026-02-30'],['from'=>'2026-12-31','to'=>'2026-01-01'],
            ['includeZero'=>'yes'],['page'=>'100001'],['itemsPerPage'=>101],['unknown'=>'x'],['periodId'=>['x']]] as $q) {
            $error=$this->get(query:$q); self::assertResponseStatusCodeSame(400);
            self::assertSame(400,$error['status']); self::assertNotEmpty($error['violations'][0]['propertyPath']);
            self::assertSame(array_key_first($q),$error['violations'][0]['propertyPath']);
        }
        $this->get(true,['includeZero'=>'true']); self::assertResponseStatusCodeSame(400);
        $this->get(query:['periodId'=>Uuid::v7()->toRfc4122()]); self::assertResponseStatusCodeSame(404);
        $this->get(true,['accountId'=>Uuid::v7()->toRfc4122()]); self::assertResponseStatusCodeSame(404);
    }
    public function testPartialReadPermissionsCannotProduceMisleadingReports(): void
    {
        $code='report_'.bin2hex(random_bytes(8)); $c=$this->fixture->owner;
        $c->transactional(function(Connection $c) use($code): void {
            $this->fixture->context($c);
            $c->executeStatement('INSERT INTO core.roles(code,name) VALUES (?,?)',[$code,'Report test']);
            $c->executeStatement('INSERT INTO core.role_permissions(role_code,permission_code) VALUES (?,?),(?,?)',[$code,'core.ucto.documents.read',$code,'core.units.read']);
            $c->executeStatement('UPDATE core.user_roles SET role_code=?,version=version+1 WHERE user_id=? AND accounting_unit_id=?',[$code,$this->fixture->users['reader'],$this->fixture->units['a']]);
        });
        try {
            $this->get(); self::assertResponseStatusCodeSame(403);
            $c->transactional(function(Connection $c) use($code): void { $this->fixture->context($c); $c->executeStatement('INSERT INTO core.role_permissions(role_code,permission_code) VALUES (?,?)',[$code,'core.ucto.accounts.read']); });
            $this->get(true); self::assertResponseStatusCodeSame(403);
            $c->transactional(function(Connection $c) use($code): void { $this->fixture->context($c); $c->executeStatement('INSERT INTO core.role_permissions(role_code,permission_code) VALUES (?,?)',[$code,'core.ucto.periods.read']); });
            $this->get(); self::assertResponseIsSuccessful();
            $c->transactional(function(Connection $c) use($code): void { $this->fixture->context($c); $c->executeStatement('DELETE FROM core.role_permissions WHERE role_code=? AND permission_code=?',[$code,'core.units.read']); });
            $this->get(); self::assertResponseStatusCodeSame(403);
        } finally {
            $c->transactional(function(Connection $c) use($code): void {
                $this->fixture->context($c);
                $c->executeStatement("UPDATE core.user_roles SET role_code='reader',version=version+1 WHERE user_id=? AND accounting_unit_id=?",[$this->fixture->users['reader'],$this->fixture->units['a']]);
                $c->executeStatement('DELETE FROM core.role_permissions WHERE role_code=?',[$code]);
                $c->executeStatement('DELETE FROM core.roles WHERE code=?',[$code]);
            });
        }
    }
    public function testUncommittedPostingDoesNotLeakIntoCountRowsOrTotals(): void
    {
        $this->post('100.00');
        $p=$this->fixture->payload(); $p['lines'][1]['dal']='100.00'; $id=$this->fixture->save($p);
        // Use a second actual application connection; the writer remains open
        // throughout the HTTP reads, without sleeps or a timing assumption.
        $writer=\App\Tests\Support\TestDatabase::connection('app');
        try {
            $writer->beginTransaction(); $this->fixture->context($writer);
            $writer->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$id]);
            $before=$this->get(true); self::assertSame(1,$before['total']); self::assertCount(1,$before['items']); self::assertSame('100.00',$before['totals']['closingBalance']);
            $trial=$this->get(); self::assertSame('100.00',$trial['totals']['turnoverMd']); self::assertTrue($trial['totals']['balanced']);
            $writer->commit();
            $after=$this->get(true); self::assertSame(2,$after['total']); self::assertCount(2,$after['items']); self::assertSame('200.00',$after['totals']['closingBalance']);
            self::assertSame('200.00',$this->get()['totals']['turnoverMd']);
        } finally { if ($writer->isTransactionActive()) { $writer->rollBack(); } $writer->close(); }
    }
}
