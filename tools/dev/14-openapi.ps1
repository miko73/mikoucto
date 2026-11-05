[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
$directory=Join-Path $script:ProjectRoot 'shared\openapi'
New-Item -ItemType Directory -Path $directory -Force | Out-Null
$path=Join-Path $directory 'partners.json'
Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\console'),'cache:clear','--env=dev','--no-warmup')
Invoke-ProjectPhp -Arguments @((Join-Path $script:BackendDir 'bin\console'),'api:openapi:export','--spec-version=3',"--output=$path")
$spec=[IO.File]::ReadAllText($path)|ConvertFrom-Json
if (-not $spec.paths.PSObject.Properties['/api/v1/accounting-units/{unit}/partners']) { throw 'V exportu chybi partnerske endpointy.' }
if ($spec.openapi -ne '3.1.0' -or -not $spec.components.securitySchemes.bearerAuth) { throw 'Chybi verze OpenAPI 3.1 nebo popis Bearer autentizace.' }
Write-Host "OpenAPI export OK: $path" -ForegroundColor Green
