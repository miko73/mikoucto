<#
.SYNOPSIS
    Nevratně odstraní celou skupinu prostředků se serverem a všemi daty.
    Vyžaduje zopakování názvu skupiny a štítek projekt=mikoucto.
.EXAMPLE
    .\99-odstraneni.ps1 -PotvrditSkupinu rg-mikoucto-dev
#>
[CmdletBinding()]
param([Parameter(Mandatory)][string]$PotvrditSkupinu)

. "$PSScriptRoot\common.ps1"
$cfg = Import-DbConfig
Connect-DbSubscription $cfg

if ($PotvrditSkupinu -ne $cfg.ResourceGroup) {
    throw "Potvrzení '$PotvrditSkupinu' neodpovídá skupině z konfigurace '$($cfg.ResourceGroup)'."
}
$rg = Invoke-Az -Arguments @('group', 'show', '--name', $cfg.ResourceGroup) -Json
$projectTag = $null
if ($rg.tags -and $rg.tags.PSObject.Properties['projekt']) { $projectTag = $rg.tags.projekt }
if ($projectTag -ne 'mikoucto') {
    throw "Skupina $($cfg.ResourceGroup) nemá štítek projekt=mikoucto; odstranění odmítnuto."
}

$resources = @(Invoke-Az -Arguments @('resource', 'list', '-g', $cfg.ResourceGroup) -Json)
Write-Step "Odstraňuji skupinu $($cfg.ResourceGroup) s těmito prostředky:"
$resources | ForEach-Object { Write-Info "$($_.type)  $($_.name)" }
Invoke-Az -Arguments @('group', 'delete', '--name', $cfg.ResourceGroup, '--yes')
Write-Ok 'Odstraněno. Soubor tajemství v .secrets ponechán; smažte jej ručně, pokud již není potřeba.'
