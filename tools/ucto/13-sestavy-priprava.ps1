[CmdletBinding()]
param([ValidateSet('Test')][string]$Target='Test',[ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference='Stop'
# 8.1 needs no new schema. Prepare the current test schema only.
& "$PSScriptRoot\01-osnova-obdobi.ps1" -Target $Target -DatabasePort $DatabasePort
Write-Host 'Sestavy 8.1/8.2: testovaci schema pripraveno. Nova migrace neni potreba. Overeni: .\tools\ucto\14-sestavy-test.ps1'
