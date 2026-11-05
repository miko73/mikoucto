[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort = 5432, [switch]$BezDatabaze)
. "$PSScriptRoot\common.ps1"
Invoke-ProjectPhp -Arguments @('--version')
Invoke-ProjectPhp -Arguments @('-r', "exit(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500 && extension_loaded('pdo_pgsql') && extension_loaded('intl') && extension_loaded('mbstring') && extension_loaded('openssl') ? 0 : 1);")
$composer = Join-Path $script:ProjectRoot 'tools\composer\composer.phar'
if (-not (Test-Path -LiteralPath $composer)) { throw 'Chybi Composer. Spustte tools\dev\00-nastroje.ps1.' }
Invoke-ProjectPhp -Arguments @($composer, '--version')
Write-Host 'PHP, rozsireni a Composer OK.' -ForegroundColor Green
if ($BezDatabaze) {
    Write-Host 'Databaze byla vyslovne preskocena (-BezDatabaze).'
    return
}
. (Join-Path $script:ProjectRoot 'db\google\common.ps1')
& (Get-PsqlPath) --version
if ($LASTEXITCODE -ne 0) { throw 'psql nefunguje.' }
$checks = @{
    owner = @'
DO $$
BEGIN
    IF current_setting('server_version_num')::integer < 160000 THEN
        RAISE EXCEPTION 'Vyzadovan PostgreSQL 16+.';
    END IF;
    IF to_regnamespace('core') IS NULL OR to_regnamespace('ext') IS NULL THEN
        RAISE EXCEPTION 'Chybi schema core nebo ext.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_extension e JOIN pg_namespace n ON n.oid=e.extnamespace WHERE e.extname='pgcrypto' AND n.nspname='ext') THEN
        RAISE EXCEPTION 'Chybi pgcrypto ve schematu ext.';
    END IF;
    IF to_regclass('core.audit_log') IS NULL OR to_regclass('core.audit_seal_state') IS NULL THEN
        RAISE EXCEPTION 'Chybi auditni tabulky.';
    END IF;
    IF to_regclass('core.audit_log_' || to_char(now() AT TIME ZONE 'UTC', 'YYYYMM')) IS NULL THEN
        RAISE EXCEPTION 'Chybi aktualni mesicni partition auditu.';
    END IF;
    IF NOT (SELECT ok FROM core.audit_verify()) THEN
        RAISE EXCEPTION 'Kontrola retezce auditu selhala.';
    END IF;
END $$;
'@
    app = @'
DO $$
BEGIN
    IF (SELECT rolsuper OR rolcreaterole OR rolcreatedb FROM pg_roles WHERE rolname=current_user) THEN
        RAISE EXCEPTION 'Aplikacni role ma nadbytecna prava.';
    END IF;
    IF NOT has_table_privilege(current_user, 'core.audit_log', 'SELECT') THEN
        RAISE EXCEPTION 'Aplikace nemuze cist audit.';
    END IF;
    IF has_table_privilege(current_user, 'core.audit_log', 'INSERT')
       OR has_table_privilege(current_user, 'core.audit_log', 'UPDATE')
       OR has_table_privilege(current_user, 'core.audit_log', 'DELETE') THEN
        RAISE EXCEPTION 'Aplikace muze primo menit audit.';
    END IF;
    IF NOT has_function_privilege(current_user, 'core.set_context(uuid,text,text)', 'EXECUTE') THEN
        RAISE EXCEPTION 'Chybi pravo nastavit auditni kontext.';
    END IF;
END $$;
'@
    read = @'
DO $$
BEGIN
    IF current_setting('default_transaction_read_only') <> 'on' THEN
        RAISE EXCEPTION 'Reportovaci role neni read-only.';
    END IF;
    IF (SELECT rolsuper OR rolcreaterole OR rolcreatedb FROM pg_roles WHERE rolname=current_user) THEN
        RAISE EXCEPTION 'Reportovaci role ma nadbytecna prava.';
    END IF;
    IF NOT has_table_privilege(current_user, 'core.audit_log', 'SELECT') THEN
        RAISE EXCEPTION 'Reportovaci role nemuze cist audit.';
    END IF;
    IF has_table_privilege(current_user, 'core.audit_log', 'INSERT')
       OR has_table_privilege(current_user, 'core.audit_log', 'UPDATE')
       OR has_table_privilege(current_user, 'core.audit_log', 'DELETE') THEN
        RAISE EXCEPTION 'Reportovaci role muze menit audit.';
    END IF;
END $$;
'@
}
$previousPassword = $env:PGPASSWORD
try {
    foreach ($role in @('owner', 'app', 'read')) {
        $url = [Uri](Import-ProjectDatabase -Role $role -Port $DatabasePort)
        $env:PGPASSWORD = [Uri]::UnescapeDataString(($url.UserInfo -split ':',2)[1])
        Invoke-TunnelPsql -User "mikoucto_$role" -Port $DatabasePort -NoPasswordPrompt -InputSql ("BEGIN READ ONLY;`n" + $checks[$role] + "`nCOMMIT;")
        Write-Host "DB role $role OK." -ForegroundColor Green
    }
}
finally { $env:PGPASSWORD = $previousPassword }
Write-Host 'Prostredi a databazovy zaklad OK. Kontroly nemenily data.' -ForegroundColor Green
