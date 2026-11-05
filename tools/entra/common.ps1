Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:EntraRoot = $PSScriptRoot
$script:EntraProjectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$script:EntraLocal = Join-Path $PSScriptRoot '.local'
$script:EntraConfigPath = Join-Path $script:EntraLocal 'config.json'
$script:AppFields = 'id,appId,displayName,signInAudience,identifierUris,api,spa,web,publicClient,requiredResourceAccess'

function Assert-Entra([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}
function Test-EntraGuid($Value) {
    $parsed = [guid]::Empty
    return [guid]::TryParse([string]$Value, [ref]$parsed) -and $parsed -ne [guid]::Empty
}
function Get-EntraProperty($Object, [string]$Name, $Default = $null) {
    if ($null -ne $Object -and $null -ne $Object.PSObject.Properties[$Name]) { return $Object.$Name }
    return $Default
}
function ConvertTo-EntraMap($Object) {
    $map = [ordered]@{}
    if ($null -ne $Object) { foreach ($property in $Object.PSObject.Properties) { $map[$property.Name] = $property.Value } }
    return $map
}
function Read-EntraConfig {
    Assert-Entra (Test-Path -LiteralPath $script:EntraConfigPath) 'Nejprve spustte 01-prihlaseni.ps1.'
    $config = [IO.File]::ReadAllText($script:EntraConfigPath) | ConvertFrom-Json
    Assert-Entra ($config.Version -eq 1) 'Nepodporovana verze konfigurace.'
    foreach ($key in @('TenantId','SpaClientId')) { Assert-Entra (Test-EntraGuid $config.$key) "Chybi platne $key." }
    foreach ($key in @('ApiClientId','DesktopClientId')) {
        if ($config.$key) { Assert-Entra (Test-EntraGuid $config.$key) "Neplatne $key." }
    }
    $ids = @($config.SpaClientId,$config.ApiClientId,$config.DesktopClientId | Where-Object { $_ })
    Assert-Entra (@($ids | Select-Object -Unique).Count -eq $ids.Count) 'API, SPA a desktop musi mit ruzna Client ID.'
    return $config
}
function Save-EntraConfig($Config) {
    [void][IO.Directory]::CreateDirectory($script:EntraLocal)
    [IO.File]::WriteAllText($script:EntraConfigPath, ($Config | ConvertTo-Json -Depth 10) + "`n", (New-Object Text.UTF8Encoding($false)))
}
function Get-EntraAz {
    $command = Get-Command az.cmd -ErrorAction SilentlyContinue
    if (-not $command) { $command = Get-Command az -ErrorAction SilentlyContinue }
    Assert-Entra ($null -ne $command) 'Chybi Azure CLI. Instalace: https://learn.microsoft.com/cli/azure/install-azure-cli-windows'
    return $command.Source
}
function Get-EntraAzCommand {
    $path = Get-EntraAz
    if ([IO.Path]::GetExtension($path) -in @('.cmd','.bat')) {
        # The MSI az.cmd expands %* inside an IF block. cmd.exe then interprets
        # Graph URL parentheses and ampersands as shell syntax. Bypass the batch
        # wrapper and use its exact bundled Python entry point instead.
        $python = [IO.Path]::GetFullPath((Join-Path (Split-Path $path -Parent) '..\python.exe'))
        Assert-Entra (Test-Path -LiteralPath $python -PathType Leaf) 'Azure CLI batch wrapper nema pribaleny python.exe. Pouzijte oficialni instalaci Azure CLI pro Windows.'
        return [pscustomobject]@{Executable=$python;Prefix=@('-IBm','azure.cli')}
    }
    return [pscustomobject]@{Executable=$path;Prefix=@()}
}
function Invoke-EntraAz([string[]]$Arguments) {
    $command = Get-EntraAzCommand
    $nativeArguments = @($command.Prefix) + $Arguments + @('--only-show-errors','--output','json')
    $previous = $env:AZURE_CONFIG_DIR
    try {
        # Isolated CLI profile; never change the user's other Azure sessions.
        $env:AZURE_CONFIG_DIR = Join-Path $script:EntraLocal 'azure'
        [void][IO.Directory]::CreateDirectory($env:AZURE_CONFIG_DIR)
        $oldPreference = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try { $output = @(& $command.Executable @nativeArguments 2>&1); $code = $LASTEXITCODE }
        finally { $ErrorActionPreference = $oldPreference }
        if ($code -ne 0) { throw "Azure CLI selhalo. Overte prihlaseni (01), prava k registracim a tenant. Detail: $($output -join [Environment]::NewLine)" }
        $json = ($output | ForEach-Object { $_.ToString() }) -join "`n"
        if ($json.Trim()) { return $json | ConvertFrom-Json }
    } finally { $env:AZURE_CONFIG_DIR = $previous }
}
function Invoke-EntraGraph([string]$Path, [ValidateSet('GET','POST','PATCH')][string]$Method = 'GET', $Body = $null) {
    $url = if ($Path.StartsWith('https://')) { $Path } else { 'https://graph.microsoft.com/v1.0/' + $Path }
    Assert-Entra ($url.StartsWith('https://graph.microsoft.com/v1.0/')) 'Nepovolena adresa Microsoft Graph.'
    $arguments = @('rest','--method',$Method,'--url',$url,'--resource','https://graph.microsoft.com/')
    $file = $null
    try {
        if ($null -ne $Body) {
            [void][IO.Directory]::CreateDirectory($script:EntraLocal)
            $file = Join-Path $script:EntraLocal ([guid]::NewGuid().ToString() + '.json')
            [IO.File]::WriteAllText($file, ($Body | ConvertTo-Json -Depth 40), (New-Object Text.UTF8Encoding($false)))
            $arguments += @('--headers','Content-Type=application/json','--body',"@$file")
        }
        return Invoke-EntraAz -Arguments $arguments
    } finally { if ($file -and (Test-Path -LiteralPath $file)) { Remove-Item -LiteralPath $file -Force } }
}
function Get-EntraCollection([string]$Path) {
    do {
        $result = Invoke-EntraGraph $Path
        foreach ($item in $result.value) { $item }
        $Path = [string](Get-EntraProperty $result '@odata.nextLink' '')
    } while ($Path)
}
function Assert-EntraSession($Config) {
    $account = Invoke-EntraAz -Arguments @('account','show')
    Assert-Entra ($account.tenantId -eq $Config.TenantId) 'CLI je v jinem tenantu. Spustte znovu 01 se spravnym TenantId.'
    $me = Invoke-EntraGraph 'me?$select=id,displayName,userPrincipalName'
    Assert-Entra (Test-EntraGuid $me.id) 'Nelze zjistit prihlaseneho uzivatele Microsoft Graph.'
    return $me
}
function Get-EntraApp([string]$ClientId) {
    Assert-Entra (Test-EntraGuid $ClientId) 'Chybi Client ID. Nejprve provedte predchozi nastavovaci krok.'
    $app = Invoke-EntraGraph ("applications(appId='$ClientId')?" + '$select=' + $script:AppFields)
    Assert-Entra ($app.appId -eq $ClientId) 'Graph vratil jinou registraci.'
    Assert-Entra ($app.signInAudience -eq 'AzureADMyOrg') 'Registrace musi byt single tenant (AzureADMyOrg). Typ existujici registrace se automaticky nemeni.'
    return $app
}
function Get-OrCreateEntraApp([string]$ClientId, [string]$Name, $Properties = @{}) {
    if ($ClientId) { return Get-EntraApp $ClientId }
    $filter = [Uri]::EscapeDataString("displayName eq '$($Name.Replace("'","''"))'")
    $found = @(Get-EntraCollection ('applications?$filter=' + $filter + '&$select=' + $script:AppFields))
    Assert-Entra ($found.Count -le 1) "Existuje vice registraci $Name. Zadejte konkretni Client ID skriptem 01."
    if ($found.Count -eq 1) { return Get-EntraApp $found[0].appId }
    $body = @{ displayName=$Name; signInAudience='AzureADMyOrg' }
    foreach ($key in $Properties.Keys) { $body[$key] = $Properties[$key] }
    $created = Invoke-EntraGraph 'applications' 'POST' $body
    return Get-EntraApp $created.appId
}
function Get-EntraScope($Api) {
    $scopes = @(Get-EntraProperty $Api.api 'oauth2PermissionScopes' @() | Where-Object { $_ -and $_.value -eq 'access_as_user' })
    Assert-Entra ($scopes.Count -eq 1 -and $scopes[0].isEnabled) 'Chybi jediny aktivni scope access_as_user. Provedte 03 a 04.'
    return $scopes[0]
}
function Get-EntraServicePrincipal([string]$ClientId) {
    $filter = [Uri]::EscapeDataString("appId eq '$ClientId'")
    $items = @(Get-EntraCollection ('servicePrincipals?$filter=' + $filter + '&$select=id,appId,appRoleAssignmentRequired,accountEnabled'))
    Assert-Entra ($items.Count -le 1) 'Nejednoznacny service principal.'
    if ($items.Count -eq 1) { return $items[0] }
}
function Ensure-EntraServicePrincipal([string]$ClientId) {
    $principal = Get-EntraServicePrincipal $ClientId
    if (-not $principal) { [void](Invoke-EntraGraph 'servicePrincipals' 'POST' @{appId=$ClientId}) }
}
function Merge-EntraResourceAccess($Existing, [string]$ApiClientId, [string]$ScopeId) {
    $result = @(); $matched = $false
    foreach ($resource in @($Existing | Where-Object { $_ })) {
        if ($resource.resourceAppId -ne $ApiClientId) { $result += $resource; continue }
        $matched = $true
        $copy = ConvertTo-EntraMap $resource
        $access = @($resource.resourceAccess | Where-Object { $_ })
        if (-not @($access | Where-Object { $_.id -eq $ScopeId -and $_.type -eq 'Scope' }).Count) { $access += @{id=$ScopeId;type='Scope'} }
        $copy.resourceAccess = $access; $result += $copy
    }
    if (-not $matched) { $result += @{resourceAppId=$ApiClientId;resourceAccess=@(@{id=$ScopeId;type='Scope'})} }
    return ,$result
}
function Merge-EntraPreauthorization($Existing, [string[]]$ClientIds, [string]$ScopeId) {
    $result = @($Existing | Where-Object { $_ })
    foreach ($client in $ClientIds) {
        $found = @($result | Where-Object { $_.appId -eq $client })
        Assert-Entra ($found.Count -le 1) 'Duplicitni predautorizovany klient.'
        if ($found.Count -eq 0) { $result += [pscustomobject]@{appId=$client;delegatedPermissionIds=@($ScopeId)} }
        else {
            $replacement = [pscustomobject]@{appId=$client;delegatedPermissionIds=@(@($found[0].delegatedPermissionIds) + @($ScopeId) | Select-Object -Unique)}
            $result = @($result | ForEach-Object { if ($_.appId -eq $client) { $replacement } else { $_ } })
        }
    }
    return ,$result
}
function Read-EntraEnv([string]$Path) {
    Assert-Entra (Test-Path -LiteralPath $Path) "Chybi soubor $Path. Provedte 09."
    $map = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        if ($line -match '^([A-Z][A-Z0-9_]*)=(.*)$') { $map[$matches[1]] = $matches[2].Trim().Trim('"').Trim("'") }
    }
    return $map
}
