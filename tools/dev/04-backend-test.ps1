[CmdletBinding()]
param([switch]$Http, [ValidateRange(1,65535)][int]$Port = 7005)
. "$PSScriptRoot\common.ps1"
# Give each unit run its own cache so clearing it cannot remove container files
# used by another unit run, a DB suite or its HTTP subprocesses. Restore the environment before
# the caller starts integration tests, which keep their usual shared cache.
$savedUnitCache = @{}
$unitCache = Join-Path $script:BackendDir ('var\cache\unit-' + [guid]::NewGuid().ToString('N'))
try {
    foreach ($name in @('APP_CACHE_DIR','APP_BUILD_DIR')) {
        $savedUnitCache[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $unitCache, 'Process')
    }
    Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\console'), 'cache:clear', '--env=test', '--no-warmup')
    Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'vendor\bin\phpunit'), '--configuration', (Join-Path $script:BackendDir 'phpunit.xml.dist'))
}
finally {
    foreach ($name in $savedUnitCache.Keys) { [Environment]::SetEnvironmentVariable($name, $savedUnitCache[$name], 'Process') }
}
if ($Http) {
    foreach ($path in @('health', 'health/database')) {
        $result = Invoke-RestMethod -Uri "http://127.0.0.1:$Port/api/v1/$path" -TimeoutSec 10
        if ($result.status -ne 'ok') { throw "Kontrola $path selhala." }
        Write-Host "HTTP $path OK."
    }
}
Write-Host 'Testy backendu OK.' -ForegroundColor Green
