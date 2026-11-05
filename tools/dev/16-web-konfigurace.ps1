[CmdletBinding()]
param(
    [Parameter(Mandatory)][guid]$TenantId,
    [Parameter(Mandatory)][guid]$ApiClientId,
    [Parameter(Mandatory)][guid]$SpaClientId
)
. "$PSScriptRoot\common.ps1"
. "$PSScriptRoot\web-common.ps1"
if ($TenantId -eq [guid]::Empty -or $ApiClientId -eq [guid]::Empty -or $SpaClientId -eq [guid]::Empty -or $ApiClientId -eq $SpaClientId) { throw 'Pouzijte skutecna ID tenantu a dvou ruznych registraci API a SPA.' }
$backendPath = Join-Path $script:BackendDir '.env.local'
if (-not (Test-Path -LiteralPath $backendPath)) { throw 'Nejprve pripravte backend a konfiguraci faze 4 (skript 08).' }
$content = [IO.File]::ReadAllText($backendPath)
foreach ($entry in @(@('ENTRA_TENANT_ID',$TenantId.ToString()),@('ENTRA_API_CLIENT_ID',$ApiClientId.ToString()))) {
    $match = [regex]::Match($content, '(?m)^' + $entry[0] + '=([^\r\n]*)')
    if (-not $match.Success -or $match.Groups[1].Value.Trim().Trim('"').Trim("'") -ne $entry[1]) { throw 'Tenant/API se neshoduji s backendem. Nejprve provedte skript 08 se stejnymi ID.' }
}
$allowed = [regex]::Match($content, '(?m)^ENTRA_ALLOWED_CLIENT_IDS=([^\r\n]*)')
if (-not $allowed.Success) { throw 'Chybi seznam povolenych klientu. Nejprve provedte skript 08.' }
$ids = @($allowed.Groups[1].Value.Trim().Trim('"').Trim("'").Split(',') | Where-Object { $_.Trim() } | ForEach-Object { $_.Trim() }) + @($SpaClientId.ToString())
$line = 'ENTRA_ALLOWED_CLIENT_IDS=' + (($ids | Select-Object -Unique) -join ',')
$content = [regex]::Replace($content, '(?m)^ENTRA_ALLOWED_CLIENT_IDS=[^\r\n]*', $line)
$encoding = New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText($backendPath, $content, $encoding)
$webPath = Join-Path $script:WebRoot '.env.local'
$webContent = if (Test-Path -LiteralPath $webPath) { [IO.File]::ReadAllText($webPath) } else { '' }
$settings = [ordered]@{ VITE_ENTRA_TENANT_ID=$TenantId; VITE_ENTRA_API_CLIENT_ID=$ApiClientId; VITE_ENTRA_SPA_CLIENT_ID=$SpaClientId }
foreach ($name in $settings.Keys) {
    $pattern = '(?m)^' + $name + '=[^\r\n]*'
    $value = "$name=$($settings[$name])"
    if ([regex]::IsMatch($webContent,$pattern)) { $webContent=[regex]::Replace($webContent,$pattern,$value) }
    else { $webContent=$webContent.TrimEnd()+"`n$value`n" }
}
[IO.File]::WriteAllText($webPath,$webContent.Trim()+"`n",$encoding)
Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\console'), 'cache:clear', '--env=dev', '--no-warmup')
Write-Host 'Verejna ID ulozena, SPA povolena v API. Restartujte backend i web. Zadny client secret se nepouziva.' -ForegroundColor Green
