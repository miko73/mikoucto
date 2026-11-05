<?php

namespace App\Modules\Accounting\State;

use ApiPlatform\Metadata\Operation;
use App\Modules\Accounting\ApiResource\Account;
use App\Modules\Accounting\ApiResource\Period;
use App\Modules\Accounting\Application\AccountingInput;
use App\Modules\Accounting\Infrastructure\AccountingRepository;
use App\Modules\Core\Application\Identity\IdentitySession;
use App\Modules\Partners\Application\PartnerPreconditions;
use App\Modules\Partners\State\PartnerAccess;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\Uid\Uuid;

final class AccountingService
{
    public function __construct(public readonly PartnerAccess $access,public readonly IdentitySession $session,public readonly AccountingRepository $repository) { }
    public static function kind(Operation $operation): string { return $operation->getClass()===Account::class?'accounts':'periods'; }
    public static function resource(string $kind,array $row): Account|Period { return $kind==='accounts'?Account::fromRow($row):Period::fromRow($row); }
    public static function pagination(Request $request,string $kind,bool $history=false): array
    {
        $query=$request->query->all();
        $allowed=$history?['page','itemsPerPage']:['page','itemsPerPage','search',...($kind==='accounts'?['active']:['on'])];
        if (array_diff(array_keys($query),$allowed)!==[]) { throw new BadRequestHttpException(); }
        foreach ($query as $value) { if (!is_string($value)) { throw new BadRequestHttpException(); } }
        $page=$query['page']??'1'; $size=$query['itemsPerPage']??'20'; $search=trim($query['search']??'');
        if (!ctype_digit($page) || !ctype_digit($size) || (int)$page<1 || (int)$page>100000 || (int)$size<1 || (int)$size>100 || mb_strlen($search)>100) { throw new BadRequestHttpException(); }
        if (isset($query['active']) && !in_array($query['active'],['true','false'],true)) { throw new BadRequestHttpException(); }
        if (isset($query['on']) && !AccountingInput::date($query['on'])) { throw new BadRequestHttpException(); }
        return [(int)$page,(int)$size,$search,isset($query['active'])?$query['active']==='true':null,$query['on']??null];
    }
    public function write(string $kind,array $variables,bool $create): Account|Period
    {
        [$user,$unit,$id,$request]=$this->access->context($variables,'core.ucto.'.$kind.'.write');
        $payload=$request->toArray();
        if (!str_starts_with(ltrim($request->getContent()),'{')) { throw new BadRequestHttpException('An object is required.'); }
        $key=$create?PartnerPreconditions::idempotencyKey($request->headers->get('Idempotency-Key')):null;
        $version=$create?null:PartnerPreconditions::version($request->headers->get('If-Match'),$id);
        try {
            $resource=$this->session->run($user,$unit,function(Connection $connection) use ($kind,$unit,$user,$id,$request,$payload,$create,$key,$version) {
                // Same unit lock as the DB hierarchy trigger, acquired before row reads.
                if ($kind==='accounts') { $connection->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.accounts:'.$unit]); }
                if ($create) {
                    $values=AccountingInput::normalize($kind,$payload);
                    $hash=hash('sha256',json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                    $connection->fetchOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['ucto.'.$kind.'.create:'.$unit.':'.$user->id.':'.$key]);
                    $table=AccountingRepository::requests($kind);
                    $stored=$connection->fetchAssociative("SELECT request_hash,response,expires_at>clock_timestamp() AS fresh FROM $table WHERE accounting_unit_id=? AND user_id=? AND operation=? AND key=?",[$unit,$user->id,$kind.'.create',$key]);
                    if ($stored!==false && $stored['fresh']) {
                        if (!hash_equals($stored['request_hash'],$hash)) { throw new ConflictHttpException('Idempotency conflict.'); }
                        $request->attributes->set('_accounting_replayed',true);
                        $class=$kind==='accounts'?Account::class:Period::class;
                        return new $class(...json_decode($stored['response'],true,512,JSON_THROW_ON_ERROR));
                    }
                    $resource=self::resource($kind,$this->repository->create($kind,$unit,Uuid::v7()->toRfc4122(),$values));
                    $connection->executeStatement("INSERT INTO $table(id,accounting_unit_id,user_id,operation,key,request_hash,resource_id,response) VALUES (?,?,?,?,?,?,?,?::jsonb) ON CONFLICT(accounting_unit_id,user_id,operation,key) DO UPDATE SET request_hash=excluded.request_hash,resource_id=excluded.resource_id,response=excluded.response,created_at=clock_timestamp(),expires_at=clock_timestamp()+interval '24 hours'",[Uuid::v7()->toRfc4122(),$unit,$user->id,$kind.'.create',$key,$hash,$resource->id,json_encode(get_object_vars($resource),JSON_THROW_ON_ERROR)]);
                    $request->attributes->set('_accounting_replayed',false);
                    return $resource;
                }
                $current=$this->repository->find($kind,$unit,$id);
                if ($current===false) { throw new NotFoundHttpException(); }
                if ((int)$current['version']!==$version) { throw new PreconditionFailedHttpException(); }
                $values=AccountingInput::normalize($kind,$payload,$current);
                if (array_all(array_keys($values),static fn($column)=>$values[$column]===$current[$column])) { return self::resource($kind,$current); }
                $updated=$this->repository->update($kind,$unit,$id,$version,$values);
                if ($updated===false) { throw new PreconditionFailedHttpException(); }
                return self::resource($kind,$updated);
            },$request->attributes->get('_request_id'));
        } catch (DatabaseException $exception) {
            $state=$exception->getSQLState();
            // Only constraints owned by this module are domain conflicts.
            if (in_array($state,['23503','23505','23514','23P01'],true) && preg_match('/(?:accounts_|periods_|ucto_identity|ucto_version)/',$exception->getMessage())) {
                throw new ConflictHttpException('Accounting constraint conflict.',$exception);
            }
            throw $exception;
        }
        $request->attributes->set('_accounting_etag',$resource->etag());
        if ($create) { $request->attributes->set('_accounting_location','/api/v1/accounting-units/'.$unit.'/'.$kind.'/'.$resource->id); }
        return $resource;
    }
}
