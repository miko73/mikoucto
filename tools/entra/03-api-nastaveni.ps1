[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$config = Read-EntraConfig
[void](Assert-EntraSession $config)
$app = Get-OrCreateEntraApp $config.ApiClientId 'mikoucto-api'
Assert-Entra ($app.appId -ne $config.SpaClientId -and $app.appId -ne $config.DesktopClientId) 'API musi byt samostatna registrace.'
$config.ApiClientId = $app.appId; Save-EntraConfig $config
$api = ConvertTo-EntraMap $app.api
$scopes = @(Get-EntraProperty $app.api 'oauth2PermissionScopes' @() | Where-Object { $_ })
$found = @($scopes | Where-Object { $_.value -eq 'access_as_user' })
Assert-Entra ($found.Count -le 1) 'Duplicitni scope access_as_user; nejprve opravte registraci v portalu.'
if ($found.Count -eq 0) {
    $scopes += [pscustomobject]@{id=[guid]::NewGuid().ToString();value='access_as_user';type='Admin';isEnabled=$true;adminConsentDisplayName='Access mikoucto as the signed-in user';adminConsentDescription='Read and change mikoucto data within the user permissions.';userConsentDisplayName=$null;userConsentDescription=$null}
} else { $found[0].isEnabled = $true }
$api.oauth2PermissionScopes = $scopes
$api.requestedAccessTokenVersion = 2
$uris = @(@($app.identifierUris) + @("api://$($app.appId)") | Select-Object -Unique)
[void](Invoke-EntraGraph ("applications/$($app.id)") 'PATCH' @{identifierUris=$uris;api=$api})
Ensure-EntraServicePrincipal $app.appId
Write-Host '03 OK: API, URI a scope nastaveny. Pokracujte 04-overeni-api.ps1.' -ForegroundColor Green
