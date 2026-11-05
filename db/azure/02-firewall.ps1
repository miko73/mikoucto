<#
.SYNOPSIS
    Povolí ve firewallu serveru veřejnou IP tohoto počítače a ověří dostupnost portu 5432.
    Starší pravidla stejného uživatele se stejným prefixem odstraní.
#>
[CmdletBinding()]
param([switch]$PonechatStara)

. "$PSScriptRoot\common.ps1"
$cfg = Import-DbConfig
Connect-DbSubscription $cfg

$ip = Get-PublicIp $cfg
# Název pravidla smí obsahovat jen písmena, číslice, '-' a '_'; diakritiku z uživatelského jména odstraníme.
$user = ($env:USERNAME.Normalize([Text.NormalizationForm]::FormD) -replace '[^A-Za-z0-9]', '').ToLower()
$prefix = "$($cfg.FirewallRulePrefix)-$user-"
$ruleName = $prefix + ($ip -replace '\.', '-')

Write-Step "Firewall: $ruleName ($ip)"
$rules = @(Invoke-Az -Arguments @('postgres', 'flexible-server', 'firewall-rule', 'list',
    '-g', $cfg.ResourceGroup, '--server-name', $cfg.ServerName) -Json)

if ($rules | Where-Object { $_.name -eq $ruleName }) {
    Write-Ok 'Pravidlo existuje.'
} else {
    Invoke-Az -Arguments @('postgres', 'flexible-server', 'firewall-rule', 'create',
        '-g', $cfg.ResourceGroup, '--server-name', $cfg.ServerName, '--name', $ruleName,
        '--start-ip-address', $ip, '--end-ip-address', $ip)
    Write-Ok 'Pravidlo vytvořeno.'
}

if (-not $PonechatStara) {
    foreach ($r in $rules | Where-Object { $_.name.StartsWith($prefix) -and $_.name -ne $ruleName }) {
        Invoke-Az -Arguments @('postgres', 'flexible-server', 'firewall-rule', 'delete',
            '-g', $cfg.ResourceGroup, '--server-name', $cfg.ServerName, '--name', $r.name, '--yes')
        Write-Info "Odstraněno staré pravidlo $($r.name) ($($r.startIpAddress))."
    }
}

Write-Step 'Test spojení na port 5432'
$fqdn = Get-ServerFqdn $cfg
$ok = $false
foreach ($i in 1..6) {   # pravidlo se projeví během několika sekund
    if (Test-NetConnection -ComputerName $fqdn -Port 5432 -InformationLevel Quiet -WarningAction SilentlyContinue) { $ok = $true; break }
    Start-Sleep -Seconds 10
}
if ($ok) {
    Write-Ok "Port 5432 na $fqdn je dostupný."
    Write-Info 'Další krok: .\03-inicializace-db.ps1'
} else {
    Write-Warning "Port 5432 na $fqdn není dostupný. Pravděpodobně jej blokuje firemní síť nebo proxy; požádejte IT o povolení odchozího TCP 5432 na *.postgres.database.azure.com, případně ověřte, že veřejná IP $ip odpovídá síti, ze které se připojujete."
}
