<?php
namespace App\Tests;
use App\Modules\Core\Infrastructure\Backup\BackupGuard;
use App\Modules\Core\Infrastructure\Backup\BackupManifest;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class BackupSafetyTest extends TestCase
{
    private const ID='playground_20261104T100000Z_012345abcdef';
    public static function forbiddenNames(): array { return [['playground'],['mikoucto_test'],['postgres'],['mikoucto_restore_deadbeefdead'],['mikoucto_restore_012345abcdef;drop database playground']]; }
    #[DataProvider('forbiddenNames')]
    public function testRejectsEveryNonMatchingRestoreTargetBeforeQuerying(string $name): void
    {
        $connection=$this->createMock(Connection::class);$connection->expects(self::never())->method('fetchAssociative');
        $this->expectException(\RuntimeException::class);BackupGuard::clone($connection,$name,self::ID);
    }
    public function testRejectsUnmarkedDatabaseEvenWithMatchingNameAndOwner(): void
    {
        $connection=$this->createMock(Connection::class);$connection->method('fetchAssociative')->willReturn(['name'=>'mikoucto_restore_012345abcdef','role'=>'mikoucto_owner','owner'=>'mikoucto_owner','marker'=>'other purpose']);
        $this->expectException(\RuntimeException::class);BackupGuard::clone($connection,'mikoucto_restore_012345abcdef',self::ID);
    }
    public function testRejectsDirectoriesOutsideBackupRoot(): void
    {
        $this->expectException(\RuntimeException::class);BackupGuard::directory(dirname(__DIR__));
    }
    private function manifest(): array { return ['formatVersion'=>1,'database'=>[],'schema'=>[],'tables'=>['core.data'=>['count'=>1,'sha256'=>'a','rows'=>['a']]],'audit'=>['ok'=>true],
        'sequences'=>['core.id'=>['definition'=>['seqcycle'=>false,'seqincrement'=>'1'],'value'=>['last_value'=>'42','is_called'=>true]]]]; }
    public function testRestoredCounterMayAdvanceButCannotGoBackOrReuseValue(): void
    {
        $before=$this->manifest();$after=$before;$after['sequences']['core.id']['value']['last_value']='43';BackupManifest::verify($before,$after);
        $after['sequences']['core.id']['value']['last_value']='41';$this->expectException(\RuntimeException::class);BackupManifest::verify($before,$after);
    }
    public function testConsumedCounterCannotBecomeUnused(): void
    {
        $before=$this->manifest();$after=$before;$after['sequences']['core.id']['value']['is_called']=false;
        $this->expectException(\RuntimeException::class);BackupManifest::verify($before,$after);
    }
    public function testDataMismatchIsRejectedEvenWhenSchemaMatches(): void
    {
        $before=$this->manifest();$after=$before;$after['tables']['core.data']['sha256']='changed';
        $this->expectException(\RuntimeException::class);BackupManifest::verify($before,$after);
    }
    public function testMigrationMustPreserveDuplicateRowsAndMayAddNewRows(): void
    {
        $before=['tables'=>['t'=>['rows'=>['a','a']]]];BackupManifest::preserved($before,['tables'=>['t'=>['rows'=>['a','a','b']]]]);
        $this->expectException(\RuntimeException::class);BackupManifest::preserved($before,['tables'=>['t'=>['rows'=>['a','b']]]]);
    }
    private function proof(): array
    {
        $files=BackupGuard::migrationFiles();$hash=str_repeat('a',64);
        $source=['backupId'=>self::ID,'manifest'=>['fingerprint'=>$hash],'migrationFiles'=>$files];$archive=['sha256'=>$hash];
        $report=['backupId'=>self::ID,'sourceFingerprint'=>$hash,'archiveSha256'=>$hash,'migrationFiles'=>$files,'verifiedAt'=>gmdate('c'),
            'restoredDatabaseVerified'=>true,'sourceUnchanged'=>true,'originalRowsPreservedAfterMigrations'=>true,'restoreDatabaseRemoved'=>true,'auditValid'=>true,
            'migrationRehearsal'=>['outerTransactionRehearsed'=>true],'smoke'=>array_fill_keys(['unbalancedRejected','repeatedPostingHasNoAudit','draftPreventsClosing','closedPeriodRejectsWrites','postedDocumentImmutable','auditValid','unitIsolation'],true)];
        return [$source,$archive,$report];
    }
    public function testValidProofCannotBeReusedForDifferentArchive(): void
    {
        [$source,$archive,$report]=$this->proof();BackupGuard::verified($source,$archive,$report,self::ID);
        $archive['sha256']=str_repeat('b',64);$this->expectException(\RuntimeException::class);BackupGuard::verified($source,$archive,$report,self::ID);
    }
    public function testUnfinishedRehearsalCannotAuthorizeDevelopment(): void
    {
        [$source,$archive,$report]=$this->proof();$report['migrationRehearsal']['outerTransactionRehearsed']=false;
        $this->expectException(\RuntimeException::class);BackupGuard::verified($source,$archive,$report,self::ID);
    }
    public function testOldVerificationCannotAuthorizeDevelopment(): void
    {
        [$source,$archive,$report]=$this->proof();$report['verifiedAt']=gmdate('c',time()-90000);
        $this->expectException(\RuntimeException::class);BackupGuard::verified($source,$archive,$report,self::ID);
    }
    public function testChangedMigrationFilesCannotAuthorizeDevelopment(): void
    {
        [$source,$archive,$report]=$this->proof();$report['migrationFiles']=[];
        $this->expectException(\RuntimeException::class);BackupGuard::verified($source,$archive,$report,self::ID);
    }
}
