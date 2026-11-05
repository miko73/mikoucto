<?php
namespace App\Modules\Core\Infrastructure\Backup;
use App\Modules\Core\Infrastructure\Identity\IdentityProvisioner;
use App\Modules\Core\Infrastructure\Migrations\MigrationFactory;
use Doctrine\DBAL\Connection;
final class DevelopmentDeployment
{
    public static function run(Connection $owner,array $source,bool $apply): array
    {
        MigrationFactory::assertTarget($owner,'playground');
        return self::verifiedTransaction($owner,$source,$apply);
    }
    public static function rehearse(Connection $owner,array $source,string $name,string $id): array
    {
        BackupGuard::clone($owner,$name,$id);
        return self::verifiedTransaction($owner,$source,true);
    }
    private static function verifiedTransaction(Connection $owner,array $source,bool $apply): array
    {
        $database=$owner->fetchOne('SELECT current_database()');
        $owner->beginTransaction();
        try {
            if ($apply) {
                $owner->executeStatement("SET LOCAL lock_timeout='10s'");$owner->executeStatement("SET LOCAL statement_timeout='180s'");
                $owner->fetchOne("SELECT pg_advisory_xact_lock(hashtextextended('mikoucto.development-deploy',0))");
                $tables=[];
                foreach ($source['manifest']['schema']['relations'] as $relation) {
                    if (in_array($relation['relkind'],['r','p'],true)) { $tables[]='ONLY '.$owner->quoteIdentifier($relation['nspname']).'.'.$owner->quoteIdentifier($relation['relname']); }
                }
                if (!$tables) { throw new \RuntimeException('Source backup has no application tables.'); }
                $owner->executeStatement('LOCK TABLE '.implode(',',$tables).' IN SHARE ROW EXCLUSIVE MODE');
            } else { $owner->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY'); }
            $searchPath=$owner->fetchOne('SHOW search_path');$before=BackupManifest::capture($owner);
            if ($before['fingerprint']!==$source['manifest']['fingerprint']) { throw new \RuntimeException('Playground changed since the verified backup. Create and verify a fresh backup.'); }
            $owner->fetchOne("SELECT set_config('search_path',?,true)",[$searchPath]);
            if (!$apply) {
                $versions=$owner->fetchFirstColumn('SELECT version FROM migration.versions ORDER BY version');$owner->rollBack();
                return ['applied'=>false,'database'=>$database,'sourceMatchesBackup'=>true,'versions'=>$versions];
            }
            $migration=VerifiedMigrationRunner::run($owner);
            $admins=$owner->fetchAllAssociative("SELECT DISTINCT u.entra_tenant_id,u.entra_object_id,u.display_name FROM core.users u JOIN core.user_roles r ON r.user_id=u.id JOIN core.accounting_units a ON a.id=r.accounting_unit_id WHERE u.active AND a.active AND a.code='DEV' AND r.role_code='administrator'");
            $unit=['ready'=>false,'reason'=>'Use tools/dev/09-uzivatel.ps1 with the intended identity.'];
            if (count($admins)===1) {
                $admin=$admins[0];$provision=IdentityProvisioner::provision($owner,['tenant'=>$admin['entra_tenant_id'],'object'=>$admin['entra_object_id'],'display_name'=>$admin['display_name'],
                    'unit_code'=>'UCTO_DEV','unit_name'=>'Účetní vývojové ověření','role'=>'administrator']);
                $unit=['ready'=>true,'id'=>$provision['accounting_unit_id'],'code'=>'UCTO_DEV','userId'=>$provision['user_id']];
            }
            $after=BackupManifest::capture($owner);BackupManifest::preserved($source['manifest'],$after);
            if ($source['migrationFiles']!==BackupGuard::migrationFiles()) { throw new \RuntimeException('Migration files changed during development deployment.'); }
            if ($owner->getTransactionNestingLevel()!==1) { throw new \RuntimeException('Migration lost the outer transaction boundary.'); }
            $owner->commit();
            return ['applied'=>true,'database'=>$database,'appliedAt'=>gmdate('c'),'migration'=>$migration,'testUnit'=>$unit,
                'originalRowsPreserved'=>true,'auditValid'=>true,'sourceFingerprintBefore'=>$before['fingerprint'],'sourceFingerprintAfter'=>$after['fingerprint']];
        } catch(\Throwable $error) { if ($owner->isTransactionActive()) { $owner->rollBack(); } throw $error; }
    }
}
