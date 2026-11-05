[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\test-common.ps1" -DatabasePort $DatabasePort -RequiredClasses @('App\Tests\Integration\AccountingTest','App\Tests\Integration\AccountingConcurrencyTest','App\Tests\Integration\DraftDocumentsTest','App\Tests\Integration\DraftConcurrencyTest','App\Tests\Integration\PostingTest','App\Tests\Integration\PostingConcurrencyTest','App\Tests\Integration\JournalTest','App\Tests\Integration\ReportsTest','App\Tests\Integration\ReportsCsvTest','App\Tests\Integration\ReversalTest','App\Tests\Integration\ReversalConcurrencyTest','App\Tests\Integration\CorrectionTest','App\Tests\Integration\CorrectionConcurrencyTest') -SuccessMessage 'Backend opravneho navrhu a ucetni regrese OK.'
Push-Location (Join-Path $PSScriptRoot '..\..\frontend')
try {
    & npm.cmd run generate
    if ($LASTEXITCODE -ne 0) { throw 'Generovani webovych typu selhalo.' }
    & npm.cmd run typecheck
    if ($LASTEXITCODE -ne 0) { throw 'Kontrola webovych typu selhala.' }
}
finally { Pop-Location }
Write-Host 'Cast 9.2 OK: opravny navrh, upravy, zauctovani, vazby, audit, opakovani, soubeh, sestavy, regrese a webove typy. Playground nebyl zmenen.' -ForegroundColor Green
