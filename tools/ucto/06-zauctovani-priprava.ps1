[CmdletBinding()]
param([ValidateSet('Test','Development')][string]$Target='Test',[ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\01-osnova-obdobi.ps1" -Target $Target -DatabasePort $DatabasePort
Write-Host "Zauctovani a uzavreni obdobi pripraveno: $Target. Overeni: .\tools\ucto\07-zauctovani-test.ps1" -ForegroundColor Green
