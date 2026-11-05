[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$config = Read-EntraConfig
# Require all preceding cloud checks immediately before local configuration.
foreach ($name in @('02-overeni-prihlaseni.ps1','04-overeni-api.ps1','06-overeni-klientu.ps1','08-overeni-predautorizace.ps1')) {
    & (Join-Path $PSScriptRoot $name)
}
$backend = Join-Path $script:EntraProjectRoot 'backend\.env.local'
$existing = Read-EntraEnv $backend
Assert-Entra ($existing.ContainsKey('APP_SECRET') -and $existing.APP_SECRET) 'Chybi APP_SECRET. Nejprve provedte tools/dev/02-backend-instalace.ps1.'
# Preserve additional clients allowed by the previous local configuration.
$allowed = @($config.SpaClientId)
if ($existing.ContainsKey('ENTRA_ALLOWED_CLIENT_IDS')) { $allowed += @($existing.ENTRA_ALLOWED_CLIENT_IDS.Split(',') | Where-Object { $_.Trim() } | ForEach-Object { [guid]$_.Trim() }) }
& (Join-Path $script:EntraProjectRoot 'tools\dev\08-entra-konfigurace.ps1') -TenantId $config.TenantId -ApiClientId $config.ApiClientId -PublicClientId $config.DesktopClientId -AllowedClientIds $allowed
& (Join-Path $script:EntraProjectRoot 'tools\dev\16-web-konfigurace.ps1') -TenantId $config.TenantId -ApiClientId $config.ApiClientId -SpaClientId $config.SpaClientId
Write-Host '09 OK: mistni konfigurace ulozena. Restartujte backend i web a provedte 10-overeni-projektu.ps1.' -ForegroundColor Green
