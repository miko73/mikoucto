[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$config = Read-EntraConfig
$me = Assert-EntraSession $config
$spa = Get-EntraApp $config.SpaClientId
Write-Host "Tenant: $($config.TenantId)"
Write-Host "Uzivatel: $($me.displayName); Object ID: $($me.id)"
Write-Host "Web: $($spa.displayName); Client ID: $($spa.appId)"
Write-Host '02 OK: spravny tenant, uzivatelska relace a existujici single-tenant web. Kontrola nic nemeni v Entra.' -ForegroundColor Green
