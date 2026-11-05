[CmdletBinding()]
param(
    [ValidateRange(10, 600)][int]$TimeoutSeconds = 120,
    [switch]$BezProhlizece
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot

function Test-LocalPort([int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $connection = $client.ConnectAsync('127.0.0.1', $Port)
        if (-not $connection.Wait(300)) { return $false }
        return $client.Connected
    }
    catch { return $false }
    finally { $client.Dispose() }
}

function Start-Component([string]$Title, [string]$RelativeScript) {
    $scriptPath = Join-Path $projectRoot $RelativeScript
    $quotedRoot = $projectRoot.Replace("'", "''")
    $quotedScript = $scriptPath.Replace("'", "''")
    # EncodedCommand zachova mezery i diakritiku v ceste.
    $command = @"
`$Host.UI.RawUI.WindowTitle = '$Title'
Set-Location -LiteralPath '$quotedRoot'
try {
    & '$quotedScript'
}
catch {
    Write-Host (`$_ | Out-String) -ForegroundColor Red
}
"@
    $encodedCommand = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($command))
    $powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    return Start-Process -FilePath $powershell -WorkingDirectory $projectRoot -ArgumentList @(
        '-NoProfile', '-NoExit', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encodedCommand
    ) -WindowStyle Normal -PassThru
}

function Wait-Component([string]$Name, [scriptblock]$Ready, [System.Diagnostics.Process]$Process) {
    $timer = [Diagnostics.Stopwatch]::StartNew()
    while ($timer.Elapsed.TotalSeconds -lt $TimeoutSeconds) {
        if (& $Ready) { return }
        if ($null -ne $Process -and $Process.HasExited) {
            throw "$Name : okno bylo zavreno pred dokoncenim startu."
        }
        Start-Sleep -Milliseconds 500
    }
    throw "$Name : vyprsel limit $TimeoutSeconds sekund. Zkontrolujte vypis v prislusnem okne a pripadne dokoncete prihlaseni."
}

function Test-Http([string]$Url) {
    try {
        $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 3
        return $response.StatusCode -eq 200
    }
    catch { return $false }
}

try {
    foreach ($relativePath in @(
        'db\google\00-tunel.ps1',
        'tools\dev\03-backend.ps1',
        'tools\dev\17-web.ps1',
        'backend\vendor\autoload.php',
        'frontend\node_modules\vite\bin\vite.js',
        'backend\.env.local',
        'frontend\.env.local',
        'db\google\.secrets\playground.roles.clixml'
    )) {
        if (-not (Test-Path -LiteralPath (Join-Path $projectRoot $relativePath))) {
            throw "Chybi $relativePath. Dokoncete pripravu projektu podle README.md."
        }
    }
    if (-not (Get-Command npm.cmd -ErrorAction SilentlyContinue)) {
        throw 'Chybi Node.js/npm. Nainstalujte Node.js alespon 22.12 a otevrete novy PowerShell.'
    }
    if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'tools\php\php.exe')) -and
        -not (Get-Command php -ErrorAction SilentlyContinue)) {
        throw 'Chybi PHP. Spustte .\tools\dev\00-nastroje.ps1.'
    }

    Write-Host '[1/3] SSH tunel k PostgreSQL (localhost:5432)'
    if (Test-LocalPort 5432) {
        Write-Host 'Port 5432 je jiz dostupny; nove okno tunelu neotviram.'
    }
    else {
        $tunnelProcess = Start-Component 'mikoucto - SSH tunel' 'db\google\00-tunel.ps1'
        Wait-Component 'SSH tunel' { Test-LocalPort 5432 } $tunnelProcess
    }

    Write-Host '[2/3] Backend (http://localhost:7005)'
    $backendProcess = $null
    if (-not (Test-LocalPort 7005)) {
        $backendProcess = Start-Component 'mikoucto - backend' 'tools\dev\03-backend.ps1'
    }
    Wait-Component 'Backend' { Test-Http 'http://127.0.0.1:7005/api/v1/health' } $backendProcess
    if (-not (Test-Http 'http://127.0.0.1:7005/api/v1/health/database')) {
        throw 'Backend bezi, ale kontrola databaze selhala. Overte SSH tunel a DB konfiguraci.'
    }

    Write-Host '[3/3] Web (http://localhost:8005)'
    $webProcess = $null
    if (-not (Test-LocalPort 8005)) {
        $webProcess = Start-Component 'mikoucto - web' 'tools\dev\17-web.ps1'
    }
    Wait-Component 'Web' { Test-Http 'http://localhost:8005' } $webProcess

    Write-Host 'Aplikace je dostupna na http://localhost:8005. Prihlaste se uctem Microsoft.' -ForegroundColor Green
    Write-Host 'Okna ponechte otevrena. Ukonceni: Ctrl+C ve webu, backendu a nakonec v tunelu.'
    if (-not $BezProhlizece) { Start-Process 'http://localhost:8005' }
}
catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host 'Jiz otevrena okna zustavaji dostupna pro diagnostiku. Po oprave lze skript spustit znovu.'
    exit 1
}
