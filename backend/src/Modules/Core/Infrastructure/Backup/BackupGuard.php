<?php
namespace App\Modules\Core\Infrastructure\Backup;
use Doctrine\DBAL\Connection;
final class BackupGuard
{
    public static function id(string $id): string
    {
        if (!preg_match('/^playground_[0-9]{8}T[0-9]{6}Z_[a-f0-9]{12}$/D',$id)) { throw new \RuntimeException('Invalid backup identifier.'); }
        return $id;
    }
    public static function directory(string $path): string
    {
        $root=realpath(dirname(__DIR__,6).'/db/backups');$actual=realpath($path);
        if (!$root||!$actual||strcasecmp(dirname($actual),$root)!==0) { throw new \RuntimeException('Backup must be a direct child of db/backups.'); }
        self::id(basename($actual));return $actual;
    }
    public static function cloneName(string $name,string $id): void
    {
        self::id($id);
        if ($name!=='mikoucto_restore_'.substr($id,-12)) { throw new \RuntimeException('Only the unique backup restore database is allowed.'); }
    }
    public static function clone(Connection $c,string $name,string $id): void
    {
        self::cloneName($name,$id);
        $row=$c->fetchAssociative("SELECT current_database() AS name,current_user AS role,pg_get_userbyid(datdba) AS owner,shobj_description(oid,'pg_database') AS marker FROM pg_database WHERE datname=current_database()");
        if (!$row||$row['name']!==$name||$row['role']!=='mikoucto_owner'||$row['owner']!=='mikoucto_owner'||$row['marker']!=='mikoucto restore verification:'.$id) {
            throw new \RuntimeException('Restore target identity or marker does not match.');
        }
    }
    public static function migrationFiles(): array
    {
        $root=dirname(__DIR__,5).'/migrations';$hashes=[];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()&&in_array($file->getExtension(),['php','sql'],true)) { $hashes[str_replace('\\','/',substr($file->getPathname(),strlen($root)+1))]=hash_file('sha256',$file->getPathname()); }
        }
        ksort($hashes);return $hashes;
    }
    public static function verified(array $source,array $archive,array $report,string $id): void
    {
        self::id($id);
        if (($source['backupId']??'')!==$id||($report['backupId']??'')!==$id||($report['archiveSha256']??'')!==($archive['sha256']??null)
            ||($report['sourceFingerprint']??'')!==($source['manifest']['fingerprint']??null)) { throw new \RuntimeException('Verification proof does not match this backup.'); }
        foreach (['restoredDatabaseVerified','sourceUnchanged','originalRowsPreservedAfterMigrations','restoreDatabaseRemoved','auditValid'] as $check) {
            if (($report[$check]??false)!==true) { throw new \RuntimeException('Incomplete restore verification: '.$check); }
        }
        foreach (['unbalancedRejected','repeatedPostingHasNoAudit','draftPreventsClosing','closedPeriodRejectsWrites','postedDocumentImmutable','auditValid','unitIsolation'] as $check) {
            if (($report['smoke'][$check]??false)!==true) { throw new \RuntimeException('Incomplete accounting rehearsal: '.$check); }
        }
        if (($report['migrationRehearsal']['outerTransactionRehearsed']??false)!==true) { throw new \RuntimeException('The guarded deployment transaction has not been rehearsed.'); }
        if (($report['migrationFiles']??[])!==self::migrationFiles()||($source['migrationFiles']??[])!==self::migrationFiles()) { throw new \RuntimeException('Migration files differ from the verified rehearsal.'); }
        $verified=new \DateTimeImmutable($report['verifiedAt']??'1970-01-01T00:00:00Z');$age=time()-$verified->getTimestamp();
        if ($age< -300||$age>86400) { throw new \RuntimeException('Restore verification is older than 24 hours or has an invalid time.'); }
    }
    public static function write(string $path,array $value): void
    {
        $json=json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($path.'.tmp',$json)===false||!rename($path.'.tmp',$path)) { throw new \RuntimeException('Cannot write backup report.'); }
    }
}
