<#
.SYNOPSIS
    Zobrazí stav serveru, nebo jej spustí či zastaví (za zastavený server se platí jen úložiště a zálohy).
.EXAMPLE
    .\server.ps1 Stop
#>
[CmdletBinding()]
param([ValidateSet('Stav', 'Start', 'Stop')][string]$Akce = 'Stav')

. "$PSScriptRoot\common.ps1"
$cfg = Import-DbConfig
Connect-DbSubscription $cfg

$base = @('postgres', 'flexible-server')
$target = @('-g', $cfg.ResourceGroup, '-n', $cfg.ServerName)

switch ($Akce) {
    'Start' { Write-Step 'Spouštím server'; Invoke-Az -Arguments ($base + 'start' + $target) }
    'Stop'  { Write-Step 'Zastavuji server'; Invoke-Az -Arguments ($base + 'stop' + $target) }
}

$s = Invoke-Az -Arguments ($base + 'show' + $target) -Json
Write-Ok "Server $($s.name): $($s.state), PostgreSQL $($s.version), $($s.sku.name), úložiště $($s.storage.storageSizeGb) GB"
if ($s.state -eq 'Stopped') {
    Write-Info 'Azure zastavený server po 7 dnech automaticky spustí.'
}
