[CmdletBinding()]
param([ValidateSet('Test')][string]$Target='Test',[ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\01-osnova-obdobi.ps1" -Target $Target -DatabasePort $DatabasePort
Write-Host 'Opravny navrh 9.2: testovaci schema pripraveno. Overeni: .\tools\ucto\19-opravny-navrh-test.ps1'
