[CmdletBinding()]
param(
    [ValidateSet('Development', 'Test')][string]$Target = 'Test',
    [ValidateSet('Status', 'Migrate', 'UpToDate')][string]$Action = 'Status',
    [ValidateRange(1,65535)][int]$DatabasePort = 5432
)
. "$PSScriptRoot\common.ps1"
$database = if ($Target -eq 'Test') { 'mikoucto_test' } else { 'playground' }
$saved = @{}
foreach ($name in @('DATABASE_MIGRATIONS_URL', 'MIKOUCTO_MIGRATION_DATABASE', 'PGCONNECT_TIMEOUT')) {
    $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
try {
    # Zamerne nesdili DATABASE_URL bezici aplikace. Cil je vzdy explicitni.
    $env:DATABASE_MIGRATIONS_URL = Import-ProjectDatabase -Role owner -Port $DatabasePort -Database $database
    $env:MIKOUCTO_MIGRATION_DATABASE = $database
    $env:PGCONNECT_TIMEOUT = '5'
    Write-Host "Migrace: $Action, databaze $database, role mikoucto_owner."
    $command = @{ Status = 'migrations:status'; Migrate = 'migrations:migrate'; UpToDate = 'migrations:up-to-date' }[$Action]
    Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\migrations'), $command, '--no-interaction', '--no-ansi')
}
finally {
    foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process') }
}
