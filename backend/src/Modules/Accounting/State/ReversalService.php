<?php
namespace App\Modules\Accounting\State;
use App\Modules\Accounting\ApiResource\Document;
use App\Modules\Accounting\Application\ReversalInput;
use App\Modules\Accounting\Infrastructure\DocumentRepository;
use App\Modules\Core\Application\Identity\IdentitySession;
use App\Modules\Partners\Application\PartnerPreconditions;
use App\Modules\Partners\State\PartnerAccess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Uid\Uuid;

final class ReversalService
{
    public function __construct(private PartnerAccess $access,private IdentitySession $session,private DocumentRepository $documents) { }
    public function execute(array $variables): Document
    {
        [$user,$unit,$id,$request]=$this->access->context($variables,'core.ucto.documents.reverse');
        foreach (['core.units.read','core.ucto.documents.read','core.ucto.documents.write','core.ucto.documents.post'] as $permission) { $this->access->context($variables,$permission); }
        $payload=$request->toArray();
        if (!json_decode($request->getContent()) instanceof \stdClass || $request->query->all()!==[]) { throw new BadRequestHttpException('An object and no query parameters are required.'); }
        $values=ReversalInput::normalize($payload);
        $key=PartnerPreconditions::idempotencyKey($request->headers->get('Idempotency-Key'));
        $version=PartnerPreconditions::version($request->headers->get('If-Match'),$id);
        $hash=hash('sha256',json_encode(['sourceId'=>$id,'version'=>$version,...$values],JSON_THROW_ON_ERROR));
        try {
            $result=$this->session->run($user,$unit,function(Connection $c) use($user,$unit,$id,$request,$key,$version,$hash,$values): Document {
                $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.accounts:'.$unit]);
                $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.documents.reverse:'.$unit.':'.$user->id.':'.$key]);
                $stored=$c->fetchAssociative("SELECT request_hash,response,response_status,expires_at>clock_timestamp() AS fresh FROM ucto.document_reverse_requests WHERE accounting_unit_id=? AND user_id=? AND operation='documents.reverse' AND key=?",[$unit,$user->id,$key]);
                if ($stored!==false && $stored['fresh']) {
                    if (!hash_equals($stored['request_hash'],$hash)) { throw new ConflictHttpException('Idempotency key has a different command.'); }
                    $request->attributes->set('_accounting_status',(int)$stored['response_status']);
                    $request->attributes->set('_accounting_replayed',true);
                    return Document::snapshot(json_decode($stored['response'],true,512,JSON_THROW_ON_ERROR));
                }
                $source=$this->documents->find($unit,$id)??throw new NotFoundHttpException();
                if ($source->version!==$version) { throw new PreconditionFailedHttpException(); }
                $relations=$this->documents->relations($unit,$id);
                $existing=$relations->reversal;
                if ($existing!==null && ($existing->periodId!==$values['periodId'] || $existing->postingDate!==$values['postingDate'] || $existing->reason!==$values['reason'])) {
                    throw new ConflictHttpException('Source already reversed with different values.',headers:['Location'=>'/api/v1/accounting-units/'.$unit.'/documents/'.$existing->id]);
                }
                $target=$c->fetchOne('SELECT ucto.reverse_document(?::uuid,?::uuid,?::integer,?::uuid,?::date,?::text)',[$unit,$id,$version,$values['periodId'],$values['postingDate'],$values['reason']]);
                $document=$this->documents->find($unit,$target)??throw new NotFoundHttpException();
                $status=$existing===null?201:200;
                $c->executeStatement("INSERT INTO ucto.document_reverse_requests(id,accounting_unit_id,user_id,operation,key,request_hash,source_id,resource_id,response,response_status)
                    VALUES (?,?,?,'documents.reverse',?,?,?,?,?::jsonb,?) ON CONFLICT(accounting_unit_id,user_id,operation,key)
                    DO UPDATE SET request_hash=excluded.request_hash,source_id=excluded.source_id,resource_id=excluded.resource_id,response=excluded.response,
                    response_status=excluded.response_status,created_at=clock_timestamp(),expires_at=clock_timestamp()+interval '24 hours'",
                    [Uuid::v7()->toRfc4122(),$unit,$user->id,$key,$hash,$id,$target,json_encode($document,JSON_THROW_ON_ERROR),$status]);
                $request->attributes->set('_accounting_status',$status);
                $request->attributes->set('_accounting_replayed',false);
                return $document;
            },$request->attributes->get('_request_id'));
        } catch(Exception $e) {
            if ($e->getSQLState()==='P0012') { throw new PreconditionFailedHttpException(previous:$e); }
            if ($e->getSQLState()==='P0014') { throw new NotFoundHttpException(previous:$e); }
            if ($e->getSQLState()==='42501') { throw new AccessDeniedHttpException(previous:$e); }
            if (in_array($e->getSQLState(),['23503','23505','23514'],true)) { throw new ConflictHttpException('Reversal conflicts with accounting rules.',$e); }
            throw $e;
        }
        $request->attributes->set('_accounting_etag',$result->etag());
        $request->attributes->set('_accounting_location','/api/v1/accounting-units/'.$unit.'/documents/'.$result->id);
        $request->attributes->set('_accounting_no_store',true);
        return $result;
    }
}
