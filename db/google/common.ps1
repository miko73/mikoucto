# Klient PostgreSQL pres lokalni SSH tunel (Windows PowerShell 5.1+).
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:SqlDir = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\sql'))

function Get-PsqlPath {
    $local = Join-Path $PSScriptRoot '..\..\tools\pgsql\bin\psql.exe'
    if (Test-Path -LiteralPath $local) { return (Resolve-Path -LiteralPath $local).Path }
    $command = Get-Command psql -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    throw 'Chybi psql. Pouzijte existujici db\azure\00-nastroje.ps1 (stahuje pouze klienta, nevytvari Azure).'
}

function Assert-Tunnel([int]$Port) {
    $client = New-Object Net.Sockets.TcpClient
    try {
        $pending = $client.BeginConnect('localhost', $Port, $null, $null)
        if (-not $pending.AsyncWaitHandle.WaitOne(5000)) { throw 'Vyprsel cas spojeni.' }
        $client.EndConnect($pending)
    }
    catch { throw "SSH tunel localhost:$Port neni dostupny. Spustte db\google\00-tunel.ps1 v samostatnem okne. $($_.Exception.Message)" }
    finally { $client.Dispose() }
}

function Invoke-TunnelPsql {
    param(
        [string]$Database = 'playground',
        [string]$User = 'michal',
        [int]$Port = 5432,
        [string[]]$Arguments = @(),
        [string]$InputSql,
        [switch]$NoPasswordPrompt
    )
    $psql = Get-PsqlPath
    Assert-Tunnel $Port
    $saved = @{}
    foreach ($name in @('PGSSLMODE', 'PGCONNECT_TIMEOUT', 'PGCLIENTENCODING', 'PGPASSWORD')) {
        $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }
    $oldEncoding = $OutputEncoding
    try {
        $env:PGSSLMODE = 'disable'
        $env:PGCONNECT_TIMEOUT = '5'
        $env:PGCLIENTENCODING = 'UTF8'
        $OutputEncoding = New-Object Text.UTF8Encoding($false)
        $psqlArgs = @('-X', '-w', '-h', 'localhost', '-p', "$Port", '-U', $User, '-d', $Database,
            '-v', 'ON_ERROR_STOP=1', '-P', 'pager=off')
        if (-not $env:PGPASSWORD -and -not $NoPasswordPrompt) {
            $password = Read-Host "Heslo PostgreSQL pro $User (neuklada se)" -AsSecureString
            $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
            try { $env:PGPASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
            finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
        }
        if ($InputSql) { $InputSql | & $psql @psqlArgs @Arguments }
        else { & $psql @psqlArgs @Arguments }
        if ($LASTEXITCODE -ne 0) { throw "psql selhal (kod $LASTEXITCODE). Instalace nebyla potvrzena jako uspesna." }
    }
    finally {
        $OutputEncoding = $oldEncoding
        foreach ($name in $saved.Keys) { [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process') }
    }
}
