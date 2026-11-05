[CmdletBinding()]
param(
    [Parameter(Mandatory)][guid]$TenantId,
    [Parameter(Mandatory)][guid]$SpaClientId,
    [guid]$ApiClientId = [guid]::Empty,
    [guid]$DesktopClientId = [guid]::Empty,
    [switch]$UseDeviceCode
)
. "$PSScriptRoot\common.ps1"
Assert-Entra ($TenantId -ne [guid]::Empty -and $SpaClientId -ne [guid]::Empty) 'Vyplnte skutecna ID tenantu a existujiciho webu.'
$config = [pscustomobject]@{Version=1;TenantId=$TenantId.ToString();SpaClientId=$SpaClientId.ToString();ApiClientId='';DesktopClientId=''}
if (Test-Path -LiteralPath $script:EntraConfigPath) {
    $old = Read-EntraConfig
    Assert-Entra ($old.TenantId -eq $config.TenantId -and $old.SpaClientId -eq $config.SpaClientId) 'Existujici mistni konfigurace patri jinemu tenantu/webu. Zkontrolujte .local/config.json.'
    $config = $old
}
if ($ApiClientId -ne [guid]::Empty) { $config.ApiClientId = $ApiClientId.ToString() }
if ($DesktopClientId -ne [guid]::Empty) { $config.DesktopClientId = $DesktopClientId.ToString() }
$ids = @($config.SpaClientId,$config.ApiClientId,$config.DesktopClientId | Where-Object { $_ })
Assert-Entra (@($ids | Select-Object -Unique).Count -eq $ids.Count) 'Kazda aplikace musi mit vlastni Client ID.'
$command = Get-EntraAzCommand
$previous = $env:AZURE_CONFIG_DIR
try {
    $env:AZURE_CONFIG_DIR = Join-Path $script:EntraLocal 'azure'
    [void][IO.Directory]::CreateDirectory($env:AZURE_CONFIG_DIR)
    $arguments = @($command.Prefix) + @('login','--tenant',$config.TenantId,'--allow-no-subscriptions','--output','none')
    if ($UseDeviceCode) { $arguments += '--use-device-code' }
    & $command.Executable @arguments
    if ($LASTEXITCODE -ne 0) { throw 'Prihlaseni selhalo. Zkuste -UseDeviceCode.' }
} finally { $env:AZURE_CONFIG_DIR = $previous }
[void](Assert-EntraSession $config)
[void](Get-EntraApp $config.SpaClientId)
Save-EntraConfig $config
Write-Host '01 OK: prihlaseni a verejna ID ulozena. Pokracujte 02-overeni-prihlaseni.ps1.' -ForegroundColor Green
