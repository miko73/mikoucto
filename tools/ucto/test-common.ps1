[CmdletBinding()]
param(
    [ValidateRange(1,65535)][int]$DatabasePort = 5432,
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string[]]$RequiredClasses,
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$SuccessMessage
)
. "$PSScriptRoot\..\dev\common.ps1"
& "$PSScriptRoot\01-osnova-obdobi.ps1" -Target Test -DatabasePort $DatabasePort
& "$PSScriptRoot\..\dev\04-backend-test.ps1"
$saved = @{}
$names = @('MIKOUCTO_TEST_OWNER_URL','MIKOUCTO_TEST_APP_URL','MIKOUCTO_TEST_READ_URL','PGCONNECT_TIMEOUT')
$report = Join-Path $script:BackendDir ('var\accounting-tests-' + [guid]::NewGuid().ToString('N') + '.xml')
$testsPassed = $false
try {
    foreach ($name in $names) { $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
    foreach ($role in @('owner','app','read')) {
        [Environment]::SetEnvironmentVariable("MIKOUCTO_TEST_$($role.ToUpperInvariant())_URL", (Import-ProjectDatabase -Role $role -Database mikoucto_test -Port $DatabasePort), 'Process')
    }
    $env:PGCONNECT_TIMEOUT = '5'
    Write-Host 'Ucetni testy a dosavadni DB regrese: mikoucto_test.'
    Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\test-database'), '--configuration', (Join-Path $script:BackendDir 'phpunit.integration.xml.dist'), '--log-junit', $report)
    [xml]$results = [IO.File]::ReadAllText($report)
    foreach ($class in $RequiredClasses) {
        $cases = @($results.SelectNodes('//testcase') | Where-Object { $_.class -eq $class })
        if ($cases.Count -eq 0) { throw "Povinna ucetni sada neprobehla: $class" }
        foreach ($case in $cases) {
            if ($case.SelectSingleNode('skipped|failure|error')) { throw "Povinna ucetni kontrola neprosla: $class" }
        }
        Write-Host "$class : $($cases.Count) uspesnych testu."
    }
    $testsPassed = $true
}
finally {
    foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process') }
    if (Test-Path -LiteralPath $report) {
        if ($testsPassed) { Remove-Item -LiteralPath $report }
        else { Write-Host "JUnit report pro diagnostiku: $report" }
    }
}
& "$PSScriptRoot\..\dev\06-migrace.ps1" -Target Test -Action UpToDate -DatabasePort $DatabasePort
& "$PSScriptRoot\03-openapi.ps1"
Write-Host $SuccessMessage -ForegroundColor Green
