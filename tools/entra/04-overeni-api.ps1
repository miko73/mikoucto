[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$config = Read-EntraConfig
[void](Assert-EntraSession $config)
$app = Get-EntraApp $config.ApiClientId
Assert-Entra ($app.api.requestedAccessTokenVersion -eq 2) 'API nema verzi tokenu 2.'
Assert-Entra (@($app.identifierUris) -contains "api://$($app.appId)") 'Chybi ocekavane API URI.'
$scope = Get-EntraScope $app
Assert-Entra ($null -ne (Get-EntraServicePrincipal $app.appId)) 'Chybi service principal API. Provedte 03.'
Write-Host "API Client ID: $($app.appId)"
Write-Host "Scope: api://$($app.appId)/access_as_user; Scope ID: $($scope.id)"
Write-Host '04 OK: single tenant, API URI, verze tokenu 2, aktivni scope a service principal.' -ForegroundColor Green
