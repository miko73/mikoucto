[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort = 5432)
$ErrorActionPreference = 'Stop'
& "$PSScriptRoot\test-common.ps1" -DatabasePort $DatabasePort -RequiredClasses @('App\Tests\Integration\AccountingTest','App\Tests\Integration\AccountingConcurrencyTest') -SuccessMessage 'Cast 7.1 OK: ucty, obdobi, role, RLS, audit, verze, idempotence a soubeh. Playground nebyl zmenen.'