# Společné funkce pro skripty v db\azure. Načítá se dot-sourcingem: . "$PSScriptRoot\common.ps1"

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$script:RepoRoot   = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$script:SecretsDir = Join-Path $PSScriptRoot '.secrets'
$script:SqlDir     = Join-Path $PSScriptRoot '..\sql'
$script:ToolsDir   = Join-Path $script:RepoRoot 'tools'

function Write-Step([string]$Text) { Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok([string]$Text)   { Write-Host "    $Text" -ForegroundColor Green }
function Write-Info([string]$Text) { Write-Host "    $Text" }

function Import-DbConfig {
    $path = Join-Path $PSScriptRoot 'config.ps1'
    if (-not (Test-Path $path)) {
        throw "Chybí $path. Zkopírujte config.example.ps1 na config.ps1 a vyplňte hodnoty."
    }
    . $path
    if (-not $DbConfig.Subscription) { throw 'V config.ps1 vyplňte Subscription.' }
    if ($DbConfig.ServerName -notmatch '^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$') {
        throw "Neplatný ServerName '$($DbConfig.ServerName)'."
    }
    return $DbConfig
}

# Spustí az, při chybě vyhodí výjimku. S -Json vrátí naparsovaný výstup.
function Invoke-Az {
    param([Parameter(Mandatory)][string[]]$Arguments, [switch]$Json, [switch]$AllowFailure)
    $azArgs = @($Arguments) + @('--only-show-errors', '--output', $(if ($Json) { 'json' } else { 'none' }))
    $out = & az @azArgs
    if ($LASTEXITCODE -ne 0) {
        if ($AllowFailure) { return $null }
        throw "Příkaz 'az $($Arguments -join ' ')' selhal (kód $LASTEXITCODE)."
    }
    if ($Json -and $out) { return ($out | Out-String | ConvertFrom-Json) }
}

function Connect-DbSubscription([hashtable]$Config) {
    if (-not (Get-Command az -ErrorAction SilentlyContinue)) {
        throw 'Azure CLI (az) není nainstalované. Viz db\README.md.'
    }
    $account = Invoke-Az -Arguments @('account', 'show') -Json -AllowFailure
    if (-not $account) {
        Write-Step 'Přihlášení do Azure'
        & az login --only-show-errors --output none
        if ($LASTEXITCODE -ne 0) { throw 'Přihlášení do Azure selhalo.' }
    }
    Invoke-Az -Arguments @('account', 'set', '--subscription', $Config.Subscription)
    $account = Invoke-Az -Arguments @('account', 'show') -Json
    Write-Ok "Předplatné: $($account.name) ($($account.id)), uživatel: $($account.user.name)"
}

function Get-TagArgs([hashtable]$Tags) {
    return @($Tags.GetEnumerator() | Sort-Object Name | ForEach-Object { "$($_.Name)=$($_.Value)" })
}

# Heslo splňující pravidla Azure (velká, malá písmena, číslice, speciální znak).
# Speciální znaky jen '-' a '_', aby heslo nebylo nutné escapovat v URL ani v příkazové řádce.
function New-DbPassword([int]$Length = 32) {
    $sets = @('ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '-_')
    $all  = -join $sets
    $rng  = [Security.Cryptography.RandomNumberGenerator]::Create()
    $pick = {
        param([string]$chars)
        $b = New-Object byte[] 4
        $rng.GetBytes($b)
        $chars[[BitConverter]::ToUInt32($b, 0) % $chars.Length]
    }
    $chars = New-Object System.Collections.Generic.List[char]
    foreach ($s in $sets) { $chars.Add((& $pick $s)) }
    while ($chars.Count -lt $Length) { $chars.Add((& $pick $all)) }
    # Zamíchání, aby povinné znaky nebyly na začátku.
    $result = $chars | Sort-Object { $b = New-Object byte[] 4; $rng.GetBytes($b); [BitConverter]::ToUInt32($b, 0) }
    $first = [string](& $pick ($sets[0] + $sets[1]))   # heslo nezačíná pomlčkou
    return $first + (-join $result)
}

function Get-SecretsPath([hashtable]$Config) { Join-Path $script:SecretsDir "$($Config.ServerName).json" }

function Get-DbSecrets([hashtable]$Config) {
    $path = Get-SecretsPath $Config
    if (Test-Path $path) {
        $obj = Get-Content $path -Raw -Encoding UTF8 | ConvertFrom-Json
        $h = @{}
        foreach ($p in $obj.PSObject.Properties) { $h[$p.Name] = $p.Value }
        return $h
    }
    return @{}
}

# Uloží tajemství a omezí přístup k souboru jen na aktuálního uživatele.
function Save-DbSecrets([hashtable]$Config, [hashtable]$Secrets) {
    if (-not (Test-Path $script:SecretsDir)) { New-Item -ItemType Directory -Path $script:SecretsDir | Out-Null }
    $path = Get-SecretsPath $Config
    ($Secrets | ConvertTo-Json) | Out-File -FilePath $path -Encoding utf8
    & icacls $path /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
}

function Get-OrCreateSecret([hashtable]$Config, [string]$Name) {
    $s = Get-DbSecrets $Config
    if (-not $s.ContainsKey($Name) -or -not $s[$Name]) {
        $s[$Name] = New-DbPassword
        Save-DbSecrets $Config $s
        Write-Info "Vygenerováno nové heslo '$Name' (uloženo v $(Get-SecretsPath $Config))."
    }
    return $s[$Name]
}

function Get-PublicIp([hashtable]$Config) {
    if ($Config.PublicIpOverride) { return $Config.PublicIpOverride }
    $ip = (Invoke-RestMethod -Uri 'https://api.ipify.org' -TimeoutSec 15).ToString().Trim()
    if ($ip -notmatch '^\d{1,3}(\.\d{1,3}){3}$') { throw "Nepodařilo se zjistit veřejnou IPv4 adresu (odpověď: $ip)." }
    return $ip
}

function Get-ServerFqdn([hashtable]$Config) { "$($Config.ServerName).postgres.database.azure.com" }

function Get-PsqlPath {
    $local = Join-Path $script:ToolsDir 'pgsql\bin\psql.exe'
    if (Test-Path $local) { return $local }
    $cmd = Get-Command psql -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw 'psql nebyl nalezen. Spusťte .\00-nastroje.ps1.'
}

# Spustí psql jako administrátor serveru. Proměnné se předají jako -v name=value.
function Invoke-Psql {
    param(
        [Parameter(Mandatory)][hashtable]$Config,
        [string]$Database = 'postgres',
        [string]$File,
        [string]$Command,
        [hashtable]$Variables = @{},
        [switch]$SingleTransaction
    )
    $psql = Get-PsqlPath
    $secrets = Get-DbSecrets $Config
    if (-not $secrets.ContainsKey('admin_password')) {
        throw "V $(Get-SecretsPath $Config) chybí admin_password. Server vytvářejte skriptem 01-server.ps1."
    }
    $env:PGPASSWORD        = $secrets['admin_password']
    $env:PGSSLMODE         = 'require'
    $env:PGCLIENTENCODING  = 'UTF8'
    $env:PGCONNECT_TIMEOUT = '15'
    try {
        $psqlArgs = @('-h', (Get-ServerFqdn $Config), '-p', '5432', '-U', $Config.AdminUser, '-d', $Database,
                      '-v', 'ON_ERROR_STOP=1', '-X', '-q')
        foreach ($k in $Variables.Keys) { $psqlArgs += @('-v', "$k=$($Variables[$k])") }
        if ($SingleTransaction) { $psqlArgs += '-1' }
        if ($File)    { $psqlArgs += @('-f', $File) }
        if ($Command) { $psqlArgs += @('-c', $Command) }
        & $psql @psqlArgs
        if ($LASTEXITCODE -ne 0) { throw "psql skončil s kódem $LASTEXITCODE$(if ($File) { " při zpracování $File" })." }
    }
    finally {
        Remove-Item Env:PGPASSWORD -ErrorAction SilentlyContinue
    }
}
