[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\test-common.ps1" -DatabasePort $DatabasePort -RequiredClasses @('App\Tests\Integration\AccountingTest','App\Tests\Integration\AccountingConcurrencyTest','App\Tests\Integration\DraftDocumentsTest','App\Tests\Integration\DraftConcurrencyTest','App\Tests\Integration\PostingTest','App\Tests\Integration\PostingConcurrencyTest','App\Tests\Integration\JournalTest') -SuccessMessage 'Cast 7.3 OK: zauctovani, cislovani, nemennost dokladu, uzavreni obdobi, soubeh a denik. Playground nebyl zmenen.'
