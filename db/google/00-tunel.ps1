[CmdletBinding()]
param([ValidateRange(1, 65535)][int]$Port = 5432)
$ErrorActionPreference = 'Stop'
$candidates = @(
    $env:GCLOUD_PATH,
    "$env:LOCALAPPDATA\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
    "${env:ProgramFiles(x86)}\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
    "$env:ProgramFiles\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
    "$env:USERPROFILE\tools\google-cloud-sdk\bin\gcloud.cmd"
)
$gcloud = $candidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
if (-not $gcloud) {
    $command = Get-Command gcloud.cmd -ErrorAction SilentlyContinue
    if ($command) { $gcloud = $command.Source }
}
if (-not $gcloud) { throw 'Chybi gcloud.cmd. Nainstalujte Google Cloud SDK nebo nastavte GCLOUD_PATH.' }
Write-Host "SSH tunel localhost:$Port -> pg-free:5432. Toto okno nechte otevrene; ukonceni Ctrl+C."
& $gcloud compute ssh michal_kocandrle@pg-free --project=pg-free-michal --zone=us-central1-a "--ssh-flag=-L ${Port}:localhost:5432" '--ssh-flag=-N'
if ($LASTEXITCODE -ne 0) { throw "SSH tunel skoncil chybou $LASTEXITCODE." }
