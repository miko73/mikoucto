[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$config = Read-EntraConfig
$me = Assert-EntraSession $config
$app = Get-EntraApp $config.ApiClientId; $scope = Get-EntraScope $app
foreach ($client in @($config.SpaClientId,$config.DesktopClientId)) {
    $entries = @(Get-EntraProperty $app.api 'preAuthorizedApplications' @() | Where-Object { $_.appId -eq $client -and @($_.delegatedPermissionIds) -contains $scope.id })
    Assert-Entra ($entries.Count -eq 1) "Klient $client neni predautorizovan pro access_as_user. Provedte 07."
}
foreach ($client in @($config.ApiClientId,$config.SpaClientId,$config.DesktopClientId)) {
    $principal = Get-EntraServicePrincipal $client
    Assert-Entra ($null -ne $principal -and $principal.accountEnabled) "Podnikova aplikace $client chybi nebo je zakazana."
    if ($principal.appRoleAssignmentRequired) {
        $assignments = @(Get-EntraCollection ("servicePrincipals/$($principal.id)/appRoleAssignedTo?" + '$select=principalId'))
        Assert-Entra (@($assignments | Where-Object { $_.principalId -eq $me.id }).Count -gt 0) "Podnikova aplikace $client vyzaduje prirazeni. Tato kontrola ocekava prime prirazeni prihlaseneho uzivatele; spravce je muze pridat v portalu. Skupinove prirazeni tato sada nevyhodnocuje."
    }
}
Write-Host '08 OK: predautorizace vlastniho scope, aktivni podnikove aplikace a pripadne prime prirazeni uzivatele. Skutecny token overte skriptem dev/11.' -ForegroundColor Green
