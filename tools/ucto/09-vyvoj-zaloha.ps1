[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort=5432,[switch]$PassThru)
. "$PSScriptRoot\backup-common.ps1"
$id='playground_'+[DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')+'_'+[guid]::NewGuid().ToString('N').Substring(0,12)
$directory=Join-Path $script:BackupRoot $id
New-Item -ItemType Directory -Path $directory | Out-Null
# Protect only this newly created directory, never change an existing tree.
$acl=Get-Acl -LiteralPath $directory;$acl.SetAccessRuleProtection($true,$false)
foreach ($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User,[Security.Principal.SecurityIdentifier]'S-1-5-18',[Security.Principal.SecurityIdentifier]'S-1-5-32-544')) {
    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid,'FullControl','ContainerInherit,ObjectInherit','None','Allow')))
}
Set-Acl -LiteralPath $directory -AclObject $acl
$nonce=$id.Substring($id.Length-12);$remoteDir="/tmp/mikoucto-backup-$nonce";$process=$null
try {
    $process=Start-BackupPhp 'backup-snapshot' @{DATABASE_MIGRATIONS_URL=(Import-ProjectDatabase -Role owner -Database playground -Port $DatabasePort);MIKOUCTO_BACKUP_DIR=$directory}
    Wait-BackupPhp $process (Join-Path $directory 'source-ready.json')
    $source=Get-BackupSource $directory
    $script=@'
umask 077
test -x /usr/lib/postgresql/16/bin/pg_dump
mkdir -m 700 '@REMOTE@'
same=$(sudo -n -u postgres /usr/lib/postgresql/16/bin/psql -X -w -At -v ON_ERROR_STOP=1 -d playground -c "SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE pid=@PID@ AND datname='playground' AND application_name='@APP@')")
test "$same" = t
sudo -n -u postgres /usr/lib/postgresql/16/bin/pg_dump --format=custom --snapshot='@SNAPSHOT@' --dbname=playground > '@REMOTE@/database.dump'
sha256sum '@REMOTE@/database.dump' > '@REMOTE@/archive.sha256'
'@
    $script=$script.Replace('@REMOTE@',$remoteDir).Replace('@PID@',[string][int]$source.sourceIdentity.pid).Replace('@APP@',[string]$source.applicationName).Replace('@SNAPSHOT@',[string]$source.snapshot)
    Write-Host 'Konzistentni zaloha playground pres PostgreSQL 16. Zdrojova data se nemeni.'
    Invoke-BackupSsh $script
    [IO.File]::WriteAllText((Join-Path $directory 'release-source'),'release')
    Complete-BackupPhp $process
    foreach ($file in @('database.dump','archive.sha256')) { Invoke-BackupScp $directory "michal_kocandrle@pg-free:$remoteDir/$file" "./$file" }
    $remoteHash=([IO.File]::ReadAllText((Join-Path $directory 'archive.sha256')) -split '\s+')[0]
    $localHash=(Get-FileHash -LiteralPath (Join-Path $directory 'database.dump') -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($remoteHash -cne $localHash) { throw 'Preneseny archiv ma jiny SHA-256.' }
    Write-BackupJson (Join-Path $directory 'archive.json') @{file='database.dump';sha256=$localHash;sourceManifestSha256=(Get-FileHash -LiteralPath (Join-Path $directory 'source.json') -Algorithm SHA256).Hash.ToLowerInvariant();bytes=(Get-Item -LiteralPath (Join-Path $directory 'database.dump')).Length;verifiedRestore=$false}
    Copy-Item -LiteralPath (Join-Path $script:ProjectRoot 'db\google\.secrets\playground.roles.clixml') -Destination (Join-Path $directory 'roles.dpapi.clixml')
    Invoke-BackupSsh "rm -f '$remoteDir/database.dump' '$remoteDir/archive.sha256'`nrmdir '$remoteDir'"
    Write-Host "Zaloha vytvorena: $directory" -ForegroundColor Green
    Write-Host 'Dalsi krok: .\tools\ucto\10-vyvoj-obnova-test.ps1 -BackupDirectory <uvedeny adresar>'
    if ($PassThru) { Write-Output $directory }
} finally {
    [IO.File]::WriteAllText((Join-Path $directory 'release-source'),'release')
    if ($process) {
        if (!$process.HasExited) { [void]$process.WaitForExit(10000) }
        if (!$process.HasExited) { $process.Kill() }
        $process.Dispose()
    }
}
