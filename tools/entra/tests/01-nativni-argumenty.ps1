# Regression check for Windows PowerShell 5.1 -> Azure CLI MSI invocation.
# No authentication or network request is performed.
[CmdletBinding()]
param()
. "$PSScriptRoot\..\common.ps1"
$command = Get-EntraAzCommand
if ([IO.Path]::GetFileName($command.Executable) -ne 'python.exe') {
    Write-Host 'Test MSI argumentu preskocen: CLI nepouziva Windows Python wrapper.'
    return
}
$url = "https://graph.microsoft.com/v1.0/applications(appId='22222222-2222-4222-8222-222222222222')?" + '$select=id,appId&$filter=displayName%20eq%20%27mikoucto-web%27'
$file = '@' + (Join-Path $script:EntraLocal 'request body.json')
$expected = @('--url',$url,'--body',$file)
$probeArguments = @('-I','-c','import json,sys; print(json.dumps(sys.argv[1:],ensure_ascii=True))') + $expected
$json = & $command.Executable @probeArguments
Assert-Entra ($LASTEXITCODE -eq 0) 'Nativni argumentovy test selhal.'
$actual = $json | ConvertFrom-Json
Assert-Entra ($actual.Count -eq $expected.Count) 'Zmenil se pocet nativnich argumentu.'
for ($index=0; $index -lt $expected.Count; $index++) {
    Assert-Entra ($actual[$index] -ceq $expected[$index]) "Argument $index se cestou do procesu zmenil."
}
$previous = $env:AZURE_CONFIG_DIR
$version = Invoke-EntraAz -Arguments @('version')
Assert-Entra ($null -ne $version.PSObject.Properties['azure-cli']) 'Azure CLI nevratilo platny JSON.'
Assert-Entra ($env:AZURE_CONFIG_DIR -ceq $previous) 'CLI profil nebyl po volani obnoven.'
Write-Host 'Nativni argumenty OK: zavorky, apostrofy, $select, &, procenta a cesta s mezerami/diakritikou; Azure CLI JSON a obnova profilu.' -ForegroundColor Green
