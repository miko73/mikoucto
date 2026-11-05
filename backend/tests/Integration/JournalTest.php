<?php
namespace App\Tests\Integration;
use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Tests\Support\DraftFixture;
use App\Tests\Support\TestTokens;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
final class JournalTest extends WebTestCase
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
    private function get(array $query=[],string $role='reader',string $unit='a'): array
    {
        $this->client->request('GET','/api/v1/accounting-units/'.$this->fixture->units[$unit].'/journal?'.http_build_query($query),server:
            ['HTTP_AUTHORIZATION'=>'Bearer '.TestTokens::token(['oid'=>$this->fixture->objects[$role]]),'HTTP_ACCEPT'=>'application/json']);
        return json_decode($this->client->getResponse()->getContent(),true,512,JSON_THROW_ON_ERROR);
    }
    public function testJournalContainsOnlyPostedLinesWithExactAmountsAndPagination(): void
    {
        $payload=$this->fixture->payload(); $payload['lines'][0]['md']='9999999999999999.99'; $payload['lines'][1]['dal']='9999999999999999.99';
        $id=$this->fixture->save($payload); $this->fixture->save($this->fixture->payload());
        $this->fixture->app->transactional(function(Connection $c) use($id): void { $this->fixture->context($c); $c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units['a'],$id]); });
        $foreignPayload=$this->fixture->payload('b');$foreignPayload['lines'][1]['dal']='100.00';
        $foreign=$this->fixture->save($foreignPayload,role:'foreign',unit:'b');
        $this->fixture->app->transactional(function(Connection $c) use($foreign): void { $this->fixture->context($c,'foreign','b');$c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$this->fixture->units['b'],$foreign]); });
        $rows=$this->get(['itemsPerPage'=>1]); self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('X-Total-Count','2');
        self::assertCount(1,$rows); self::assertSame($id,$rows[0]['documentId']); self::assertSame('9999999999999999.99',$rows[0]['md']);
        self::assertSame('INT/20260101/000001',$rows[0]['number']); self::assertSame(1,$rows[0]['lineNo']);
        $next=$this->get(['itemsPerPage'=>1,'page'=>2]); self::assertSame(2,$next[0]['lineNo']); self::assertSame('9999999999999999.99',$next[0]['dal']);
        $filtered=$this->get(['accountId'=>$this->fixture->accounts['a']['md'],'periodId'=>$this->fixture->periods['a'],'search'=>'Draft','from'=>'2026-11-03','to'=>'2026-11-03']);
        self::assertCount(1,$filtered); self::assertResponseHeaderSame('X-Total-Count','1');
        self::assertSame([],$this->get(['search'=>'%']));
        self::assertSame([],$this->get(['periodId'=>$this->fixture->periods['b']]));
        $other=$this->get(role:'foreign',unit:'b'); self::assertResponseIsSuccessful(); self::assertCount(2,$other); self::assertSame($foreign,$other[0]['documentId']);
        $this->get(unit:'b');self::assertResponseStatusCodeSame(403);
    }
    public function testJournalPermissionsAndInvalidFilters(): void
    {
        foreach (['reader','auditor','accountant','manager'] as $role) { self::assertSame([],$this->get(role:$role)); self::assertResponseIsSuccessful(); }
        $this->get(role:'foreign'); self::assertResponseStatusCodeSame(403);
        foreach ([['accountId'=>'invalid'],['from'=>'2026-02-30'],['from'=>'2026-12-01','to'=>'2026-01-01'],['unknown'=>'x'],['itemsPerPage'=>101],['accountId'=>['x']]] as $query) {
            $this->get($query); self::assertResponseStatusCodeSame(400);
        }
    }
}
