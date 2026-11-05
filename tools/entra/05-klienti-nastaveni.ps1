[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$config = Read-EntraConfig
[void](Assert-EntraSession $config)
$api = Get-EntraApp $config.ApiClientId; $scope = Get-EntraScope $api
$spa = Get-EntraApp $config.SpaClientId
$desktop = Get-OrCreateEntraApp $config.DesktopClientId 'mikoucto-desktop' @{publicClient=@{redirectUris=@('http://localhost')}}
Assert-Entra ($desktop.appId -ne $api.appId -and $desktop.appId -ne $spa.appId) 'Desktop musi byt samostatna registrace.'
$config.DesktopClientId = $desktop.appId; Save-EntraConfig $config
foreach ($app in @($spa,$desktop)) {
    $body = @{requiredResourceAccess=(Merge-EntraResourceAccess $app.requiredResourceAccess $api.appId $scope.id)}
    if ($app.appId -eq $spa.appId) {
        $settings = ConvertTo-EntraMap $app.spa
        $settings.redirectUris = @(@(Get-EntraProperty $app.spa 'redirectUris' @()) + @('http://localhost:8005/redirect.html') | Select-Object -Unique)
        $body.spa = $settings
        $web = ConvertTo-EntraMap $app.web
        $web.implicitGrantSettings = @{enableAccessTokenIssuance=$false;enableIdTokenIssuance=$false}
        $body.web = $web
    } else {
        $settings = ConvertTo-EntraMap $app.publicClient
        $settings.redirectUris = @(@(Get-EntraProperty $app.publicClient 'redirectUris' @()) + @('http://localhost') | Select-Object -Unique)
        $body.publicClient = $settings
    }
    [void](Invoke-EntraGraph ("applications/$($app.id)") 'PATCH' $body)
    Ensure-EntraServicePrincipal $app.appId
}
Write-Host '05 OK: SPA, desktop a pozadovane delegovane opravneni nastaveny. Pokracujte 06-overeni-klientu.ps1.' -ForegroundColor Green
