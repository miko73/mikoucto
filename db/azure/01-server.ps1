<#
.SYNOPSIS
    Vytvoří skupinu prostředků, PostgreSQL Flexible Server a databázi podle config.ps1.
    Skript je idempotentní: existující prostředky přeskočí.
#>
[CmdletBinding()]
param()

. "$PSScriptRoot\common.ps1"
$cfg = Import-DbConfig
Connect-DbSubscription $cfg
$tagArgs = Get-TagArgs $cfg.Tags

Write-Step 'Registrace poskytovatele Microsoft.DBforPostgreSQL'
$state = (Invoke-Az -Arguments @('provider', 'show', '--namespace', 'Microsoft.DBforPostgreSQL') -Json).registrationState
if ($state -ne 'Registered') {
    Invoke-Az -Arguments @('provider', 'register', '--namespace', 'Microsoft.DBforPostgreSQL', '--wait')
}
Write-Ok 'Registrováno.'

Write-Step "Skupina prostředků $($cfg.ResourceGroup)"
$exists = (& az group exists --name $cfg.ResourceGroup --only-show-errors) -eq 'true'
if ($exists) {
    Write-Ok 'Existuje.'
} else {
    Invoke-Az -Arguments (@('group', 'create', '--name', $cfg.ResourceGroup, '--location', $cfg.Location, '--tags') + $tagArgs)
    Write-Ok 'Vytvořena.'
}

Write-Step "Server $($cfg.ServerName)"
$server = Invoke-Az -Arguments @('postgres', 'flexible-server', 'show', '-g', $cfg.ResourceGroup, '-n', $cfg.ServerName) -Json -AllowFailure
if ($server) {
    Write-Ok "Existuje, stav: $($server.state), verze $($server.version), SKU $($server.sku.name)."
} else {
    $secrets = Get-DbSecrets $cfg
    if ($secrets.ContainsKey('admin_password')) {
        Write-Info 'Používám existující heslo administrátora ze souboru tajemství.'
    }
    $adminPassword = Get-OrCreateSecret $cfg 'admin_password'
    $secrets = Get-DbSecrets $cfg
    $secrets['admin_user'] = $cfg.AdminUser
    $secrets['host'] = Get-ServerFqdn $cfg
    Save-DbSecrets $cfg $secrets

    Write-Info 'Vytvářím server, trvá obvykle 5-15 minut...'
    Invoke-Az -Arguments (@(
        'postgres', 'flexible-server', 'create',
        '--resource-group', $cfg.ResourceGroup,
        '--name', $cfg.ServerName,
        '--location', $cfg.Location,
        '--version', $cfg.PgVersion,
        '--tier', $cfg.Tier,
        '--sku-name', $cfg.SkuName,
        '--storage-size', [string]$cfg.StorageSizeGb,
        '--storage-auto-grow', 'Disabled',
        '--backup-retention', [string]$cfg.BackupRetentionDays,
        '--geo-redundant-backup', 'Disabled',
        '--zonal-resiliency', 'Disabled',
        '--password-auth', 'Enabled',
        '--microsoft-entra-auth', 'Disabled',
        '--admin-user', $cfg.AdminUser,
        '--admin-password', $adminPassword,
        '--public-access', 'None',
        '--yes',
        '--tags') + $tagArgs)
    Write-Ok 'Server vytvořen.'
}

Write-Step 'Parametry serveru'
$params = [ordered]@{
    'azure.extensions'         = 'PGCRYPTO'   # povolená rozšíření
    'timezone'                 = 'UTC'
    'require_secure_transport' = 'on'
    'log_min_duration_statement' = '2000'     # logovat dotazy delší než 2 s
}
foreach ($name in $params.Keys) {
    $current = Invoke-Az -Arguments @('postgres', 'flexible-server', 'parameter', 'show',
        '-g', $cfg.ResourceGroup, '-s', $cfg.ServerName, '-n', $name) -Json
    if ("$($current.value)".ToLower() -eq $params[$name].ToLower()) {
        Write-Ok "$name = $($current.value)"
        continue
    }
    Invoke-Az -Arguments @('postgres', 'flexible-server', 'parameter', 'set',
        '-g', $cfg.ResourceGroup, '-s', $cfg.ServerName, '-n', $name, '--value', $params[$name])
    Write-Ok "$name = $($params[$name]) (nastaveno)"
}

Write-Step "Databáze $($cfg.DatabaseName)"
$db = Invoke-Az -Arguments @('postgres', 'flexible-server', 'db', 'show',
    '-g', $cfg.ResourceGroup, '-s', $cfg.ServerName, '-n', $cfg.DatabaseName) -Json -AllowFailure
if ($db) {
    Write-Ok 'Existuje.'
} else {
    Invoke-Az -Arguments @('postgres', 'flexible-server', 'db', 'create',
        '-g', $cfg.ResourceGroup, '-s', $cfg.ServerName, '-n', $cfg.DatabaseName,
        '--charset', 'UTF8')
    Write-Ok 'Vytvořena.'
}

Write-Host ''
Write-Ok "Hotovo. Server: $(Get-ServerFqdn $cfg)"
Write-Info 'Další krok: .\02-firewall.ps1'
