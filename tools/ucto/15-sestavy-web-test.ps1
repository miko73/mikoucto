[CmdletBinding()]
param([switch]$InstallBrowser)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\..\dev\18-web-test.ps1" -InstallBrowser:$InstallBrowser
Write-Host 'Cast 8.3 OK: sestavy, filtry, soucty, detail dokladu, CSV a webove regrese na desktopu i mobilu. Zivy pruchod overte podle analyza/sestavy_web.md.' -ForegroundColor Green
