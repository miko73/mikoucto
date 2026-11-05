[CmdletBinding()]
param(
    [string]$Database = 'playground',
    [string]$User = 'postgres',
    [ValidateRange(1, 65535)][int]$Port = 5432,
    [switch]$NoPasswordPrompt
)
. "$PSScriptRoot\common.ps1"
# Nazev je pouzit take jako jmeno souboru a hodnota psql promenne.
if ($Database -notmatch '^[a-zA-Z_][a-zA-Z0-9_]{0,62}$') { throw 'Pouzijte jednoduchy nazev databaze (pismena, cislice, podtrzitko).' }
Assert-Tunnel $Port

$secretDir = Join-Path $PSScriptRoot '.secrets'
$secretPath = Join-Path $secretDir "$Database.roles.clixml"
if (Test-Path -LiteralPath $secretPath) {
    $secrets = Import-Clixml -LiteralPath $secretPath
}
else {
    $secrets = @{}
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        foreach ($name in @('owner_pwd', 'app_pwd', 'read_pwd')) {
            $bytes = New-Object byte[] 32
            $rng.GetBytes($bytes)
            $value = ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
            $secrets[$name] = ConvertTo-SecureString $value -AsPlainText -Force
        }
    }
    finally { $rng.Dispose() }
    New-Item -ItemType Directory -Path $secretDir -Force | Out-Null
    # Windows DPAPI: rozsifruje pouze stejny uzivatel na stejnem pocitaci.
    $secrets | Export-Clixml -LiteralPath $secretPath
}

$lines = New-Object 'System.Collections.Generic.List[string]'
$lines.Add('\set ON_ERROR_STOP on')
$lines.Add("\set db_name '$Database'")
foreach ($name in @('owner_pwd', 'app_pwd', 'read_pwd')) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secrets[$name])
    try { $value = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    if ($value -notmatch '^[a-f0-9]{64}$') { throw "Neplatne ulozene heslo $name." }
    # Hesla jdou pres stdin, nikoli pres argumenty procesu ci soubor s SQL.
    $lines.Add("\set $name '$value'")
}
$lines.Add(@'
DO $$
BEGIN
    IF current_setting('server_version_num')::integer < 160000 THEN
        RAISE EXCEPTION 'Instalace vyzaduje PostgreSQL 16 nebo novejsi.';
    END IF;
    IF NOT (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
        RAISE EXCEPTION 'Pro tuto instalaci pouzijte administratora postgres. Uctu michal nezvysujte prava.';
    END IF;
    IF current_setting('server_encoding') <> 'UTF8' THEN
        RAISE EXCEPTION 'Databaze musi mit kodovani UTF8.';
    END IF;
END $$;
BEGIN;
SET LOCAL lock_timeout = '10s';
SET LOCAL statement_timeout = '120s';
'@)
foreach ($file in @('010_role_a_schemata.sql', '020_rozsireni.sql', '030_audit.sql')) {
    $lines.Add([IO.File]::ReadAllText((Join-Path $script:SqlDir $file), [Text.Encoding]::UTF8))
}
# Puvodni vlastnik playground ma mit nadale pristup k databazi.
$lines.Add('GRANT CONNECT, TEMPORARY ON DATABASE :"db_name" TO michal;')
$lines.Add('COMMIT;')
# Kontrola ma vlastni BEGIN/ROLLBACK a musi byt mimo instalacni transakci.
$lines.Add([IO.File]::ReadAllText((Join-Path $script:SqlDir '090_kontrola.sql'), [Text.Encoding]::UTF8))
Invoke-TunnelPsql -Database $Database -User $User -Port $Port -InputSql ($lines -join "`n") -NoPasswordPrompt:$NoPasswordPrompt
Write-Host "Instalace a kontrola OK: $Database. Hesla roli jsou sifrovana v $secretPath." -ForegroundColor Green
