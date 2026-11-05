<#
.SYNOPSIS
    Inicializuje databázi: role, oprávnění, rozšíření a auditní infrastrukturu (db\sql\0*.sql),
    spustí kontrolu (090_kontrola.sql) a vytvoří soubor s připojovacími řetězci pro backend.
    Skript je idempotentní.
#>
[CmdletBinding()]
param([switch]$BezKontroly)

. "$PSScriptRoot\common.ps1"
$cfg = Import-DbConfig
$fqdn = Get-ServerFqdn $cfg

Write-Step "Spojení s $fqdn"
Invoke-Psql -Config $cfg -Command 'SELECT version();'

$vars = @{
    db_name   = $cfg.DatabaseName
    owner_pwd = Get-OrCreateSecret $cfg 'owner_password'
    app_pwd   = Get-OrCreateSecret $cfg 'app_password'
    read_pwd  = Get-OrCreateSecret $cfg 'read_password'
}

$files = Get-ChildItem -Path $script:SqlDir -Filter '0*.sql' | Where-Object Name -NotLike '09*' | Sort-Object Name
foreach ($f in $files) {
    Write-Step $f.Name
    Invoke-Psql -Config $cfg -Database $cfg.DatabaseName -File $f.FullName -Variables $vars -SingleTransaction
    Write-Ok 'OK'
}

if (-not $BezKontroly) {
    Write-Step '090_kontrola.sql'
    Invoke-Psql -Config $cfg -Database $cfg.DatabaseName -File (Join-Path $script:SqlDir '090_kontrola.sql')
}

Write-Step 'Připojovací řetězce'
$envPath = Join-Path $script:SecretsDir "$($cfg.ServerName).env"
$q = "serverVersion=$($cfg.PgVersion)&charset=utf8&sslmode=require"
$db = $cfg.DatabaseName
@"
# mikoucto - připojení k databázi $fqdn
# NEcommitovat. Hodnoty zkopírujte do backend\.env.local
DATABASE_URL="postgresql://mikoucto_app:$($vars.app_pwd)@${fqdn}:5432/${db}?$q"
DATABASE_MIGRATIONS_URL="postgresql://mikoucto_owner:$($vars.owner_pwd)@${fqdn}:5432/${db}?$q"
DATABASE_READ_URL="postgresql://mikoucto_read:$($vars.read_pwd)@${fqdn}:5432/${db}?$q"
"@ | Out-File -FilePath $envPath -Encoding utf8
& icacls $envPath /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
Write-Ok "Uloženo do $envPath"
