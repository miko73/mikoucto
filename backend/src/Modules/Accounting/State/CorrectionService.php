<?php
namespace App\Modules\Accounting\State;
use App\Modules\Accounting\ApiResource\Document;
use App\Modules\Accounting\Application\CorrectionInput;
use App\Modules\Accounting\Infrastructure\DocumentRepository;
use App\Modules\Core\Application\Identity\IdentitySession;
use App\Modules\Partners\Application\PartnerPreconditions;
use App\Modules\Partners\State\PartnerAccess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Uid\Uuid;
final class CorrectionService
{
    public function __construct(private PartnerAccess $access,private IdentitySession $session,private DocumentRepository $documents) { }
    public function execute(array $variables): Document
    {
        [$user,$unit,$id,$request]=$this->access->context($variables,'core.ucto.documents.write');
        foreach (['core.units.read','core.ucto.documents.read'] as $permission) { $this->access->context($variables,$permission); }
        $payload=$request->toArray();
        if (!json_decode($request->getContent()) instanceof \stdClass || $request->query->all()!==[]) { throw new BadRequestHttpException('An object and no query parameters are required.'); }
        $values=CorrectionInput::normalize($payload);
        $key=PartnerPreconditions::idempotencyKey($request->headers->get('Idempotency-Key'));
        $version=PartnerPreconditions::version($request->headers->get('If-Match'),$id);
        $hash=hash('sha256',json_encode(['sourceId'=>$id,'version'=>$version,...$values],JSON_THROW_ON_ERROR));
        try {
            $result=$this->session->run($user,$unit,function(Connection $c) use($user,$unit,$id,$request,$key,$version,$hash,$values): Document {
                $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.accounts:'.$unit]);
                $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.documents.correction-draft:'.$unit.':'.$user->id.':'.$key]);
                $stored=$c->fetchAssociative("SELECT request_hash,response,response_status,expires_at>clock_timestamp() AS fresh FROM ucto.document_correction_requests WHERE accounting_unit_id=? AND user_id=? AND operation='documents.correction-draft' AND key=?",[$unit,$user->id,$key]);
                if ($stored!==false && $stored['fresh']) {
                    if (!hash_equals($stored['request_hash'],$hash)) { throw new ConflictHttpException('Idempotency key has a different command.'); }
                    $request->attributes->set('_accounting_status',(int)$stored['response_status']);
                    $request->attributes->set('_accounting_replayed',true);
                    return Document::snapshot(json_decode($stored['response'],true,512,JSON_THROW_ON_ERROR));
                }
                $source=$this->documents->find($unit,$id)??throw new NotFoundHttpException();
                if ($source->version!==$version) { throw new PreconditionFailedHttpException(); }
                $existing=$c->fetchAssociative('SELECT correction_document_id,requested_period_id,requested_posting_date FROM ucto.document_corrections WHERE accounting_unit_id=? AND original_document_id=?',[$unit,$id]);
                if ($existing!==false && ($existing['requested_period_id']!==$values['periodId'] || $existing['requested_posting_date']!==$values['postingDate'])) {
                    throw new ConflictHttpException('Correction already exists with different requested values.',headers:['Location'=>'/api/v1/accounting-units/'.$unit.'/documents/'.$existing['correction_document_id']]);
                }
                $target=$c->fetchOne('SELECT ucto.create_correction_draft(?::uuid,?::uuid,?::integer,?::uuid,?::date)',[$unit,$id,$version,$values['periodId'],$values['postingDate']]);
                $document=$this->documents->find($unit,$target)??throw new NotFoundHttpException();
                $status=$existing===false?201:200;
                $c->executeStatement("INSERT INTO ucto.document_correction_requests(id,accounting_unit_id,user_id,operation,key,request_hash,source_id,resource_id,response,response_status)
                    VALUES (?,?,?,'documents.correction-draft',?,?,?,?,?::jsonb,?) ON CONFLICT(accounting_unit_id,user_id,operation,key)
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
            if (in_array($e->getSQLState(),['22023','22007','22008','22P02'],true)) { throw new HttpException(422,'Invalid correction input.',$e); }
            if (in_array($e->getSQLState(),['23503','23505','23514'],true)) { throw new ConflictHttpException('Correction conflicts with accounting rules.',$e); }
            throw $e;
        }
        $request->attributes->set('_accounting_etag',$result->etag());
        $request->attributes->set('_accounting_location','/api/v1/accounting-units/'.$unit.'/documents/'.$result->id);
        $request->attributes->set('_accounting_no_store',true);
        return $result;
    }
}
