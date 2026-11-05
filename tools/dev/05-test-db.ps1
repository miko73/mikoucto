[CmdletBinding()]
param([ValidateRange(1,65535)][int]$DatabasePort = 5432, [switch]$ViaSsh)
. "$PSScriptRoot\common.ps1"
$sqlPath = Join-Path $script:ProjectRoot 'db\google\050_test_databaze.sql'
if ($ViaSsh) {
    # Alternativa k heslu postgres: existujici SSH ucet a sudo na vlastni VM.
    $candidates = @(
        $env:GCLOUD_PATH,
        "$env:LOCALAPPDATA\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
        "${env:ProgramFiles(x86)}\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
        "$env:ProgramFiles\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd"
    )
    $gcloud = $candidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
    if (-not $gcloud) { throw 'Chybi gcloud.cmd. Nastavte GCLOUD_PATH nebo pouzijte skript bez -ViaSsh.' }
    # SQL neobsahuje tajemstvi. Base64 zajisti presny prenos uvozovek a novych radku.
    $payload = [Convert]::ToBase64String([IO.File]::ReadAllBytes($sqlPath))
    $remoteCommand = "printf '%s' '$payload' | base64 --decode | sudo -n -u postgres psql -X -v ON_ERROR_STOP=1 -d postgres"
    & $gcloud compute ssh michal_kocandrle@pg-free --project=pg-free-michal --zone=us-central1-a "--command=$remoteCommand" --quiet
    if ($LASTEXITCODE -ne 0) { throw 'Priprava testovaci DB pres SSH selhala. Lze pouzit standardni variantu s heslem postgres.' }
}
else {
    . (Join-Path $script:ProjectRoot 'db\google\common.ps1')
    Invoke-TunnelPsql -Database postgres -User postgres -Port $DatabasePort -InputSql ([IO.File]::ReadAllText($sqlPath, [Text.Encoding]::UTF8))
}
Write-Host 'Priprava testovaci DB OK. Pokracujte: .\tools\dev\06-migrace.ps1 -Target Test -Action Migrate' -ForegroundColor Green
