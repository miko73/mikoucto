[CmdletBinding()]
param([ValidateRange(1,65535)][int]$Port = 7005, [switch]$Install, [switch]$CheckOnly)
. "$PSScriptRoot\common.ps1"
if (-not (Get-Command node -ErrorAction SilentlyContinue)) { throw 'Zivy prihlasovaci test vyzaduje Node.js 22 nebo novejsi.' }
$nodeVersion = & node --version
if ($LASTEXITCODE -ne 0 -or [version]($nodeVersion -replace '^v','') -lt [version]'22.0.0') { throw 'Zivy prihlasovaci test vyzaduje Node.js 22 nebo novejsi.' }
$configFile = Join-Path $script:BackendDir '.env.local'
$config = if (Test-Path -LiteralPath $configFile) { [IO.File]::ReadAllText($configFile) } else { '' }
$saved = @{}
$names = @('ENTRA_TENANT_ID', 'ENTRA_API_CLIENT_ID', 'ENTRA_PUBLIC_CLIENT_ID', 'MIKOUCTO_API_URL')
foreach ($name in $names) { $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
try {
    foreach ($name in $names | Where-Object { $_ -ne 'MIKOUCTO_API_URL' }) {
        $value = [Environment]::GetEnvironmentVariable($name, 'Process')
        if (-not $value) {
            $match = [regex]::Match($config, ('(?m)^' + [regex]::Escape($name) + '=([a-fA-F0-9-]+)\r?$'))
            if ($match.Success) { $value = $match.Groups[1].Value }
        }
        $parsed = [guid]::Empty
        if (-not [guid]::TryParse($value, [ref]$parsed) -or $parsed -eq [guid]::Empty) { throw 'Nejprve provedte registrace Entra a 08-entra-konfigurace.ps1 podle analyza/entra_nastaveni.md.' }
        [Environment]::SetEnvironmentVariable($name, $parsed.ToString(), 'Process')
    }
    $clientDir = Join-Path $script:ProjectRoot 'tools\entra-client'
    if ($Install) {
        & npm.cmd ci --prefix $clientDir --ignore-scripts --no-fund --no-audit
        if ($LASTEXITCODE -ne 0) { throw 'Instalace MSAL klienta selhala.' }
    }
    if (-not (Test-Path (Join-Path $clientDir 'node_modules\@azure\msal-node'))) { throw 'Nejprve spustte tento skript s -Install pro instalaci MSAL klienta.' }
    $env:MIKOUCTO_API_URL = "http://127.0.0.1:$Port"
    $nodeArguments = @((Join-Path $clientDir 'login.mjs'))
    if ($CheckOnly) { $nodeArguments += '--check-only' }
    & node @nodeArguments
    if ($LASTEXITCODE -ne 0) { throw 'Zivy test Entra selhal. Access token se nevypisuje ani neuklada.' }
}
finally { foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process') } }
