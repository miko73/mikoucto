[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort = 5432, [string]$Filter)
. "$PSScriptRoot\common.ps1"
# Migruje vyhradne testovaci DB; neobsahuje prepinac pro playground.
& "$PSScriptRoot\06-migrace.ps1" -Target Test -Action Migrate -DatabasePort $DatabasePort
$saved = @{}
foreach ($name in @('MIKOUCTO_TEST_OWNER_URL', 'MIKOUCTO_TEST_APP_URL', 'MIKOUCTO_TEST_READ_URL', 'PGCONNECT_TIMEOUT')) {
    $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
try {
    foreach ($role in @('owner', 'app', 'read')) {
        $name = "MIKOUCTO_TEST_$($role.ToUpperInvariant())_URL"
        [Environment]::SetEnvironmentVariable($name, (Import-ProjectDatabase -Role $role -Database mikoucto_test -Port $DatabasePort), 'Process')
    }
    $env:PGCONNECT_TIMEOUT = '5'
    $testArgs = @((Join-Path $script:BackendDir 'bin\test-database'), '--configuration', (Join-Path $script:BackendDir 'phpunit.integration.xml.dist'))
    if ($Filter) { $testArgs += @('--filter', $Filter) }
    Invoke-ProjectPhp -Arguments $testArgs
}
finally {
    foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process') }
}
& "$PSScriptRoot\06-migrace.ps1" -Target Test -Action UpToDate -DatabasePort $DatabasePort
if ($Filter) { Write-Host "Vybrane DB testy OK (filtr: $Filter)." -ForegroundColor Green }
else { Write-Host 'Etapa 3 OK: migrace, opakovani, skutecne DB role, audit a rollback.' -ForegroundColor Green }
