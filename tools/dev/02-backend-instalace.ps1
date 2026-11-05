[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$composer = Join-Path $script:ProjectRoot 'tools\composer\composer.phar'
if (-not (Test-Path -LiteralPath $composer)) { throw 'Spustte nejprve tools\dev\00-nastroje.ps1.' }
$localEnv = Join-Path $script:BackendDir '.env.local'
if (-not (Test-Path -LiteralPath $localEnv)) {
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $secret = ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
    "APP_SECRET=$secret" | Set-Content -LiteralPath $localEnv -Encoding ASCII
}
Invoke-ProjectPhp -Arguments @($composer, "--working-dir=$script:BackendDir", 'install', '--no-interaction', '--prefer-dist')
Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\console'), 'cache:clear', '--env=dev', '--no-warmup')
Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\console'), 'lint:container')
Write-Host 'Zavislosti backendu OK.' -ForegroundColor Green
