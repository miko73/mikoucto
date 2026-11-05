[CmdletBinding()]
param(
    [ValidateSet('Development', 'Test')][string]$Target = 'Development',
    [Parameter(Mandatory)][guid]$TenantId,
    [Parameter(Mandatory)][guid]$ObjectId,
    [Parameter(Mandatory)][ValidateLength(1,200)][string]$DisplayName,
    [Parameter(Mandatory)][ValidatePattern('^[A-Z0-9_-]{1,32}$')][string]$UnitCode,
    [Parameter(Mandatory)][ValidateLength(1,200)][string]$UnitName,
    [ValidateSet('administrator','accountant','auditor','reader')][string]$Role = 'reader',
    [ValidateRange(1,65535)][int]$DatabasePort = 5432
)
. "$PSScriptRoot\common.ps1"
if ($TenantId -eq [guid]::Empty -or $ObjectId -eq [guid]::Empty) { throw 'TenantId a ObjectId nesmi byt prazdna GUID.' }
$UnitCode = $UnitCode.ToUpperInvariant()
$Role = $Role.ToLowerInvariant()
$database = if ($Target -eq 'Test') { 'mikoucto_test' } else { 'playground' }
$saved = @{}
foreach ($name in @('DATABASE_MIGRATIONS_URL', 'MIKOUCTO_MIGRATION_DATABASE', 'MIKOUCTO_PROVISION_INPUT', 'PGCONNECT_TIMEOUT')) {
    $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
try {
    $env:DATABASE_MIGRATIONS_URL = Import-ProjectDatabase -Role owner -Database $database -Port $DatabasePort
    $env:MIKOUCTO_MIGRATION_DATABASE = $database
    $env:PGCONNECT_TIMEOUT = '5'
    $env:MIKOUCTO_PROVISION_INPUT = @{
        tenant = $TenantId.ToString(); object = $ObjectId.ToString(); display_name = $DisplayName
        unit_code = $UnitCode; unit_name = $UnitName; role = $Role
    } | ConvertTo-Json -Compress
    Write-Host "Registrace uzivatele: DB $database, jednotka $UnitCode, role $Role."
    Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\provision-user'))
}
finally {
    foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process') }
}
