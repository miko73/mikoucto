Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:WebRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\frontend'))
function Invoke-WebNpm([string[]]$Arguments) {
    if (-not (Get-Command npm.cmd -ErrorAction SilentlyContinue)) { throw 'Nainstalujte Node.js 22.12 nebo novejsi (doporucena LTS verze).' }
    Push-Location $script:WebRoot
    try {
        & npm.cmd @Arguments
        if ($LASTEXITCODE -ne 0) { throw "Webovy prikaz selhal (kod $LASTEXITCODE)." }
    } finally { Pop-Location }
}
