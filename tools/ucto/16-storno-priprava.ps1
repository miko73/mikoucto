[CmdletBinding()]
param([ValidateSet('Test')][string]$Target='Test',[ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\01-osnova-obdobi.ps1" -Target $Target -DatabasePort $DatabasePort
Write-Host 'Storno 9.1: testovaci schema pripraveno. Overeni: .\tools\ucto\17-storno-test.ps1'
