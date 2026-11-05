[CmdletBinding()]
param([Parameter(Mandatory)][string]$BackupDirectory,[switch]$Apply,[ValidateRange(1,65535)][int]$DatabasePort=5432)
. "$PSScriptRoot\backup-common.ps1"
$directory=Get-BackupDirectory $BackupDirectory
[void](Get-BackupSource $directory);[void](Assert-BackupArchive $directory)
if (!(Test-Path -LiteralPath (Join-Path $directory 'restore-report.json'))) { throw 'Nejprve musi projit skutecna obnova a nacvik skriptem 10.' }
$process=Start-BackupPhp 'deploy-development' @{DATABASE_MIGRATIONS_URL=(Import-ProjectDatabase -Role owner -Database playground -Port $DatabasePort);MIKOUCTO_BACKUP_DIR=$directory;MIKOUCTO_DEVELOPMENT_APPLY=$(if($Apply){'yes'}else{'no'})}
try {
    # Output is bounded to a single summary line; the full report is private.
    if (!$process.WaitForExit(240000)) { $process.Kill();$process.WaitForExit();throw 'Vyprsel limit nasazeni. Pred opakovanim overte stav DB.' }
    Complete-BackupPhp $process
} finally { $process.Dispose() }
if ($Apply) { Write-Host "Vyvojove nasazeni OK. Report: $directory\deployment-report.json" -ForegroundColor Green }
else { Write-Host 'Predbezna kontrola OK. Migrace nebyla spustena; k aplikaci slouzi explicitni prepinac -Apply.' -ForegroundColor Green }
