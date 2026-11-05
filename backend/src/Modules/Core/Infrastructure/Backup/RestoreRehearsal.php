<?php
namespace App\Modules\Core\Infrastructure\Backup;
use App\Modules\Core\Application\Identity\ApplicationUser;
use App\Modules\Core\Application\Identity\IdentitySession;
use App\Modules\Core\Infrastructure\Identity\ApplicationRoleGuard;
use App\Modules\Core\Infrastructure\Identity\IdentityProvisioner;
use App\Modules\Accounting\Infrastructure\DocumentRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\Uid\Uuid;
final class RestoreRehearsal
{
    public static function migrate(Connection $owner,string $name,string $id,array $source): array
    {
        $result=DevelopmentDeployment::rehearse($owner,$source,$name,$id);
        return [...$result['migration'],'outerTransactionRehearsed'=>true,'testUnitPrepared'=>$result['testUnit']['ready']];
    }
    public static function smoke(Connection $owner,Connection $app,string $name,string $id): array
    {
        BackupGuard::clone($owner,$name,$id);
        if ($app->fetchOne('SELECT current_database()')!==$name) { throw new \RuntimeException('App smoke target mismatch.'); }
        $identity=IdentityProvisioner::provision($owner,['tenant'=>Uuid::v7()->toRfc4122(),'object'=>Uuid::v7()->toRfc4122(),'display_name'=>'Restore rehearsal',
            'unit_code'=>'RESTORE_'.strtoupper(substr($id,-12)),'unit_name'=>'Isolated restore rehearsal','role'=>'administrator']);
        $unit=$identity['accounting_unit_id'];$user=new ApplicationUser($identity['user_id'],'Restore rehearsal');
        $session=new IdentitySession($app,new ApplicationRoleGuard($app));
        $run=static fn(callable $operation)=>$session->run($user,$unit,$operation,'restore-rehearsal');
        $period=Uuid::v7()->toRfc4122();$md=Uuid::v7()->toRfc4122();$dal=Uuid::v7()->toRfc4122();
        $run(static function(Connection $c) use($unit,$period,$md,$dal): void {
            $c->executeStatement("INSERT INTO ucto.periods(id,accounting_unit_id,name,starts_on,ends_on) VALUES (?,?,'Rehearsal','2026-01-01','2026-12-31')",[$period,$unit]);
            foreach ([$md,$dal] as $i=>$account) { $c->executeStatement('INSERT INTO ucto.accounts(id,accounting_unit_id,code,name) VALUES (?,?,?,?)',[$account,$unit,'90'.$i,'Rehearsal account']); }
        });
        $payload=['periodId'=>$period,'postingDate'=>'2026-11-04','text'=>'Restore rehearsal','partnerId'=>null,
            'lines'=>[['accountId'=>$md,'text'=>'MD','md'=>'100.00','dal'=>'0.00'],['accountId'=>$dal,'text'=>'DAL','md'=>'0.00','dal'=>'99.00']]];
        $save=static function(string $document,?int $version,array $value) use($run,$unit): void {
            $run(static fn(Connection $c)=>$c->fetchOne('SELECT ucto.save_draft(?::uuid,?::uuid,?::integer,?::jsonb,?::uuid[])',
                [$unit,$document,$version,json_encode($value,JSON_THROW_ON_ERROR),'{'.Uuid::v7()->toRfc4122().','.Uuid::v7()->toRfc4122().'}']));
        };
        $first=Uuid::v7()->toRfc4122();$second=Uuid::v7()->toRfc4122();$save($first,null,$payload);
        self::rejected(static fn()=>$run(static fn(Connection $c)=>$c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$unit,$first])),'23514');
        $payload['lines'][1]['dal']='100.00';$save($first,1,$payload);
        $run(static fn(Connection $c)=>$c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,2)',[$unit,$first]));
        $number=$run(static fn(Connection $c)=>$c->fetchOne('SELECT number FROM ucto.documents WHERE id=?',[$first]));
        if ($number!=='INT/20260101/000001') { throw new \RuntimeException('Posting did not allocate the first number.'); }
        $auditBefore=$owner->fetchOne('SELECT count(*) FROM core.audit_log');
        $run(static fn(Connection $c)=>$c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,3)',[$unit,$first]));
        if ($owner->fetchOne('SELECT count(*) FROM core.audit_log')!==$auditBefore) { throw new \RuntimeException('Repeated posting changed audit.'); }
        $save($second,null,$payload);
        self::rejected(static fn()=>$run(static fn(Connection $c)=>$c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$unit,$period])),'23514');
        $run(static fn(Connection $c)=>$c->fetchOne('SELECT ucto.post_document(?::uuid,?::uuid,1)',[$unit,$second]));
        $repository=new DocumentRepository($app);
        [, $count]=$run(static fn()=>$repository->journal($unit,[1,20,'',$period,null,null,null]));
        [, $filtered]=$run(static fn()=>$repository->journal($unit,[1,20,'',$period,null,null,$md]));
        if ($count!==4||$filtered!==2) { throw new \RuntimeException('Journal smoke failed.'); }
        $run(static fn(Connection $c)=>$c->fetchOne('SELECT ucto.close_period(?::uuid,?::uuid,1)',[$unit,$period]));
        self::rejected(static fn()=>$save(Uuid::v7()->toRfc4122(),null,$payload),'23514');
        self::rejected(static fn()=>$owner->transactional(static fn(Connection $c)=>$c->executeStatement("UPDATE ucto.documents SET text='forbidden' WHERE id=?",[$first])),'23514');
        if (!(bool)$owner->fetchOne('SELECT ok FROM core.audit_verify()')) { throw new \RuntimeException('Audit smoke failed.'); }
        $foreign=$run(static fn(Connection $c)=>(int)$c->fetchOne('SELECT count(*) FROM core.accounting_units WHERE id<>?',[$unit]));
        if ($foreign!==0) { throw new \RuntimeException('Unit isolation smoke failed.'); }
        return ['unbalancedRejected'=>true,'firstNumber'=>$number,'repeatedPostingHasNoAudit'=>true,'draftPreventsClosing'=>true,
            'journalRows'=>$count,'journalFilteredRows'=>$filtered,'closedPeriodRejectsWrites'=>true,'postedDocumentImmutable'=>true,'auditValid'=>true,'unitIsolation'=>true];
    }
    private static function rejected(callable $operation,string $state): void
    {
        try { $operation(); } catch(Exception $error) { if ($error->getSQLState()===$state) { return; } throw $error; }
        throw new \RuntimeException('An invalid accounting operation was accepted.');
    }
}
