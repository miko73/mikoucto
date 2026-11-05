$candidates = @(
    "$env:LOCALAPPDATA\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
    "${env:ProgramFiles(x86)}\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
    "$env:ProgramFiles\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd",
    "$env:USERPROFILE\tools\google-cloud-sdk\bin\gcloud.cmd"
)
if ($env:GCLOUD_PATH) { $candidates = @($env:GCLOUD_PATH) + $candidates }
$gcloud = $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
if (-not $gcloud) { Write-Error "gcloud.cmd nenalezen"; exit 1 }
& $gcloud @args
exit $LASTEXITCODE
