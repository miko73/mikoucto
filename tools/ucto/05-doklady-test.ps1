[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference = 'Stop'
& "$PSScriptRoot\test-common.ps1" -DatabasePort $DatabasePort -RequiredClasses @('App\Tests\Integration\AccountingTest','App\Tests\Integration\AccountingConcurrencyTest','App\Tests\Integration\DraftDocumentsTest','App\Tests\Integration\DraftConcurrencyTest') -SuccessMessage 'Cast 7.2 OK: rozpracovane doklady, radky MD/DAL, presne castky, audit, verze, role a soubeh. Playground nebyl zmenen.'