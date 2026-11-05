<?php

namespace App\Modules\Partners\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Core\Application\Identity\IdentitySession;
use App\Modules\Partners\ApiResource\Partner;
use App\Modules\Partners\ApiResource\PartnerInput;
use App\Modules\Partners\Application\PartnerInputValidator;
use App\Modules\Partners\Application\PartnerPreconditions;
use App\Modules\Partners\Infrastructure\PartnerRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Uid\Uuid;

final class PartnerProcessor implements ProcessorInterface
{
    public function __construct(private PartnerAccess $access,private IdentitySession $session,private PartnerRepository $repository,private PartnerInputValidator $validator) { }

    public function process(mixed $data,Operation $operation,array $uriVariables=[],array $context=[]): Partner
    {
        [$user,$unit,$id,$request]=$this->access->context($uriVariables,'core.partners.write');
        if (!$data instanceof PartnerInput) { throw new BadRequestHttpException(); }
        $payload=$request->toArray();
        $present=array_keys($payload);
        if (array_diff($present,array_keys(PartnerInputValidator::FIELDS))!==[]) { throw new BadRequestHttpException(); }
        foreach ($payload as $field=>$value) {
            if ($value!==null && ($field==='active'?!is_bool($value):!is_string($value))) { throw new BadRequestHttpException(); }
        }
        $create=$operation instanceof Post;
        $key=$create?PartnerPreconditions::idempotencyKey($request->headers->get('Idempotency-Key')):null;
        $version=$create?null:PartnerPreconditions::version($request->headers->get('If-Match'),$id);
        $partner=$this->session->run($user,$unit,function (Connection $connection) use ($data,$present,$user,$unit,$id,$key,$version,$create,$request): Partner {
            if ($create) {
                $values=$this->validator->normalize($data,$present);
                $hash=hash('sha256',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                $connection->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['partners.create:'.$unit.':'.$user->id.':'.$key]);
                $stored=$connection->fetchAssociative("SELECT request_hash,response,expires_at>clock_timestamp() AS fresh FROM core.idempotency_keys WHERE accounting_unit_id=? AND user_id=? AND operation='partners.create' AND key=?",[$unit,$user->id,$key]);
                if ($stored!==false && $stored['fresh']) {
                    if (!hash_equals($stored['request_hash'],$hash)) { throw new ConflictHttpException('Idempotency key has a different request.'); }
                    $request->attributes->set('_partner_replayed',true);
                    return new Partner(...json_decode($stored['response'],true,512,JSON_THROW_ON_ERROR));
                }
                $partner=Partner::fromRow($this->repository->create($unit,Uuid::v7()->toRfc4122(),$values));
                $connection->executeStatement("INSERT INTO core.idempotency_keys (id,accounting_unit_id,user_id,operation,key,request_hash,resource_id,response) VALUES (?,?,?,'partners.create',?,?,?,?::jsonb)
                    ON CONFLICT (accounting_unit_id,user_id,operation,key) DO UPDATE SET request_hash=excluded.request_hash,resource_id=excluded.resource_id,response=excluded.response,created_at=clock_timestamp(),expires_at=clock_timestamp()+interval '24 hours'",
                    [Uuid::v7()->toRfc4122(),$unit,$user->id,$key,$hash,$partner->id,json_encode(get_object_vars($partner),JSON_THROW_ON_ERROR)]);
                $request->attributes->set('_partner_replayed',false);
                return $partner;
            }
            $current=$this->repository->find($unit,$id);
            if ($current===false) { throw new NotFoundHttpException(); }
            if ((int)$current['version']!==$version) { throw new PreconditionFailedHttpException(); }
            $values=$this->validator->normalize($data,$present,$current);
            $same=true;
            foreach ($values as $column=>$value) { if ($current[$column]!==$value) { $same=false; break; } }
            if ($same) { return Partner::fromRow($current); }
            $updated=$this->repository->update($unit,$id,$version,$values);
            if ($updated===false) { throw new PreconditionFailedHttpException(); }
            return Partner::fromRow($updated);
        },$request->attributes->get('_request_id'));
        $request->attributes->set('_partner_etag',$partner->etag());
        if ($create) { $request->attributes->set('_partner_location','/api/v1/accounting-units/'.$unit.'/partners/'.$partner->id); }
        return $partner;
    }
}
