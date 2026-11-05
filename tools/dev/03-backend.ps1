[CmdletBinding()]
param([ValidateRange(1,65535)][int]$Port = 7005, [ValidateRange(1,65535)][int]$DatabasePort = 5432, [switch]$WithoutOpcache)
. "$PSScriptRoot\common.ps1"
if (-not (Test-Path (Join-Path $script:BackendDir 'vendor\autoload.php'))) { throw 'Spustte tools\dev\02-backend-instalace.ps1.' }
$previousUrl = $env:DATABASE_URL
try {
    if (-not $env:DATABASE_URL) { $env:DATABASE_URL = Import-ProjectDatabase -Port $DatabasePort }
    $runtime=@()
    if (!$WithoutOpcache) {
        $php=Get-ProjectPhp
        $loaded=Invoke-ProjectPhp -Arguments @('-r',"echo extension_loaded('Zend OPcache') ? 'yes' : 'no';")
        if ($loaded -eq 'yes' -or (Test-Path (Join-Path (Split-Path $php -Parent) 'ext\php_opcache.dll'))) {
            if ($loaded -ne 'yes') { $runtime+=@('-d','zend_extension=opcache') }
            # Cache only this development server; CLI tests and other ports
            # keep their own caches. Source edits are checked on every request.
            $runtime+=@('-d','opcache.enable_cli=1','-d','opcache.validate_timestamps=1','-d','opcache.revalidate_freq=0','-d','opcache.file_update_protection=0','-d',"opcache.cache_id=mikoucto-dev-$Port")
            Write-Host 'OPcache pro vyvojovy server zapnuta; zmeny PHP souboru se kontroluji pri kazdem pozadavku.'
        }
    }
    Write-Host "Backend: http://localhost:$Port/api/v1/health (ukonceni Ctrl+C)."
    Invoke-ProjectPhp -Arguments @($runtime+@('-S', "127.0.0.1:$Port", '-t', (Join-Path $script:BackendDir 'public'), (Join-Path $script:BackendDir 'public\router.php')))
}
finally { $env:DATABASE_URL = $previousUrl }
