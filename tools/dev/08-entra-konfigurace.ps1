[CmdletBinding()]
param(
    [Parameter(Mandatory)][guid]$TenantId,
    [Parameter(Mandatory)][guid]$ApiClientId,
    [Parameter(Mandatory)][guid]$PublicClientId,
    [guid[]]$AllowedClientIds = @()
)
. "$PSScriptRoot\common.ps1"
if ($TenantId -eq [guid]::Empty -or $ApiClientId -eq [guid]::Empty -or $PublicClientId -eq [guid]::Empty) { throw 'Vyplnte skutecna ID registraci z Entra.' }
$AllowedClientIds = @($PublicClientId) + $AllowedClientIds
foreach ($clientId in $AllowedClientIds) {
    if ($clientId -eq [guid]::Empty -or $clientId -eq $ApiClientId) { throw 'Klient musi mit vlastni registraci odlisnou od API.' }
}
$path = Join-Path $script:BackendDir '.env.local'
if (-not (Test-Path -LiteralPath $path)) { throw 'Nejprve spustte 02-backend-instalace.ps1, ktera vytvori APP_SECRET.' }
$settings = [ordered]@{
    ENTRA_TENANT_ID = $TenantId.ToString()
    ENTRA_API_CLIENT_ID = $ApiClientId.ToString()
    ENTRA_PUBLIC_CLIENT_ID = $PublicClientId.ToString()
    ENTRA_ALLOWED_CLIENT_IDS = (($AllowedClientIds | ForEach-Object { $_.ToString() } | Select-Object -Unique) -join ',')
    ENTRA_REQUIRED_SCOPE = 'access_as_user'
}
$content = [IO.File]::ReadAllText($path)
foreach ($name in $settings.Keys) {
    $pattern = '(?m)^' + [regex]::Escape($name) + '=.*$'
    $line = "$name=$($settings[$name])"
    if ([regex]::IsMatch($content, $pattern)) { $content = [regex]::Replace($content, $pattern, $line) }
    else { $content = $content.TrimEnd() + "`n$line`n" }
}
[IO.File]::WriteAllText($path, $content.TrimEnd() + "`n", (New-Object Text.UTF8Encoding($false)))
Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\console'), 'cache:clear', '--env=dev', '--no-warmup')
Write-Host 'Konfigurace Entra ulozena. Restartujte backend. Klientske tajemstvi neni potreba.' -ForegroundColor Green
