[CmdletBinding()]
param([Parameter(Mandatory)][string]$BackupDirectory,[switch]$Http,[ValidateRange(1,65535)][int]$DatabasePort=5432,[ValidateRange(1,65535)][int]$BackendPort=7005,[ValidateRange(1,65535)][int]$WebPort=8005)
. "$PSScriptRoot\backup-common.ps1"
$directory=Get-BackupDirectory $BackupDirectory
$process=Start-BackupPhp 'check-development' @{DATABASE_MIGRATIONS_URL=(Import-ProjectDatabase -Role owner -Database playground -Port $DatabasePort);MIKOUCTO_BACKUP_APP_URL=(Import-ProjectDatabase -Role app -Database playground -Port $DatabasePort);MIKOUCTO_BACKUP_DIR=$directory}
try {
    if (!$process.WaitForExit(120000)) { $process.Kill();$process.WaitForExit();throw 'Vyprsel limit kontroly vyvojove DB.' }
    Complete-BackupPhp $process
} finally { $process.Dispose() }
if ($Http) {
    foreach ($path in @('health','health/database')) {
        $response=Invoke-RestMethod -Uri "http://localhost:$BackendPort/api/v1/$path" -TimeoutSec 20
        if ($response.status -ne 'ok') { throw "HTTP $path neni v poradku." }
        Write-Host "HTTP $path OK."
    }
    $web=Invoke-WebRequest -Uri "http://localhost:$WebPort/" -UseBasicParsing -TimeoutSec 20
    if ($web.StatusCode -ne 200) { throw 'Webovy server neni dostupny.' }
    Write-Host "Web http://localhost:$WebPort OK."
}
Write-Host 'Vyvojova DB a prava UCTO_DEV OK. Skutecne prihlaseni a ucetni pruchod v prohlizeci jeste potvrdi obsluha.' -ForegroundColor Green
