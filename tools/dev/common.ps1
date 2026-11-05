Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:ProjectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$script:BackendDir = Join-Path $script:ProjectRoot 'backend'

function Get-ProjectPhp {
    $local = Join-Path $script:ProjectRoot 'tools\php\php.exe'
    if (Test-Path -LiteralPath $local) { return $local }
    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    throw 'Chybi PHP. Spustte .\tools\dev\00-nastroje.ps1.'
}

function Invoke-ProjectPhp([string[]]$Arguments) {
    $php = Get-ProjectPhp
    # Windows loader PHP nezvlada absolutni extension_dir s diakritikou.
    # Relativni ext a pracovni adresar PHP tento problem obchazi.
    Push-Location (Split-Path $php -Parent)
    try {
        & $php @Arguments
        if ($LASTEXITCODE -ne 0) { throw "PHP prikaz selhal (kod $LASTEXITCODE)." }
    }
    finally { Pop-Location }
}

function Import-ProjectDatabase {
    param(
        [ValidateSet('app', 'owner', 'read')][string]$Role = 'app',
        [int]$Port = 5432,
        [ValidateSet('playground', 'mikoucto_test')][string]$Database = 'playground'
    )
    $secretFile = Join-Path $script:ProjectRoot 'db\google\.secrets\playground.roles.clixml'
    if (-not (Test-Path -LiteralPath $secretFile)) {
        throw 'Chybi ulozena hesla roli. Dokoncete inicializaci DB podle README.md.'
    }
    $roles = Import-Clixml -LiteralPath $secretFile
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($roles["${Role}_pwd"])
    try { $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    $username = @{ app = 'mikoucto_app'; owner = 'mikoucto_owner'; read = 'mikoucto_read' }[$Role]
    return "postgresql://${username}:$([Uri]::EscapeDataString($password))@localhost:${Port}/${Database}?serverVersion=16&charset=utf8&sslmode=disable&connect_timeout=5"
}
