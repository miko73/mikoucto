# Prenosne PHP 8.4 NTS x64 a Composer, bez zmen systemoveho PATH.
[CmdletBinding()]
param()
. "$PSScriptRoot\common.ps1"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ProgressPreference = 'SilentlyContinue'
$downloadDir = Join-Path $script:ProjectRoot 'tools\_download'
$phpDir = Join-Path $script:ProjectRoot 'tools\php'
$composerDir = Join-Path $script:ProjectRoot 'tools\composer'
New-Item -ItemType Directory -Path $downloadDir, $composerDir -Force | Out-Null
if (-not (Test-Path (Join-Path $phpDir 'php.exe'))) {
    $releases = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json'
    $package = $releases.'8.4'.'nts-vs17-x64'.zip
    if ($package.path -notmatch '^php-[a-zA-Z0-9.\-]+\.zip$') { throw 'Neplatny nazev balicku PHP.' }
    $zip = Join-Path $downloadDir $package.path
    Write-Host "Stahuji PHP $($releases.'8.4'.version) z oficialniho serveru."
    Invoke-WebRequest -UseBasicParsing -Uri "https://windows.php.net/downloads/releases/$($package.path)" -OutFile $zip
    if ((Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash -ne $package.sha256) { throw 'Nesouhlasi SHA256 PHP.' }
    Expand-Archive -LiteralPath $zip -DestinationPath $phpDir -Force
}
$phpIni = Join-Path $phpDir 'php.ini'
if (-not (Test-Path -LiteralPath $phpIni)) {
    $iniContent = @"
extension_dir=ext
extension=curl
extension=openssl
extension=mbstring
extension=intl
extension=pdo_pgsql
extension=zip
date.timezone=UTC
memory_limit=512M
display_errors=Off
log_errors=On
"@
    [IO.File]::WriteAllText($phpIni, $iniContent, (New-Object Text.UTF8Encoding($false)))
}
$composer = Join-Path $composerDir 'composer.phar'
if (-not (Test-Path -LiteralPath $composer)) {
    $installer = Join-Path $downloadDir 'composer-setup.php'
    $expected = (Invoke-RestMethod 'https://composer.github.io/installer.sig').ToString().Trim()
    Invoke-WebRequest -UseBasicParsing 'https://getcomposer.org/installer' -OutFile $installer
    if ((Get-FileHash -LiteralPath $installer -Algorithm SHA384).Hash -ne $expected) { throw 'Nesouhlasi SHA384 instalatoru Composer.' }
    Invoke-ProjectPhp -Arguments @($installer, "--install-dir=$composerDir", '--filename=composer.phar', '--2')
}
Invoke-ProjectPhp -Arguments @('--version')
Invoke-ProjectPhp -Arguments @($composer, '--version')
Invoke-ProjectPhp -Arguments @('-r', "exit(extension_loaded('pdo_pgsql') ? 0 : 1);")
Write-Host 'Nastroje OK.' -ForegroundColor Green
