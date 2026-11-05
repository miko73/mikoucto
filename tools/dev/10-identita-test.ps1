[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort = 5432)
$ErrorActionPreference = 'Stop'
# Kryptograficke/HTTP testy nepotrebuji registraci Entra; integracni cast jen mikoucto_test.
& "$PSScriptRoot\04-backend-test.ps1"
& "$PSScriptRoot\07-db-test.ps1" -DatabasePort $DatabasePort
Write-Host 'Faze 4 OK: podpisy JWT, autentizace, role, izolace jednotek a audit.' -ForegroundColor Green
