[CmdletBinding()]
param(
    [string]$Database = 'playground',
    [string]$User = 'michal',
    [ValidateRange(1, 65535)][int]$Port = 5432,
    [switch]$NoPasswordPrompt
)
. "$PSScriptRoot\common.ps1"
$sql = @'
BEGIN READ ONLY;
SELECT current_database() AS database, current_user AS login,
       inet_server_addr() AS server_address, inet_server_port() AS server_port,
       version() AS server_version;
SELECT rolname, rolsuper, rolcreaterole, rolcreatedb
FROM pg_roles WHERE rolname = current_user;
SELECT datname, pg_get_userbyid(datdba) AS owner,
       pg_encoding_to_char(encoding) AS encoding
FROM pg_database WHERE datname = current_database();
SELECT ssl AS postgres_ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid();
COMMIT;
'@
Invoke-TunnelPsql -Database $Database -User $User -Port $Port -InputSql $sql -NoPasswordPrompt:$NoPasswordPrompt
Write-Host 'Pripojeni OK. Test nemenil data ani schema.' -ForegroundColor Green
