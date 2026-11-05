[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort=5432)
$ErrorActionPreference='Stop'
& "$PSScriptRoot\04-backend-test.ps1"
& "$PSScriptRoot\07-db-test.ps1" -DatabasePort $DatabasePort
& "$PSScriptRoot\14-openapi.ps1"
Write-Host 'Faze 5 OK: partneri, validace, RLS, audit, If-Match a idempotence.' -ForegroundColor Green
