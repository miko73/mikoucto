[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$config = Read-EntraConfig
foreach ($key in @('ApiClientId','DesktopClientId')) { Assert-Entra (Test-EntraGuid $config.$key) "Chybi $key. Dokoncete predchozi kroky." }
$backend = Read-EntraEnv (Join-Path $script:EntraProjectRoot 'backend\.env.local')
$web = Read-EntraEnv (Join-Path $script:EntraProjectRoot 'frontend\.env.local')
foreach ($pair in @(@('ENTRA_TENANT_ID','TenantId'),@('ENTRA_API_CLIENT_ID','ApiClientId'),@('ENTRA_PUBLIC_CLIENT_ID','DesktopClientId'))) {
    Assert-Entra ($backend.ContainsKey($pair[0]) -and $backend[$pair[0]] -eq $config.($pair[1])) "Neshoda backend $($pair[0]). Provedte 09."
}
foreach ($pair in @(@('VITE_ENTRA_TENANT_ID','TenantId'),@('VITE_ENTRA_API_CLIENT_ID','ApiClientId'),@('VITE_ENTRA_SPA_CLIENT_ID','SpaClientId'))) {
    Assert-Entra ($web.ContainsKey($pair[0]) -and $web[$pair[0]] -eq $config.($pair[1])) "Neshoda web $($pair[0]). Provedte 09."
}
Assert-Entra ($backend.ContainsKey('APP_SECRET') -and $backend.APP_SECRET) 'Chybi APP_SECRET.'
Assert-Entra ($backend.ContainsKey('ENTRA_REQUIRED_SCOPE') -and $backend.ENTRA_REQUIRED_SCOPE -eq 'access_as_user') 'Chybi pozadovany scope.'
$allowed = if ($backend.ContainsKey('ENTRA_ALLOWED_CLIENT_IDS')) { @($backend.ENTRA_ALLOWED_CLIENT_IDS.Split(',') | ForEach-Object { $_.Trim() }) } else { @() }
foreach ($client in @($config.SpaClientId,$config.DesktopClientId)) { Assert-Entra ($allowed -contains $client) 'V backendu chybi povoleny klient.' }
foreach ($key in $web.Keys) { Assert-Entra ($key -notmatch '(?i)(SECRET|PASSWORD|TOKEN|DATABASE_URL)') 'Webova konfigurace obsahuje neocekavanou citlivou promennou; zkontrolujte ji rucne.' }
Write-Host '10 OK: mistni ID, scope, oba povoleni klienti a oddeleni webove konfigurace. Tajemstvi se nevypisuji.' -ForegroundColor Green
