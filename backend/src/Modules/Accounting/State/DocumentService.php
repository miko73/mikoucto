<?php
namespace App\Modules\Accounting\State;
use App\Modules\Accounting\ApiResource\Document;
use App\Modules\Accounting\Application\AccountingInput;
use App\Modules\Accounting\Application\DraftInput;
use App\Modules\Accounting\Infrastructure\DocumentRepository;
use App\Modules\Core\Application\Identity\IdentitySession;
use App\Modules\Core\Infrastructure\Identity\EntraSettings;
use App\Modules\Partners\Application\PartnerPreconditions;
use App\Modules\Partners\State\PartnerAccess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Uid\Uuid;

final class DocumentService
{
    public function __construct(public readonly PartnerAccess $access,public readonly IdentitySession $session,public readonly DocumentRepository $repository) { }
    public static function pagination(Request $request,bool $history=false,bool $journal=false): array
    {
        $query=$request->query->all(); $allowed=$history?['page','itemsPerPage']:['page','itemsPerPage','search','periodId','from','to'];
        if ($journal) { $allowed[]='accountId'; }
        if (array_diff(array_keys($query),$allowed)!==[]) { throw new BadRequestHttpException(); }
        foreach ($query as $value) { if (!is_string($value)) { throw new BadRequestHttpException(); } }
        $page=$query['page']??'1'; $size=$query['itemsPerPage']??'20'; $search=trim($query['search']??'');
        if (!ctype_digit($page)||!ctype_digit($size)||(int)$page<1||(int)$page>100000||(int)$size<1||(int)$size>100||mb_strlen($search)>100) { throw new BadRequestHttpException(); }
        if (isset($query['periodId']) && !EntraSettings::isGuid($query['periodId'])) { throw new BadRequestHttpException(); }
        if ($journal && isset($query['accountId']) && !EntraSettings::isGuid($query['accountId'])) { throw new BadRequestHttpException(); }
        foreach (['from','to'] as $field) { if (isset($query[$field]) && !AccountingInput::date($query[$field])) { throw new BadRequestHttpException(); } }
        if (isset($query['from'],$query['to']) && $query['from']>$query['to']) { throw new BadRequestHttpException(); }
        $result=[(int)$page,(int)$size,$search,isset($query['periodId'])?strtolower($query['periodId']):null,$query['from']??null,$query['to']??null];
        if ($journal) { $result[]=isset($query['accountId'])?strtolower($query['accountId']):null; }
        return $result;
    }
    public function write(array $variables,bool $create): Document
    {
        [$user,$unit,$id,$request]=$this->access->context($variables,'core.ucto.documents.write');
        $payload=$request->toArray();
        $shape=json_decode($request->getContent());
        if (!$shape instanceof \stdClass || (property_exists($shape,'lines') && !is_array($shape->lines))) { throw new BadRequestHttpException('An object with an array of lines is required.'); }
        foreach ($shape->lines??[] as $line) { if (!$line instanceof \stdClass) { throw new BadRequestHttpException('Lines must be objects.'); } }
        $key=$create?PartnerPreconditions::idempotencyKey($request->headers->get('Idempotency-Key')):null;
        $version=$create?null:PartnerPreconditions::version($request->headers->get('If-Match'),$id);
        try {
            $document=$this->session->run($user,$unit,function(Connection $c) use($user,$unit,$id,$request,$payload,$create,$key,$version): Document {
                $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.accounts:'.$unit]);
                if ($create) {
                    $values=DraftInput::normalize($payload);
                    $hash=hash('sha256',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                    $c->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.documents.create:'.$unit.':'.$user->id.':'.$key]);
                    $stored=$c->fetchAssociative("SELECT request_hash,response,expires_at>clock_timestamp() AS fresh FROM ucto.document_create_requests WHERE accounting_unit_id=? AND user_id=? AND operation='documents.create' AND key=?",[$unit,$user->id,$key]);
                    if ($stored!==false && $stored['fresh']) {
                        if (!hash_equals($stored['request_hash'],$hash)) { throw new ConflictHttpException('Idempotency conflict.'); }
                        $request->attributes->set('_accounting_replayed',true);
                        return Document::snapshot(json_decode($stored['response'],true,512,JSON_THROW_ON_ERROR));
                    }
                    $id=Uuid::v7()->toRfc4122();
                    $this->repository->save($unit,$id,null,$values);
                    $document=$this->repository->find($unit,$id)??throw new \LogicException('Saved draft is missing.');
                    $c->executeStatement("INSERT INTO ucto.document_create_requests(id,accounting_unit_id,user_id,operation,key,request_hash,resource_id,response) VALUES (?,?,?,'documents.create',?,?,?,?::jsonb) ON CONFLICT(accounting_unit_id,user_id,operation,key) DO UPDATE SET request_hash=excluded.request_hash,resource_id=excluded.resource_id,response=excluded.response,created_at=clock_timestamp(),expires_at=clock_timestamp()+interval '24 hours'",
                        [Uuid::v7()->toRfc4122(),$unit,$user->id,$key,$hash,$id,json_encode($document,JSON_THROW_ON_ERROR)]);
                    $request->attributes->set('_accounting_replayed',false);
                    return $document;
                }
                $current=$this->repository->find($unit,$id)??throw new NotFoundHttpException();
                if ($current->version!==$version) { throw new PreconditionFailedHttpException(); }
                $this->repository->save($unit,$id,$version,DraftInput::normalize($payload,$current->input()));
                return $this->repository->find($unit,$id)??throw new \LogicException('Saved draft is missing.');
            },$request->attributes->get('_request_id'));
        } catch(Exception $e) {
            $state=$e->getSQLState();
            if ($state==='P0012') { throw new PreconditionFailedHttpException(previous:$e); }
            if ($state==='P0014') { throw new NotFoundHttpException(previous:$e); }
            if (in_array($state,['22023','22007','22008','22P02'],true)) { throw new HttpException(422,'Invalid draft input.',$e); }
            if (in_array($state,['23503','23505','23514'],true)) { throw new ConflictHttpException('Draft constraints conflict.',$e); }
            throw $e;
        }
        $request->attributes->set('_accounting_etag',$document->etag());
        if ($create) { $request->attributes->set('_accounting_location','/api/v1/accounting-units/'.$unit.'/documents/'.$document->id); }
        return $document;
    }
}
