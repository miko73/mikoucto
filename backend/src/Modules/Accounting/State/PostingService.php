<?php
namespace App\Modules\Accounting\State;
use App\Modules\Accounting\ApiResource\Document;
use App\Modules\Accounting\ApiResource\Period;
use App\Modules\Accounting\Infrastructure\AccountingRepository;
use App\Modules\Accounting\Infrastructure\DocumentRepository;
use App\Modules\Core\Application\Identity\IdentitySession;
use App\Modules\Partners\Application\PartnerPreconditions;
use App\Modules\Partners\State\PartnerAccess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Uid\Uuid;

final class PostingService
{
    public function __construct(private PartnerAccess $access,private IdentitySession $session,private DocumentRepository $documents,private AccountingRepository $accounting) { }
    public function execute(string $command,array $variables): Document|Period
    {
        $post=$command==='post'; $operation=$post?'documents.post':'periods.close';
        [$user,$unit,$id,$request]=$this->access->context($variables,'core.ucto.'.$operation);
        $payload=$request->toArray(); $shape=json_decode($request->getContent());
        if (!$shape instanceof \stdClass || $payload!==[] || $request->query->all()!==[]) { throw new BadRequestHttpException('Commands take an empty object and no query parameters.'); }
        $key=PartnerPreconditions::idempotencyKey($request->headers->get('Idempotency-Key'));
        $version=PartnerPreconditions::version($request->headers->get('If-Match'),$id);
        $table=$post?'ucto.document_post_requests':'ucto.period_close_requests';
        try {
            $result=$this->session->run($user,$unit,function(Connection $c) use($post,$operation,$unit,$id,$user,$key,$version,$table,$request): Document|Period {
                $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.accounts:'.$unit]);
                return $this->command($c,$post,$operation,$unit,$id,$user->id,$key,$version,$table,$request);
            },$request->attributes->get('_request_id'));
        } catch(Exception $e) {
            if ($e->getSQLState()==='P0012') { throw new PreconditionFailedHttpException(previous:$e); }
            if ($e->getSQLState()==='P0014') { throw new NotFoundHttpException(previous:$e); }
            if (in_array($e->getSQLState(),['23503','23505','23514'],true)) { throw new ConflictHttpException('Posting or closing conflicts with accounting rules.',$e); }
            throw $e;
        }
        $request->attributes->set('_accounting_etag',$result->etag());
        return $result;
    }
    private function command(Connection $c,bool $post,string $operation,string $unit,string $id,string $user,string $key,int $version,string $table,\Symfony\Component\HttpFoundation\Request $request): Document|Period
    {
        $hash=hash('sha256',json_encode(['resourceId'=>$id,'version'=>$version],JSON_THROW_ON_ERROR));
        $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.'.$operation.':'.$unit.':'.$user.':'.$key]);
        $stored=$c->fetchAssociative("SELECT request_hash,response,expires_at>clock_timestamp() AS fresh FROM $table WHERE accounting_unit_id=? AND user_id=? AND operation=? AND key=?",[$unit,$user,$operation,$key]);
        if ($stored!==false && $stored['fresh']) {
            if (!hash_equals($stored['request_hash'],$hash)) { throw new ConflictHttpException('Idempotency key has a different command.'); }
            $request->attributes->set('_accounting_replayed',true);
            $data=json_decode($stored['response'],true,512,JSON_THROW_ON_ERROR);
            return $post?Document::snapshot($data):new Period(...$data);
        }
        $function=$post?'ucto.post_document':'ucto.close_period';
        $c->fetchOne("SELECT $function(?::uuid,?::uuid,?::integer)",[$unit,$id,$version]);
        if ($post) { $result=$this->documents->find($unit,$id)??throw new NotFoundHttpException(); }
        else {
            $row=$this->accounting->find('periods',$unit,$id);
            if ($row===false) { throw new NotFoundHttpException(); }
            $result=Period::fromRow($row);
        }
        $c->executeStatement("INSERT INTO $table(id,accounting_unit_id,user_id,operation,key,request_hash,resource_id,response) VALUES (?,?,?,?,?,?,?,?::jsonb)
            ON CONFLICT(accounting_unit_id,user_id,operation,key) DO UPDATE SET request_hash=excluded.request_hash,resource_id=excluded.resource_id,response=excluded.response,created_at=clock_timestamp(),expires_at=clock_timestamp()+interval '24 hours'",
            [Uuid::v7()->toRfc4122(),$unit,$user,$operation,$key,$hash,$id,json_encode($result,JSON_THROW_ON_ERROR)]);
        $request->attributes->set('_accounting_replayed',false);
        return $result;
    }
}
