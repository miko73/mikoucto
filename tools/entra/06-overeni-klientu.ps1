[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$config = Read-EntraConfig
[void](Assert-EntraSession $config)
$api = Get-EntraApp $config.ApiClientId; $scope = Get-EntraScope $api
foreach ($client in @($config.SpaClientId,$config.DesktopClientId)) {
    $app = Get-EntraApp $client
    $resources = @($app.requiredResourceAccess | Where-Object { $_.resourceAppId -eq $api.appId })
    $permissions = @($resources | ForEach-Object { $_.resourceAccess } | Where-Object { $_.id -eq $scope.id -and $_.type -eq 'Scope' })
    Assert-Entra ($permissions.Count -ge 1) "Klient $client nema delegovany scope access_as_user. Provedte 05."
    Assert-Entra ($null -ne (Get-EntraServicePrincipal $client)) "Klient $client nema service principal. Provedte 05."
    if ($client -eq $config.SpaClientId) {
        Assert-Entra (@($app.spa.redirectUris) -contains 'http://localhost:8005/redirect.html') 'Chybi navratova adresa pod platformou SPA.'
        $implicit = Get-EntraProperty $app.web 'implicitGrantSettings'
        Assert-Entra (-not (Get-EntraProperty $implicit 'enableAccessTokenIssuance' $false) -and -not (Get-EntraProperty $implicit 'enableIdTokenIssuance' $false)) 'Implicitni tok ma byt vypnuty.'
    } else { Assert-Entra (@($app.publicClient.redirectUris) -contains 'http://localhost') 'Chybi desktopova navratova adresa.' }
}
Write-Host '06 OK: obe registrace, spravne platformy, URI a deklarace Scope. Souhlas overuje az krok 08.' -ForegroundColor Green
