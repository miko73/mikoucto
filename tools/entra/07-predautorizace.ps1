[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$config = Read-EntraConfig
[void](Assert-EntraSession $config)
$app = Get-EntraApp $config.ApiClientId; $scope = Get-EntraScope $app
foreach ($client in @($config.SpaClientId,$config.DesktopClientId)) { [void](Get-EntraApp $client) }
$api = ConvertTo-EntraMap $app.api
$api.preAuthorizedApplications = Merge-EntraPreauthorization (Get-EntraProperty $app.api 'preAuthorizedApplications' @()) @($config.SpaClientId,$config.DesktopClientId) $scope.id
[void](Invoke-EntraGraph ("applications/$($app.id)") 'PATCH' @{api=$api})
Write-Host '07 OK: SPA a desktop predautorizovany pouze pro vlastni access_as_user. Pokracujte 08-overeni-predautorizace.ps1.' -ForegroundColor Green
