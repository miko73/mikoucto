[CmdletBinding()]
param([switch]$InstallBrowser)
. "$PSScriptRoot\web-common.ps1"
if ($InstallBrowser) {
    Push-Location $script:WebRoot
    try {
        & npx.cmd --no-install playwright install chromium
        if ($LASTEXITCODE -ne 0) { throw 'Instalace testovaciho prohlizece selhala.' }
    } finally { Pop-Location }
}
Invoke-WebNpm -Arguments @('run','generate')
Invoke-WebNpm -Arguments @('run','test')
Invoke-WebNpm -Arguments @('run','build')
Invoke-WebNpm -Arguments @('run','test:e2e')
Write-Host 'Etapa 6 OK (lokalni testy). Skutecne prihlaseni a zapis do Google DB overte podle analyza/web.md.' -ForegroundColor Green
