# Backup operations use the existing Google SSH identity; no administrator
# password, application password or DSN is sent in process arguments.
. "$PSScriptRoot\..\dev\common.ps1"
$script:BackupRoot=Join-Path $script:ProjectRoot 'db\backups'
function Get-BackupGcloud {
    $candidates=@($env:GCLOUD_PATH,"$env:LOCALAPPDATA\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd","${env:ProgramFiles(x86)}\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd","$env:ProgramFiles\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd","$env:USERPROFILE\tools\google-cloud-sdk\bin\gcloud.cmd")
    $path=$candidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
    if (!$path) { $command=Get-Command gcloud.cmd -ErrorAction SilentlyContinue; if ($command) { $path=$command.Source } }
    if (!$path) { throw 'Chybi prihlasene Google Cloud SDK. Nastavte GCLOUD_PATH.' }
    return $path
}
function Invoke-BackupSsh([string]$Script) {
    $payload=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Script))
    $remote="printf '%s' '$payload' | base64 --decode | bash -e -o pipefail"
    & (Get-BackupGcloud) compute ssh michal_kocandrle@pg-free --project=pg-free-michal --zone=us-central1-a "--command=$remote" --quiet
    if ($LASTEXITCODE -ne 0) { throw 'SSH operace zalohy/obnovy selhala. Vyvojova migrace nebyla spustena.' }
}
function Invoke-BackupScp([string]$Directory,[string]$Source,[string]$Destination) {
    Push-Location -LiteralPath $Directory
    try {
        & (Get-BackupGcloud) compute scp $Source $Destination --project=pg-free-michal --zone=us-central1-a --quiet
        if ($LASTEXITCODE -ne 0) { throw 'Prenos zalohy pres SCP selhal.' }
    } finally { Pop-Location }
}
function Get-BackupDirectory([string]$Path) {
    $root=[IO.Path]::GetFullPath($script:BackupRoot).TrimEnd('\')
    $full=[IO.Path]::GetFullPath($Path).TrimEnd('\')
    if (![StringComparer]::OrdinalIgnoreCase.Equals([IO.Path]::GetDirectoryName($full),$root) -or [IO.Path]::GetFileName($full) -notmatch '^playground_\d{8}T\d{6}Z_[a-f0-9]{12}$' -or !(Test-Path -LiteralPath $full -PathType Container)) { throw 'Zaloha musi byt pojmenovany primy podadresar db\backups.' }
    if ((Get-Item -LiteralPath $full).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Odkazy na adresare zaloh nejsou povolene.' }
    return $full
}
function Write-BackupJson([string]$Path,$Value) {
    [IO.File]::WriteAllText($Path,($Value | ConvertTo-Json -Depth 100),(New-Object Text.UTF8Encoding($false)))
}
function Start-BackupPhp([string]$Command,[hashtable]$Variables) {
    $info=New-Object Diagnostics.ProcessStartInfo
    $info.FileName=Get-ProjectPhp
    $encoded=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes((Join-Path $script:BackendDir "bin\$Command")))
    $info.Arguments='-r "require base64_decode($argv[1]);" '+$encoded
    $info.WorkingDirectory=Split-Path $info.FileName -Parent
    $info.UseShellExecute=$false;$info.CreateNoWindow=$true
    $info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
    foreach ($key in $Variables.Keys) { $info.EnvironmentVariables[$key]=[string]$Variables[$key] }
    $process=New-Object Diagnostics.Process;$process.StartInfo=$info
    if (!$process.Start()) { throw 'Nelze spustit overovaci PHP proces.' }
    return $process
}
function Wait-BackupPhp($Process,[string]$ReadyPath) {
    $deadline=[DateTime]::UtcNow.AddSeconds(240)
    while ([DateTime]::UtcNow -lt $deadline) {
        if (Test-Path -LiteralPath $ReadyPath) { return }
        if ($Process.HasExited) { throw ('PHP overeni selhalo: '+$Process.StandardError.ReadToEnd()) }
        Start-Sleep -Milliseconds 100
    }
    throw 'Vyprsel limit pripravy konzistentniho snapshotu.'
}
function Complete-BackupPhp($Process) {
    if (!$Process.WaitForExit(30000)) { throw 'Overovaci PHP proces se neukoncil v limitu.' }
    if ($Process.ExitCode -ne 0) { throw ('PHP overeni selhalo: '+$Process.StandardError.ReadToEnd()) }
    $output=$Process.StandardOutput.ReadToEnd();if ($output) { Write-Host $output }
}
function Get-BackupSource([string]$Directory) {
    $source=[IO.File]::ReadAllText((Join-Path $Directory 'source.json')) | ConvertFrom-Json
    if (!$source.manifest.PSObject.Properties['formatVersion'] -or $source.manifest.formatVersion -ne 1) { throw 'Stary format manifestu. Vytvorte novou zalohu skriptem 09.' }
    if ($source.backupId -cne (Split-Path $Directory -Leaf) -or $source.sourceIdentity.database -cne 'playground' -or $source.sourceIdentity.role -cne 'mikoucto_owner' -or $source.snapshot -notmatch '^[0-9A-Fa-f]+-[0-9A-Fa-f]+-[0-9]+$') { throw 'Nesouhlasi identita zdrojove zalohy.' }
    if ($source.applicationName -cne ('mikoucto.backup.'+$source.backupId.Substring($source.backupId.Length-12))) { throw 'Nesouhlasi oznaceni zdrojove relace.' }
    return $source
}
function Assert-BackupArchive([string]$Directory) {
    $archive=[IO.File]::ReadAllText((Join-Path $Directory 'archive.json')) | ConvertFrom-Json
    if ($archive.file -cne 'database.dump' -or $archive.sha256 -notmatch '^[a-fA-F0-9]{64}$' -or (Get-FileHash -LiteralPath (Join-Path $Directory 'database.dump') -Algorithm SHA256).Hash -ine $archive.sha256) { throw 'Kontrolni soucet archivu nesouhlasi. Obnova se nespusti.' }
    if ((Get-FileHash -LiteralPath (Join-Path $Directory 'source.json') -Algorithm SHA256).Hash -ine $archive.sourceManifestSha256) { throw 'Zmenil se manifest zdrojove zalohy.' }
    return $archive
}
