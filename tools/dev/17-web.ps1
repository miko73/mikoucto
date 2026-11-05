[CmdletBinding()]
param()
. "$PSScriptRoot\web-common.ps1"
$webUrl = 'http://localhost:8005/'
$existingWeb = $null
try {
    $existingWeb = Invoke-WebRequest -Uri $webUrl -UseBasicParsing -TimeoutSec 3
}
catch { }
if ($null -ne $existingWeb -and $existingWeb.StatusCode -eq 200 -and
    $existingWeb.Content -match '<title>[^<]*mikoucto</title>' -and
    $existingWeb.Content -match '/@vite/client') {
    Write-Host "Web mikoucto uz bezi na $webUrl. Otevrete tuto adresu v prohlizeci." -ForegroundColor Green
    Write-Host 'Novy server nebyl spusten. Restart provedte v puvodnim okne serveru pomoci Ctrl+C.'
    return
}

$client = New-Object System.Net.Sockets.TcpClient
$portInUse = $false
try {
    $connection = $client.ConnectAsync('localhost', 8005)
    if ($connection.Wait(1000)) { $portInUse = $client.Connected }
}
catch { }
finally { $client.Dispose() }
if ($portInUse) {
    throw 'Port 8005 je obsazeny, ale web mikoucto nebyl rozpoznan. Zkontrolujte proces prikazem: Get-NetTCPConnection -LocalPort 8005 -State Listen | Select-Object LocalAddress, OwningProcess. Pred spustenim uvolnete port v puvodnim okne dane sluzby.'
}

Write-Host 'Web: http://localhost:8005 (ukonceni Ctrl+C). Backend musi bezet na portu 7005.'
Invoke-WebNpm -Arguments @('run','dev')
