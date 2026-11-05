[CmdletBinding()]
param([switch]$InstallBrowser)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\..\dev\18-web-test.ps1" -InstallBrowser:$InstallBrowser
Write-Host 'Cast 7.4 OK: typy, sestaveni, jednotkove a prohlizecove testy ucetniho webu. Zive prihlaseni a DB overte podle analyza/ucetni_web.md.' -ForegroundColor Green
