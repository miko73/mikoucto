[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$sourceRoot = $PSScriptRoot
$fixture = Join-Path $script:EntraLocal ('tests\' + [guid]::NewGuid().ToString())
$fixtureScripts = Join-Path $fixture 'entra'
$fixtureProject = Join-Path $fixture 'project'
$script:Checks = 0
function Assert-Test([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "TEST SELHAL: $Message" }; $script:Checks++
}
function Get-State { return [IO.File]::ReadAllText((Join-Path $fixture 'graph.json')) | ConvertFrom-Json }
function Set-State($State) { [IO.File]::WriteAllText((Join-Path $fixture 'graph.json'),($State | ConvertTo-Json -Depth 50),(New-Object Text.UTF8Encoding($false))) }
function Invoke-Step([string]$Name) { & (Join-Path $fixtureScripts $Name) }
function Assert-Fails([string]$Name) {
    $failed = $false; try { Invoke-Step $Name } catch { $failed = $true }
    Assert-Test $failed "$Name mel odmitnout neplatny stav."
}
function Write-Fixture([string]$Path,[string]$Text) {
    [void][IO.Directory]::CreateDirectory((Split-Path $Path -Parent))
    # Windows PowerShell 5.1 needs a BOM for generated scripts containing Unicode paths.
    [IO.File]::WriteAllText($Path,$Text,(New-Object Text.UTF8Encoding($Path.EndsWith('.ps1'))))
}
try {
    [void][IO.Directory]::CreateDirectory($fixtureScripts)
    $tenant='11111111-1111-4111-8111-111111111111'; $spa='22222222-2222-4222-8222-222222222222'
    $otherResource='33333333-3333-4333-8333-333333333333'; $otherScope='55555555-5555-4555-8555-555555555555'
    $original = [pscustomobject]@{id='66666666-6666-4666-8666-666666666666';appId=$spa;displayName='mikoucto-web';signInAudience='AzureADMyOrg';identifierUris=@();api=[pscustomobject]@{oauth2PermissionScopes=@()};spa=[pscustomobject]@{redirectUris=@('http://localhost:8005/redirect.html','http://localhost:9000/redirect.html')};web=[pscustomobject]@{redirectUris=@();implicitGrantSettings=[pscustomobject]@{enableAccessTokenIssuance=$true;enableIdTokenIssuance=$true}};publicClient=[pscustomobject]@{redirectUris=@()};requiredResourceAccess=@([pscustomobject]@{resourceAppId=$otherResource;resourceAccess=@([pscustomobject]@{id=$otherScope;type='Scope'})})}
    Set-State ([pscustomobject]@{apps=@($original);principals=@();assignments=@();writes=0})
    $quote = { param($Value) "'" + $Value.Replace("'","''") + "'" }
    $stub = '. ' + (& $quote (Join-Path $sourceRoot 'common.ps1')) + "`n"
    $stub += '$script:EntraLocal = ' + (& $quote (Join-Path $fixture '.local')) + "`n"
    $stub += '$script:EntraConfigPath = ' + (& $quote (Join-Path $fixture '.local\config.json')) + "`n"
    $stub += '$script:EntraProjectRoot = ' + (& $quote $fixtureProject) + "`n"
    $stub += '$script:FixtureGraph = ' + (& $quote (Join-Path $fixture 'graph.json')) + "`n"
    $stub += '$script:FixtureTenant = ' + (& $quote $tenant) + "`n"
    $stub += '. ' + (& $quote (Join-Path $sourceRoot 'tests\graph-mock.ps1')) + "`n"
    Write-Fixture (Join-Path $fixtureScripts 'common.ps1') $stub
    Get-ChildItem -LiteralPath $sourceRoot -Filter '*.ps1' -File | Where-Object { $_.Name -match '^(0[2-9]|10)-' } | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $fixtureScripts }
    $configPath = Join-Path $fixture '.local\config.json'
    Write-Fixture $configPath ([pscustomobject]@{Version=1;TenantId=$tenant;SpaClientId=$spa;ApiClientId='';DesktopClientId=''} | ConvertTo-Json)
    Invoke-Step '02-overeni-prihlaseni.ps1'
    Assert-Test ((Get-State).writes -eq 0) 'Kontrola prihlaseni nesmi zapisovat.'
    Assert-Fails '04-overeni-api.ps1'
    Invoke-Step '03-api-nastaveni.ps1'; Invoke-Step '04-overeni-api.ps1'
    $state = Get-State; $api = @($state.apps | Where-Object { $_.displayName -eq 'mikoucto-api' })[0]
    $apiId = $api.appId; $scopeId = $api.api.oauth2PermissionScopes[0].id
    $api.api.oauth2PermissionScopes += [pscustomobject]@{id=$otherScope;value='other_scope';isEnabled=$true;type='Admin'}
    $api.api.preAuthorizedApplications += [pscustomobject]@{appId=$otherResource;delegatedPermissionIds=@($otherScope)}
    Set-State $state
    Invoke-Step '03-api-nastaveni.ps1'
    $state = Get-State; $api = @($state.apps | Where-Object { $_.appId -eq $apiId })[0]
    Assert-Test ($state.apps.Count -eq 2) 'Opakovani API nesmi vytvorit duplicitu.'
    Assert-Test ($api.api.oauth2PermissionScopes[0].id -eq $scopeId) 'Scope ID musi zustat stejne.'
    Assert-Test ($api.api.oauth2PermissionScopes.Count -eq 2) 'Jiny scope se musi zachovat.'
    Assert-Test ($api.api.preAuthorizedApplications.Count -eq 1) 'Jina predautorizace se musi zachovat.'
    Invoke-Step '05-klienti-nastaveni.ps1'; Invoke-Step '06-overeni-klientu.ps1'
    Invoke-Step '05-klienti-nastaveni.ps1'; Invoke-Step '06-overeni-klientu.ps1'
    $state = Get-State
    Assert-Test ($state.apps.Count -eq 3 -and $state.principals.Count -eq 3) 'Opakovani klientu nesmi duplikovat aplikace ani SP.'
    $web = @($state.apps | Where-Object { $_.appId -eq $spa })[0]
    Assert-Test ($web.spa.redirectUris.Count -eq 2) 'Dalsi redirect URI se zachova bez duplikace.'
    Assert-Test ($web.requiredResourceAccess.Count -eq 2) 'Existujici opravneni k jinym API se zachova.'
    Assert-Test (@($web.requiredResourceAccess | Where-Object { $_.resourceAppId -eq $apiId })[0].resourceAccess.Count -eq 1) 'Scope se do klienta nepridava opakovane.'
    Assert-Fails '08-overeni-predautorizace.ps1'
    Invoke-Step '07-predautorizace.ps1'; Invoke-Step '08-overeni-predautorizace.ps1'
    Invoke-Step '07-predautorizace.ps1'; Invoke-Step '08-overeni-predautorizace.ps1'
    $state = Get-State; $api = @($state.apps | Where-Object { $_.appId -eq $apiId })[0]
    Assert-Test ($api.api.preAuthorizedApplications.Count -eq 3) 'Predautorizace se nesmi duplikovat ani mazat cizi zaznam.'
    $before = $state.writes
    foreach ($check in @('02-overeni-prihlaseni.ps1','04-overeni-api.ps1','06-overeni-klientu.ps1','08-overeni-predautorizace.ps1')) { Invoke-Step $check }
    Assert-Test ((Get-State).writes -eq $before) 'Overovaci kroky nesmi menit Graph.'
    $api.api.requestedAccessTokenVersion=1; Set-State $state
    Assert-Fails '04-overeni-api.ps1'
    $api.api.requestedAccessTokenVersion=2; Set-State $state
    $state.principals[0].appRoleAssignmentRequired=$true; Set-State $state
    Assert-Fails '08-overeni-predautorizace.ps1'
    $state.assignments=@([pscustomobject]@{resourceId=$state.principals[0].id;principalId='44444444-4444-4444-8444-444444444444'}); Set-State $state
    Invoke-Step '08-overeni-predautorizace.ps1'
    Assert-Test ((Get-State).writes -eq $before) 'Kontrola primeho prirazeni nesmi zapisovat.'
    $state.principals[0].appRoleAssignmentRequired=$false; Set-State $state
    $config = [IO.File]::ReadAllText($configPath) | ConvertFrom-Json
    $config.TenantId='77777777-7777-4777-8777-777777777777'; Write-Fixture $configPath ($config | ConvertTo-Json)
    $before = (Get-State).writes
    Assert-Fails '03-api-nastaveni.ps1'
    Assert-Test ((Get-State).writes -eq $before) 'Jiny tenant se odmitne pred zapisem.'
    $config.TenantId=$tenant; Write-Fixture $configPath ($config | ConvertTo-Json)
    # Execute the actual existing local configuration scripts against fixture files.
    $dev = Join-Path $fixtureProject 'tools\dev'; [void][IO.Directory]::CreateDirectory($dev)
    foreach ($name in @('08-entra-konfigurace.ps1','16-web-konfigurace.ps1','web-common.ps1')) { Copy-Item -LiteralPath (Join-Path $script:EntraProjectRoot "tools\dev\$name") -Destination $dev }
    $devStub = '$script:BackendDir = ' + (& $quote (Join-Path $fixtureProject 'backend')) + "`nfunction Invoke-ProjectPhp { param([string[]]`$Arguments) }`n"
    Write-Fixture (Join-Path $dev 'common.ps1') $devStub
    Write-Fixture (Join-Path $fixtureProject 'backend\.env.local') "APP_SECRET=fixture-secret`nDATABASE_URL=fixture-database`nOTHER_SETTING=keep`nENTRA_ALLOWED_CLIENT_IDS=$otherResource`n"
    Write-Fixture (Join-Path $fixtureProject 'frontend\.env.local') "VITE_OTHER=keep`n"
    Invoke-Step '09-projekt-nastaveni.ps1'; Invoke-Step '10-overeni-projektu.ps1'
    $backend = Read-EntraEnv (Join-Path $fixtureProject 'backend\.env.local')
    Assert-Test ($backend.APP_SECRET -eq 'fixture-secret' -and $backend.DATABASE_URL -eq 'fixture-database' -and $backend.OTHER_SETTING -eq 'keep') 'Lokalni nastaveni musi zachovat ostatni hodnoty.'
    Assert-Test (@($backend.ENTRA_ALLOWED_CLIENT_IDS.Split(',')) -contains $otherResource) 'Dalsi povoleny klient musi zustat zachovany.'
    $webEnv = Read-EntraEnv (Join-Path $fixtureProject 'frontend\.env.local')
    Assert-Test ($webEnv.VITE_OTHER -eq 'keep' -and -not $webEnv.ContainsKey('APP_SECRET') -and -not $webEnv.ContainsKey('DATABASE_URL')) 'Web zachova vlastni nastaveni bez tajemstvi backendu.'
    Write-Fixture (Join-Path $fixtureProject 'frontend\.env.local') "VITE_ENTRA_TENANT_ID=wrong`n"
    Assert-Fails '10-overeni-projektu.ps1'
    Write-Host "Entra skripty OK: $script:Checks kontrol; izolovane fixture, zadne skutecne prihlaseni, zadna zmena v Entra ani DB." -ForegroundColor Green
} finally {
    $resolved = [IO.Path]::GetFullPath($fixture)
    $expectedRoot = [IO.Path]::GetFullPath((Join-Path $sourceRoot '.local\tests')) + [IO.Path]::DirectorySeparatorChar
    if ($resolved.StartsWith($expectedRoot,[StringComparison]::OrdinalIgnoreCase) -and (Test-Path -LiteralPath $resolved)) { Remove-Item -LiteralPath $resolved -Recurse -Force }
}
