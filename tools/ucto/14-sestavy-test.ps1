[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\test-common.ps1" -DatabasePort $DatabasePort -RequiredClasses @('App\Tests\Integration\AccountingTest','App\Tests\Integration\AccountingConcurrencyTest','App\Tests\Integration\DraftDocumentsTest','App\Tests\Integration\DraftConcurrencyTest','App\Tests\Integration\PostingTest','App\Tests\Integration\PostingConcurrencyTest','App\Tests\Integration\JournalTest','App\Tests\Integration\ReportsTest','App\Tests\Integration\ReportsCsvTest') -SuccessMessage 'Cast 8.1/8.2 OK: hlavni kniha, predvaha, CSV, presne zustatky, opravneni, izolace a ucetni regrese. Playground nebyl zmenen.'
