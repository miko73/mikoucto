[CmdletBinding()]
param([Parameter(Mandatory)][string]$BackupDirectory,[ValidateRange(1,65535)][int]$DatabasePort=5432)
. "$PSScriptRoot\backup-common.ps1"
$directory=Get-BackupDirectory $BackupDirectory
$source=Get-BackupSource $directory;$archive=Assert-BackupArchive $directory
foreach ($marker in @('verification-ready.json','restore-report.json','restore-error.json')) {
    $path=Join-Path $directory $marker
    if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force }
}
$id=[string]$source.backupId;$nonce=$id.Substring($id.Length-12)
$database="mikoucto_restore_$nonce";$remoteDir="/tmp/mikoucto-restore-$nonce"
if ($source.manifest.database.datlocprovider -cne 'c' -or $source.manifest.database.encoding -cne 'UTF8' -or $source.manifest.database.owner -cne 'mikoucto_owner') { throw 'Obnova ocekava puvodni UTF8/libc databazi vlastnenou mikoucto_owner.' }
function SqlLiteral([string]$Text) { return "'"+$Text.Replace("'","''")+"'" }
$collate=SqlLiteral $source.manifest.database.datcollate;$ctype=SqlLiteral $source.manifest.database.datctype
$create=@'
\set ON_ERROR_STOP on
DO $$ BEGIN
 IF current_user<>'postgres' OR current_setting('server_version_num')::int/10000<>16 THEN RAISE EXCEPTION 'Restore requires PostgreSQL 16 administrator'; END IF;
 IF EXISTS(SELECT 1 FROM pg_database WHERE datname='@DATABASE@') THEN RAISE EXCEPTION 'Restore target already exists; it will not be changed'; END IF;
END $$;
CREATE DATABASE @DATABASE@ OWNER mikoucto_owner TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER libc LC_COLLATE @COLLATE@ LC_CTYPE @CTYPE@;
COMMENT ON DATABASE @DATABASE@ IS 'mikoucto restore verification:@ID@';
REVOKE ALL ON DATABASE @DATABASE@ FROM PUBLIC;
SELECT format('GRANT %s ON DATABASE %I TO %s%s',a.privilege_type,'@DATABASE@',CASE WHEN a.grantee=0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(a.grantee)) END,CASE WHEN a.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END)
FROM pg_database d CROSS JOIN LATERAL aclexplode(coalesce(d.datacl,acldefault('d',d.datdba))) a WHERE d.datname='playground' \gexec
'@
$create=$create.Replace('@DATABASE@',$database).Replace('@ID@',$id).Replace('@COLLATE@',$collate).Replace('@CTYPE@',$ctype)
[IO.File]::WriteAllText((Join-Path $directory 'create-restore.sql'),$create,(New-Object Text.UTF8Encoding($false)))
$created=$false;$remoteCreated=$false;$process=$null;$verified=$false
try {
    Invoke-BackupSsh "umask 077`nmkdir -m 700 '$remoteDir'"
    $remoteCreated=$true
    Invoke-BackupScp $directory './database.dump' "michal_kocandrle@pg-free:$remoteDir/database.dump"
    Invoke-BackupScp $directory './create-restore.sql' "michal_kocandrle@pg-free:$remoteDir/create-restore.sql"
    Invoke-BackupSsh "cat '$remoteDir/create-restore.sql' | sudo -n -u postgres /usr/lib/postgresql/16/bin/psql -X -w -v ON_ERROR_STOP=1 -d postgres"
    $created=$true
    $restore=@'
safe=$(sudo -n -u postgres /usr/lib/postgresql/16/bin/psql -X -w -At -v ON_ERROR_STOP=1 -d postgres -c "SELECT count(*) FROM pg_database WHERE datname='@DATABASE@' AND pg_get_userbyid(datdba)='mikoucto_owner' AND shobj_description(oid,'pg_database')='mikoucto restore verification:@ID@'")
test "$safe" = 1
actual=$(sha256sum '@REMOTE@/database.dump')
test "${actual%% *}" = '@SHA@'
sudo -n -u postgres /usr/lib/postgresql/16/bin/pg_restore --exit-on-error --single-transaction --dbname='@DATABASE@' < '@REMOTE@/database.dump'
'@
    $restore=$restore.Replace('@DATABASE@',$database).Replace('@ID@',$id).Replace('@REMOTE@',$remoteDir).Replace('@SHA@',[string]$archive.sha256)
    Write-Host "Obnova a nacvik migraci: $database. Playground se nemigruje."
    Invoke-BackupSsh $restore
    $process=Start-BackupPhp 'verify-restore' @{DATABASE_MIGRATIONS_URL=(Import-ProjectDatabase -Role owner -Database playground -Port $DatabasePort);MIKOUCTO_BACKUP_APP_URL=(Import-ProjectDatabase -Role app -Database playground -Port $DatabasePort);MIKOUCTO_BACKUP_DIR=$directory;MIKOUCTO_RESTORE_DATABASE=$database}
    Wait-BackupPhp $process (Join-Path $directory 'verification-ready.json')
    Complete-BackupPhp $process
    $verified=$true
} finally {
    if ($process) {
        if (!$process.HasExited) { $process.Kill();$process.WaitForExit() }
        $process.Dispose()
    }
    if ($created) {
        # Only the fresh copy created by this invocation may be removed. The
        # name is derived from the validated nonce, and the DB marker is checked
        # again immediately before DROP. No FORCE or connection termination.
        $drop=@'
DO $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM pg_database WHERE datname='@DATABASE@' AND pg_get_userbyid(datdba)='mikoucto_owner' AND shobj_description(oid,'pg_database')='mikoucto restore verification:@ID@') THEN RAISE EXCEPTION 'Cleanup target mismatch'; END IF;
END $$;
SELECT format('DROP DATABASE %I','@DATABASE@') \gexec
'@
        $drop=$drop.Replace('@DATABASE@',$database).Replace('@ID@',$id)
        [IO.File]::WriteAllText((Join-Path $directory 'drop-restore.sql'),$drop,(New-Object Text.UTF8Encoding($false)))
        Invoke-BackupScp $directory './drop-restore.sql' "michal_kocandrle@pg-free:$remoteDir/drop-restore.sql"
        Invoke-BackupSsh "cat '$remoteDir/drop-restore.sql' | sudo -n -u postgres /usr/lib/postgresql/16/bin/psql -X -w -v ON_ERROR_STOP=1 -d postgres"
        if ($verified) {
            $report=[IO.File]::ReadAllText((Join-Path $directory 'restore-report.json')) | ConvertFrom-Json
            $report.restoreDatabaseRemoved=$true
            Write-BackupJson (Join-Path $directory 'restore-report.json') $report
        }
    }
    if ($remoteCreated) { Invoke-BackupSsh "rm -f '$remoteDir/database.dump' '$remoteDir/create-restore.sql' '$remoteDir/drop-restore.sql'`nrmdir '$remoteDir'" }
}
if (!$verified) { throw 'Obnova nebyla overena.' }
Write-Host "Zaloha i obnova OK. Nacvik migraci a ucetni scenar prosly. Report: $directory\restore-report.json" -ForegroundColor Green
Write-Host 'Vyvojova migrace do playground dosud nebyla spustena. Pred jejim spustenim zkontrolujte report a aktualnost zalohy.'
