#requires -Version 5.1
param(
    [Parameter(Mandatory = $true)][string]$MariaDbBin,
    [Parameter(Mandatory = $true)][string]$Application,
    [Parameter(Mandatory = $true)][string]$Php
)
$ErrorActionPreference = 'Stop'
$server = Join-Path $MariaDbBin 'mariadbd.exe'
$installer = Join-Path $MariaDbBin 'mariadb-install-db.exe'
foreach ($file in @($server, $installer, $Php)) {
    if (!(Test-Path -LiteralPath $file -PathType Leaf)) { throw "Private print fixture executable is missing: $file" }
}
$parent = [System.IO.Path]::GetTempPath()
if ($env:RUNNER_TEMP) { $parent = $env:RUNNER_TEMP }
$work = Join-Path $parent ('desktop-print-fixture-' + [guid]::NewGuid().ToString('N'))
$data = Join-Path $work 'data'
$process = $null
$fixtureExit = 1
New-Item -ItemType Directory -Path $work | Out-Null
try {
    # This instance belongs only to the print fixture. The shared instance and
    # its permanent effects ledger remain intact for the other acceptance cohorts.
    & $installer "--datadir=$data"
    if ($LASTEXITCODE -ne 0) { throw 'Private print database initialization failed.' }
    $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    try { $listener.Start(); $port = $listener.LocalEndpoint.Port } finally { $listener.Stop() }
    $process = Start-Process $server -ArgumentList @('--no-defaults', "--datadir=`"$data`"", '--bind-address=127.0.0.1', "--port=$port", '--console') -PassThru -RedirectStandardOutput (Join-Path $work 'maria-out.log') -RedirectStandardError (Join-Path $work 'maria-error.log')
    $ready = $false
    for ($attempt = 0; $attempt -lt 120; $attempt++) {
        if ($process.HasExited) { Get-Content (Join-Path $work 'maria-error.log'); throw 'Private print database stopped.' }
        $client = [System.Net.Sockets.TcpClient]::new()
        try { if ($client.ConnectAsync('127.0.0.1', $port).Wait(250)) { $ready = $true; break } } catch {} finally { $client.Dispose() }
        Start-Sleep -Milliseconds 250
    }
    if (!$ready) { throw 'Private print database readiness timed out.' }
    & $Php (Join-Path $PSScriptRoot 'print-ledger.php') $Application $port
    $fixtureExit = $LASTEXITCODE
} finally {
    if ($null -ne $process) {
        if (!$process.HasExited) { Stop-Process -Id $process.Id -Force }
        if (!$process.WaitForExit(20000)) { throw 'Private print database cleanup timed out.' }
        $process.Dispose()
    }
    Remove-Item -LiteralPath $work -Recurse -Force
}
exit $fixtureExit
