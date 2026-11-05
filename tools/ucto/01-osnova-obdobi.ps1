[CmdletBinding()]
param(
    [ValidateSet('Test','Development')][string]$Target = 'Test',
    [ValidateRange(1,65535)][int]$DatabasePort = 5432
)
$ErrorActionPreference = 'Stop'
$dev = Join-Path $PSScriptRoot '..\dev'
& "$dev\06-migrace.ps1" -Target $Target -Action Migrate -DatabasePort $DatabasePort
& "$dev\06-migrace.ps1" -Target $Target -Action UpToDate -DatabasePort $DatabasePort
Write-Host "Ucty a obdobi pripraveny: $Target. Overeni: .\tools\ucto\02-osnova-obdobi-test.ps1" -ForegroundColor Green
