# Test-only Graph replacement, loaded only from the temporary test fixture.
function Invoke-EntraAz([string[]]$Arguments) {
    if (($Arguments -join ' ') -ne 'account show') { throw 'Test nesmi volat skutecne Azure CLI.' }
    return [pscustomobject]@{tenantId=$script:FixtureTenant}
}
function Get-EntraAz { throw 'Test nesmi spoustet interaktivni prihlaseni.' }
function Invoke-EntraGraph([string]$Path, [string]$Method='GET', $Body=$null) {
    $state = [IO.File]::ReadAllText($script:FixtureGraph) | ConvertFrom-Json
    $pathText = [Uri]::UnescapeDataString($Path)
    $answer = $null
    if ($Method -eq 'GET' -and $Path -like 'me?*') { return [pscustomobject]@{id='44444444-4444-4444-8444-444444444444';displayName='Test user'} }
    if ($Method -eq 'GET' -and $Path -match '^servicePrincipals/([^/]+)/appRoleAssignedTo\?') {
        $principalId = $matches[1]
        return [pscustomobject]@{value=@($state.assignments | Where-Object { $_.resourceId -eq $principalId })}
    }
    if ($Method -eq 'GET' -and $pathText -match "^applications\(appId='([^']+)'\)") {
        $answer = @($state.apps | Where-Object { $_.appId -eq $matches[1] })
        if ($answer.Count -ne 1) { throw 'Aplikace nebyla nalezena.' }; return $answer[0]
    }
    if ($Method -eq 'GET' -and $pathText -match "^applications\?\`$filter=displayName eq '([^']+)'") {
        return [pscustomobject]@{value=@($state.apps | Where-Object { $_.displayName -eq $matches[1] })}
    }
    if ($Method -eq 'GET' -and $pathText -match "^servicePrincipals\?\`$filter=appId eq '([^']+)'") {
        return [pscustomobject]@{value=@($state.principals | Where-Object { $_.appId -eq $matches[1] })}
    }
    if ($Method -eq 'POST' -and $Path -eq 'applications') {
        $answer = [pscustomobject]@{id=[guid]::NewGuid().ToString();appId=[guid]::NewGuid().ToString();displayName=$Body.displayName;signInAudience=$Body.signInAudience;identifierUris=@();api=[pscustomobject]@{requestedAccessTokenVersion=$null;oauth2PermissionScopes=@();preAuthorizedApplications=@()};spa=[pscustomobject]@{redirectUris=@()};web=[pscustomobject]@{redirectUris=@();implicitGrantSettings=[pscustomobject]@{enableAccessTokenIssuance=$false;enableIdTokenIssuance=$false}};publicClient=[pscustomobject]@{redirectUris=@()};requiredResourceAccess=@()}
        foreach ($key in $Body.Keys) { $answer | Add-Member -NotePropertyName $key -NotePropertyValue $Body[$key] -Force }
        $state.apps = @($state.apps) + @($answer)
    } elseif ($Method -eq 'POST' -and $Path -eq 'servicePrincipals') {
        $answer = [pscustomobject]@{id=[guid]::NewGuid().ToString();appId=$Body.appId;accountEnabled=$true;appRoleAssignmentRequired=$false}
        $state.principals = @($state.principals) + @($answer)
    } elseif ($Method -eq 'PATCH' -and $Path -match '^applications/([^?]+)$') {
        $app = @($state.apps | Where-Object { $_.id -eq $matches[1] })
        if ($app.Count -ne 1) { throw 'Neznamy objekt aplikace.' }
        foreach ($key in $Body.Keys) { $app[0] | Add-Member -NotePropertyName $key -NotePropertyValue $Body[$key] -Force }
    } else { throw "Neocekavana testovaci Graph operace: $Method $Path" }
    $state.writes = $state.writes + 1
    [IO.File]::WriteAllText($script:FixtureGraph,($state | ConvertTo-Json -Depth 50),(New-Object Text.UTF8Encoding($false)))
    if ($answer) { return $answer | ConvertTo-Json -Depth 50 | ConvertFrom-Json }
}
