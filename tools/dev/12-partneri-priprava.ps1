[CmdletBinding()]
param([ValidateSet('Test','Development')][string]$Target='Test',[ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\02-backend-instalace.ps1"
& "$PSScriptRoot\06-migrace.ps1" -Target $Target -Action Migrate -DatabasePort $DatabasePort
& "$PSScriptRoot\06-migrace.ps1" -Target $Target -Action UpToDate -DatabasePort $DatabasePort
Write-Host "Partneri pripraveni: $Target. Pokracujte 13-partneri-test.ps1." -ForegroundColor Green
