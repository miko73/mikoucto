[CmdletBinding()]
param()
. "$PSScriptRoot\..\dev\common.ps1"
$path = Join-Path $script:BackendDir ('var\accounting-openapi-' + [guid]::NewGuid().ToString('N') + '.json')
try {
    Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\console'),'cache:clear','--env=dev','--no-warmup')
    Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\console'),'api:openapi:export','--spec-version=3',"--output=$path")
    $spec = [IO.File]::ReadAllText($path) | ConvertFrom-Json
    foreach ($property in @($spec.paths.PSObject.Properties)) {
        if ($property.Name -notmatch '^/api/v1/accounting-units/\{unit\}/(accounts|periods|documents|journal|reports)(/|$)') { $spec.paths.PSObject.Properties.Remove($property.Name) }
    }
    if (@($spec.paths.PSObject.Properties).Count -ne 19 -or $spec.openapi -ne '3.1.0' -or -not $spec.components.securitySchemes.bearerAuth -or -not $spec.paths.'/api/v1/accounting-units/{unit}/journal'.get) { throw 'Neuplny ucetni OpenAPI kontrakt.' }
    $reverse=$spec.paths.'/api/v1/accounting-units/{unit}/documents/{id}/reverse'.post
    if (-not $reverse.responses.'201' -or -not $reverse.responses.'200' -or -not $reverse.responses.'412' -or -not $spec.paths.'/api/v1/accounting-units/{unit}/documents/{id}/relations'.get) { throw 'Chybi storno kontrakt.' }
    $correction=$spec.paths.'/api/v1/accounting-units/{unit}/documents/{id}/correction-draft'.post
    if (-not $correction.responses.'201' -or -not $correction.responses.'200' -or -not $correction.responses.'412') { throw 'Chybi kontrakt opravneho navrhu.' }
    foreach ($report in @('general-ledger','trial-balance')) {
        $reportPath='/api/v1/accounting-units/{unit}/reports/'+$report
        if (-not $spec.paths.$reportPath.get.responses.'200'.content.'application/json'.schema.'$ref') { throw "Chybi kontrakt sestavy: $report" }
        $csv=$spec.paths.($reportPath+'.csv').get
        if (-not $csv.responses.'200'.content.'text/csv' -or -not $csv.responses.'422') { throw "Chybi CSV kontrakt: $report" }
    }
    foreach ($kind in @('accounts','periods','documents')) {
        $base = '/api/v1/accounting-units/{unit}/' + $kind
        if (-not $spec.paths.$base.get -or -not $spec.paths.$base.post -or -not $spec.paths.($base + '/{id}').get -or -not $spec.paths.($base + '/{id}').patch -or -not $spec.paths.($base + '/{id}/history').get) { throw "Chybi operace API: $kind" }
    }
    foreach ($command in @('/api/v1/accounting-units/{unit}/documents/{id}/post','/api/v1/accounting-units/{unit}/periods/{id}/close')) {
        if (-not $spec.paths.$command.post.responses.'200') { throw "Chybi prikaz API: $command" }
    }
    $output = Join-Path $script:ProjectRoot 'shared\openapi\accounting.json'
    [IO.File]::WriteAllText($output, ($spec | ConvertTo-Json -Depth 100), (New-Object Text.UTF8Encoding($false)))
    Write-Host "Ucetni OpenAPI OK: $output"
}
finally { if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path } }
