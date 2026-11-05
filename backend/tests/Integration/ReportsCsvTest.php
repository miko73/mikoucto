<?php
namespace App\Tests\Integration;

use App\Modules\Accounting\Application\ReportQuery;
use App\Modules\Accounting\Infrastructure\ReportCsvWriter;
use App\Modules\Accounting\Infrastructure\ReportRepository;
use App\Modules\Accounting\State\ReportAccess;
use App\Modules\Accounting\State\ReportCsvProvider;
use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Modules\Core\Application\Identity\ApplicationUser;
use App\Modules\Core\Application\Identity\IdentitySession;
use App\Tests\Support\DraftFixture;
use App\Tests\Support\TestDatabase;
use App\Tests\Support\TestTokens;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ReportsCsvTest extends WebTestCase
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
    private function get(bool $ledger=true,array $query=[],string $role='reader',string $unit='a',bool $csv=true,bool $anonymous=false): string
    {
        $defaults=['periodId'=>$this->fixture->periods[$unit]];
        if ($ledger) { $defaults['accountId']=$this->fixture->accounts[$unit]['md']; }
        $headers=['HTTP_ACCEPT'=>$csv?'text/csv':'application/json'];
        if (!$anonymous) { $headers['HTTP_AUTHORIZATION']='Bearer '.TestTokens::token(['oid'=>$this->fixture->objects[$role]]); }
        $this->client->request('GET','/api/v1/accounting-units/'.$this->fixture->units[$unit].'/reports/'.($ledger?'general-ledger':'trial-balance').($csv?'.csv':'').'?'.http_build_query(array_replace($defaults,$query)),server:$headers);
        // BrowserKit has already consumed a StreamedResponse into its internal
        // response; sending it a second time would produce an empty body.
        return $this->client->getInternalResponse()->getContent();
    }
    private function rows(string $csv): array
    {
        self::assertStringStartsWith("\xEF\xBB\xBF",$csv); $s=fopen('php://temp','w+b');
        try { fwrite($s,substr($csv,3)); rewind($s); $rows=[]; while (($row=fgetcsv($s,0,';','"',''))!==false) { $rows[]=$row; } return $rows; }
        finally { fclose($s); }
    }
    private function data(array $rows,bool $ledger): array
    {
        $header=(new ReportCsvWriter())->header($ledger); $start=array_search($header,$rows,true); self::assertNotFalse($start);
        $data=[]; for ($i=$start+1;$i<count($rows)&&$rows[$i]!==[null];++$i) { $data[]=$rows[$i]; } return $data;
    }
    private function post(string $amount,string $date='2026-11-03',string $unit='a'): string
    {
        $p=$this->fixture->payload($unit); $p['postingDate']=$date; $p['text']="=SUM(1;2)\r\nČeský \"doklad\"";
        $p['lines'][0]['text']="Žluťoučký; \"řádek\"\r\ndalší";
        $p['lines'][0]['md']=$amount; $p['lines'][1]['dal']=$amount;
        $role=$unit==='a'?'manager':'foreign'; $id=$this->fixture->save($p,role:$role,unit:$unit);
        $this->fixture->app->transactional(function(Connection $c) use($id,$role,$unit): void {
            $this->fixture->context($c,$role,$unit); $c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units[$unit],$id]);
        }); return $id;
    }
    public function testCsvEqualsWholeJsonSelectionWithExactAmountsAndEscapedText(): void
    {
        // Force multiple actual cursor batches without seeding thousands of rows.
        self::getContainer()->set(ReportRepository::class,new ReportRepository($this->fixture->app,2));
        $this->fixture->owner->transactional(function(Connection $c): void {
            $this->fixture->context($c); $c->executeStatement("UPDATE ucto.accounts SET code='001',name='@Pokladna',version=version+1 WHERE id=?",[$this->fixture->accounts['a']['md']]);
        });
        $this->post('9999999999999999.99','2026-11-02'); $this->post('9999999999999999.99'); $this->post('0.01','2026-11-04');
        $this->post('999.00',unit:'b');
        $json=json_decode($this->get(query:['itemsPerPage'=>1],csv:false),true,512,JSON_THROW_ON_ERROR); self::assertSame(3,$json['total']); self::assertCount(1,$json['items']);
        $csv=$this->get(); self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('Content-Type','text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('Cache-Control','no-store, private'); self::assertStringContainsString('attachment;',$this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertFalse($this->fixture->app->isTransactionActive());
        $rows=$this->rows($csv); $data=$this->data($rows,true); self::assertCount(3,$data);
        self::assertSame('001',$data[0][2]); self::assertStringStartsWith("'=SUM",$data[0][3]); self::assertSame("Žluťoučký; \"řádek\"\r\ndalší",$data[0][4]);
        self::assertSame('19999999999999999,99',$data[2][7]);
        self::assertContains(['Konečný zůstatek MD − DAL',ReportCsvWriter::money($json['totals']['closingBalance'])],$rows);
        self::assertContains(['Název účtu',"'@Pokladna"],$rows); self::assertContains(['Počet datových řádků','3'],$rows);
        $trial=json_decode($this->get(false,csv:false),true,512,JSON_THROW_ON_ERROR);
        $trialRows=$this->rows($this->get(false)); $accounts=$this->data($trialRows,false); self::assertCount(2,$accounts);
        foreach ($trial['items'] as $i=>$item) { self::assertSame($item['accountCode'],$accounts[$i][0]); self::assertSame(ReportCsvWriter::money($item['closingMd']),$accounts[$i][6]); }
        self::assertContains(['CELKEM','',...array_map(ReportCsvWriter::money(...),array_map(static fn($k)=>$trial['totals'][$k],['openingMd','openingDal','turnoverMd','turnoverDal','closingMd','closingDal']))],$trialRows);
        $credit=$this->data($this->rows($this->get(query:['accountId'=>$this->fixture->accounts['a']['dal']])),true);
        self::assertSame('-19999999999999999,99',$credit[2][7]);
        $foreign=$this->data($this->rows($this->get(role:'foreign',unit:'b')),true); self::assertCount(1,$foreign); self::assertSame('999,00',$foreign[0][5]);
        $this->get(unit:'b'); self::assertResponseStatusCodeSame(403);
        $this->fixture->app->transactional(function(Connection $c): void {
            $this->fixture->context($c); $c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$this->fixture->periods['a']]);
        });
        self::assertCount(3,$this->data($this->rows($this->get()),true)); self::assertResponseIsSuccessful();
    }
    public function testEmptyExportsRolesAndInvalidFiltersUseJsonErrors(): void
    {
        foreach (['manager','accountant','reader','auditor'] as $role) {
            $rows=$this->rows($this->get(role:$role)); self::assertResponseIsSuccessful(); self::assertSame([],$this->data($rows,true)); self::assertContains(['Počet datových řádků','0'],$rows);
        }
        $trial=$this->rows($this->get(false,query:['includeZero'=>'true'])); self::assertCount(4,$this->data($trial,false));
        $this->get(anonymous:true); self::assertResponseStatusCodeSame(401);
        $this->get(role:'foreign'); self::assertResponseStatusCodeSame(403);
        foreach ([['page'=>1],['itemsPerPage'=>1],['from'=>'2025-12-31'],['to'=>'2026-02-30'],['periodId'=>['x']],['unknown'=>'x']] as $q) {
            $error=$this->get(query:$q); self::assertResponseStatusCodeSame(400); self::assertStringNotContainsString("\xEF\xBB\xBF",$error);
            self::assertSame(400,json_decode($error,true,512,JSON_THROW_ON_ERROR)['status']);
        }
        $this->get(query:['periodId'=>$this->fixture->periods['b']]); self::assertResponseStatusCodeSame(404);
        $this->get(query:['accountId'=>$this->fixture->accounts['b']['md']]); self::assertResponseStatusCodeSame(404);
    }
    public function testActualDatabaseLimitRejectsBeforeCsvAndBoundaryExportsAllRows(): void
    {
        self::getContainer()->set(ReportCsvProvider::class,new ReportCsvProvider(self::getContainer()->get(ReportAccess::class),
            self::getContainer()->get(IdentitySession::class),new ReportRepository($this->fixture->app),new ReportCsvWriter(),2));
        $this->post('1.00','2026-11-02'); $this->post('2.00'); $this->post('3.00','2026-11-04');
        $error=$this->get(); self::assertResponseStatusCodeSame(422); self::assertSame(422,json_decode($error,true,512,JSON_THROW_ON_ERROR)['status']);
        self::assertFalse($this->client->getResponse()->headers->has('Content-Disposition')); self::assertStringNotContainsString("\xEF\xBB\xBF",$error);
        self::assertFalse($this->fixture->app->isTransactionActive());
        $rows=$this->rows($this->get(query:['to'=>'2026-11-03'])); self::assertResponseIsSuccessful(); self::assertCount(2,$this->data($rows,true));
        self::assertContains(['Konečný zůstatek MD − DAL','3,00'],$rows);
    }
    public function testSnapshotSurvivesConcurrentPostingAndCursorIsClosedOnFailure(): void
    {
        $this->post('100.00'); $p=$this->fixture->payload(); $p['lines'][0]['md']='50.00'; $p['lines'][1]['dal']='50.00'; $draft=$this->fixture->save($p);
        $session=self::getContainer()->get(IdentitySession::class); $repository=new ReportRepository($this->fixture->app);
        $user=new ApplicationUser($this->fixture->users['manager'],'Test'); $unit=$this->fixture->units['a'];
        $query=ReportQuery::parse(['periodId'=>$this->fixture->periods['a'],'accountId'=>$this->fixture->accounts['a']['md']],true,export:true);
        $other=TestDatabase::connection('app'); $rows=new \ArrayObject();
        try {
            $meta=$session->run($user,$unit,fn()=> $repository->export($unit,$query,true,
                function(array $meta) use($other,$draft): void {
                    self::assertSame(1,$meta['total']);
                    $other->transactional(function(Connection $c) use($draft): void { $this->fixture->context($c); $c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$draft]); });
                },function(array $row) use($rows): void { $rows[]=$row; }),readOnlySnapshot:true);
            self::assertCount(1,$rows); self::assertSame('100.00',$meta['totals']['closingBalance']);
            $next=$session->run($user,$unit,fn()=> $repository->report($unit,$query,true)); self::assertSame(2,$next['total']); self::assertSame('150.00',$next['totals']['closingBalance']);
            try {
                $session->run($user,$unit,fn()=> $repository->export($unit,$query,true,static fn()=>null,static function(): void { throw new \RuntimeException('Writer interrupted'); }),readOnlySnapshot:true);
                self::fail('Writer error swallowed.');
            } catch (\RuntimeException $e) { self::assertSame('Writer interrupted',$e->getMessage()); }
            self::assertFalse($this->fixture->app->isTransactionActive());
            self::assertSame(0,(int)$this->fixture->app->fetchOne("SELECT count(*) FROM pg_cursors WHERE name LIKE 'report_%'"));
            $settings=$session->run($user,$unit,static fn(Connection $c)=>$c->fetchAssociative("SELECT current_setting('transaction_read_only') AS ro,current_setting('transaction_isolation') AS isolation"));
            self::assertSame('off',$settings['ro']); self::assertSame('read committed',$settings['isolation']);
        } finally { $other->close(); }
    }
}
