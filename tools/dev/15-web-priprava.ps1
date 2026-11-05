[CmdletBinding()]
param()
. "$PSScriptRoot\web-common.ps1"
Invoke-WebNpm -Arguments @('ci')
Invoke-WebNpm -Arguments @('run', 'generate')
Write-Host 'Web pripraven. Pokracujte konfiguraci Entra podle analyza/web.md.' -ForegroundColor Green
