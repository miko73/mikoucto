<#
.SYNOPSIS
    Stáhne přenosné klientské nástroje PostgreSQL (psql, pg_dump, pg_restore) do tools\pgsql.
    Nevyžaduje práva správce.
.PARAMETER SeServerem
    Rozbalí i lib a share, aby šlo spustit lokální server PostgreSQL pro automatické testy.
#>
[CmdletBinding()]
param([string]$Version, [switch]$SeServerem)

. "$PSScriptRoot\common.ps1"

if (-not $Version) {
    $cfgPath = Join-Path $PSScriptRoot 'config.ps1'
    if (Test-Path $cfgPath) { . $cfgPath; $Version = $DbConfig.PgClientVersion }
    if (-not $Version) { $Version = '17.10-1' }
}

$target = Join-Path $script:ToolsDir 'pgsql'
$psql = Join-Path $target 'bin\psql.exe'
$needServer = $SeServerem -and -not (Test-Path (Join-Path $target 'share\postgres.bki'))
if ((Test-Path $psql) -and -not $needServer) {
    Write-Ok "psql už je nainstalovaný: $psql"
    & $psql --version
    return
}

$url = "https://get.enterprisedb.com/postgresql/postgresql-$Version-windows-x64-binaries.zip"
# Stažený archiv jde do vlastního prázdného adresáře mimo skripty.
$downloadDir = Join-Path $script:ToolsDir ("_download\" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $downloadDir -Force | Out-Null
$zip = Join-Path $downloadDir 'pgsql.zip'

Write-Step "Stahuji $url (cca 330 MB)"
$ProgressPreference = 'SilentlyContinue'   # výrazně zrychlí Invoke-WebRequest v PowerShell 5.1
Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing

$prefixes = @('pgsql/bin/')
if ($SeServerem) { $prefixes += @('pgsql/lib/', 'pgsql/share/') }
Write-Step "Rozbaluji $($prefixes -join ', ') do $target"
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($zip)
try {
    foreach ($entry in $archive.Entries) {
        if (-not $entry.Name) { continue }
        if (-not ($prefixes | Where-Object { $entry.FullName.StartsWith($_) })) { continue }
        $dest = Join-Path $target ($entry.FullName.Substring('pgsql/'.Length) -replace '/', '\')
        $dir = Split-Path $dest -Parent
        if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
        [IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $dest, $true)
    }
}
finally { $archive.Dispose() }

Remove-Item -Recurse -Force (Join-Path $script:ToolsDir '_download')
& $psql --version
Write-Ok 'Hotovo.'
