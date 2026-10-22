# CI-only: the production diagnostic script is exercised without test switches.
[CmdletBinding()]
param([Parameter(Mandatory = $true)][string]$InstallerPath)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if ($PSVersionTable.PSVersion.Major -ne 5 -or $env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_OS -cne 'Windows') { throw 'ISOLATED_WINDOWS_CI_REQUIRED' }
$profilePath = Join-Path $env:APPDATA 'fasakhansta-dashboard-preview'
$registryPath = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaDashboardPreview'
if ((Test-Path -LiteralPath $profilePath) -or (Test-Path -LiteralPath $registryPath) -or @(Get-Process -Name 'GO ERP' -ErrorAction SilentlyContinue).Count) { throw 'CLEAN_CI_ACCOUNT_REQUIRED' }
$fixture = Join-Path $env:RUNNER_TEMP ('go-erp-inspector-proof-' + [Guid]::NewGuid().ToString('N'))
$installed = Join-Path $fixture 'installed'
$application = Join-Path $fixture 'fixture-app'
$null = New-Item -ItemType Directory -Path $fixture, (Join-Path $application 'desktop'), (Join-Path $application 'empty-conf')
$utf8 = New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $application 'php.ini'), "display_errors=0`nlog_errors=0`n", $utf8)
[IO.File]::WriteAllText((Join-Path $application 'desktop/database.php'), @'
<?php
fwrite(STDERR, "CI_RAW_SECRET_MUST_NOT_APPEAR\n");
fwrite(STDOUT, "Fatal error: Allowed memory size of 268435456 bytes exhausted in CI_STDOUT_SECRET_MUST_NOT_APPEAR\n");
fwrite(STDERR, 'DESKTOP_IMPORT_DIAGNOSTIC '.json_encode([
    'phase'=>'import', 'type'=>'RuntimeException', 'file'=>'fixture.php', 'line'=>7, 'status'=>null
])."\n");
exit(17);
'@, $utf8)
$stdoutPath = Join-Path $fixture 'diagnostic-output.txt'
$stderrPath = Join-Path $fixture 'diagnostic-error.txt'
$diagnostic = $null; $appProcess = $null; $socket = $null
function Get-FixedDiagnosticFailure {
    if (-not (Test-Path -LiteralPath $stdoutPath)) { return 'NO_DIAGNOSTIC_OUTPUT' }
    return (@(Get-Content -LiteralPath $stdoutPath | Where-Object { $_ -cmatch '^GO_ERP_[A-Z0-9_]+$' -or $_.StartsWith('GO_ERP_DIAGNOSTIC_LOCATION ') -or $_.StartsWith('GO_ERP_INSPECTOR_CLEANUP ') } | Select-Object -Last 3) -join ',')
}
try {
    $install = Start-Process -FilePath $InstallerPath -ArgumentList @('/S', ('/D=' + $installed)) -PassThru
    if (-not $install.WaitForExit(300000) -or $install.ExitCode -ne 0) { throw 'CI_INSTALL_FAILED' }
    # Only this disposable fixture acknowledges the exact nonfatal startup
    # network error that the real user is instructed to dismiss manually.
    $dialogReceiptPath = Join-Path $fixture 'startup-dialog.json'
    $preloadPath = Join-Path $fixture 'acknowledge-startup.cjs'
    $preloadConfig = @{ executable = (Join-Path $installed 'GO ERP.exe'); receipt = $dialogReceiptPath } | ConvertTo-Json -Compress
    $preload = @'
'use strict';
const config=PRELOAD_CONFIG;
process.once('loaded',()=>{
 const path=require('node:path'),fs=require('node:fs');
 if(process.type!=='browser'||process.versions.electron!=='44.6.0'||path.resolve(process.execPath).toLowerCase()!==path.resolve(config.executable).toLowerCase())return;
 const {dialog}=require('electron'),original=dialog.showErrorBox;let count=0;
 fs.writeFileSync(config.receipt,JSON.stringify({installed:true,count}),{flag:'wx'});
 dialog.showErrorBox=function(title,message){
  if(title==='GO ERP'&&message==="\u062c\u0647\u0651\u0632 \u0627\u0644\u062c\u0647\u0627\u0632 \u0645\u0646 \u062d\u0633\u0627\u0628 \u0627\u0644\u0633\u064a\u0631\u0641\u0631 \u0642\u0628\u0644 \u0627\u0644\u0639\u0645\u0644 \u0628\u062f\u0648\u0646 \u0625\u0646\u062a\u0631\u0646\u062a."){
   if(++count>8)throw Error('CI_STARTUP_DIALOG_REPEAT_LIMIT');
   fs.writeFileSync(config.receipt,JSON.stringify({installed:true,count}));return;
  }
  return Reflect.apply(original,this,arguments);
 };
});
'@
    [IO.File]::WriteAllText($preloadPath, $preload.Replace('PRELOAD_CONFIG', $preloadConfig), $utf8)
    $script = Join-Path $PSScriptRoot 'diagnose_go_erp_setup.ps1'
    $hadNodeOptions = Test-Path Env:NODE_OPTIONS
    $savedNodeOptions = $env:NODE_OPTIONS
    if ($savedNodeOptions) { throw 'CI_NODE_OPTIONS_ALREADY_SET' }
    try {
        $env:NODE_OPTIONS = '--require="' + $preloadPath + '"'
        $diagnostic = Start-Process -FilePath "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $script + '"')) -PassThru -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath
    } finally {
        if ($hadNodeOptions) { $env:NODE_OPTIONS = $savedNodeOptions } else { Remove-Item Env:NODE_OPTIONS -ErrorAction SilentlyContinue }
    }
    $ready = $null
    $deadline = [DateTime]::UtcNow.AddSeconds(90)
    do {
        if ($diagnostic.HasExited) { throw ('DIAGNOSTIC_EXITED_BEFORE_READY:' + (Get-FixedDiagnosticFailure)) }
        if (Test-Path -LiteralPath $stdoutPath) {
            $line = @(Get-Content -LiteralPath $stdoutPath | Where-Object { $_.StartsWith('GO_ERP_INSPECTOR_READY ') })
            if ($line.Count -gt 1) { throw 'MULTIPLE_READY_MESSAGES' }
            if ($line.Count -eq 1) { $ready = $line[0].Substring(23) | ConvertFrom-Json }
        }
        if (-not $ready) { Start-Sleep -Milliseconds 200 }
    } while (-not $ready -and [DateTime]::UtcNow -lt $deadline)
    if (-not $ready -or $ready.Port -lt 1024 -or $ready.Port -gt 65535 -or $ready.Pid -lt 1) { throw 'READY_MESSAGE_INVALID' }
    $hookDeadline = [DateTime]::UtcNow.AddSeconds(10)
    while (!(Test-Path -LiteralPath $dialogReceiptPath) -and [DateTime]::UtcNow -lt $hookDeadline) { Start-Sleep -Milliseconds 50 }
    if (!(Test-Path -LiteralPath $dialogReceiptPath)) { throw 'CI_STARTUP_DIALOG_HOOK_NOT_INSTALLED' }
    $appProcess = Get-Process -Id $ready.Pid
    if ($appProcess.Path -cne (Join-Path $installed 'GO ERP.exe')) { throw 'WRONG_PACKAGED_PROCESS' }
    $targets = @(Invoke-RestMethod -DisableKeepAlive -Uri ('http://127.0.0.1:' + $ready.Port + '/json/list') -TimeoutSec 5)
    if ($targets.Count -ne 1 -or $targets[0].webSocketDebuggerUrl -notmatch '^ws://127\.0\.0\.1:[0-9]+/[a-fA-F0-9-]+$') { throw 'INSPECTOR_TARGET_INVALID' }
    $socket = New-Object Net.WebSockets.ClientWebSocket
    $cancel = New-Object Threading.CancellationTokenSource
    $cancel.CancelAfter(10000)
    [void]($socket.ConnectAsync([Uri]$targets[0].webSocketDebuggerUrl, $cancel.Token).GetAwaiter().GetResult())
    # Only this CI test injects a synthetic call. The packaged module, bundled
    # PHP and unmodified run()/sanitizer execute against temporary fixture files.
    $fixtureJson = ConvertTo-Json -InputObject $application -Compress
    $expression = @'
(()=>{const path=process.mainModule.require('node:path');
 const Runtime=process.mainModule.require(path.join(process.resourcesPath,'app.asar','src','local-runtime.cjs'));
 const root=FIXTURE_ROOT;
 const context={application:root,bundle:root,phpIni:path.join(root,'php.ini'),php:path.join(process.resourcesPath,'dashboard-runtime','php','php.exe')};
 const fake=Object.create(Runtime.prototype);
 Object.assign(fake,{stopping:false,environment:{},profile:path.join(root,'profile'),stagedContexts:new Map()});
 fake.candidate=async()=>context;
 fake.environmentFor=()=>({...process.env});
 fake.storageFor=()=>path.join(root,'storage');
 fake.settings=async()=>({rootPassword:'CI_SYNTHETIC_ONLY',appPassword:'CI_SYNTHETIC_ONLY'});
 setTimeout(()=>Runtime.prototype.stage.call(fake,{media:[]},'0123456789abcdef',null).catch(()=>{}),250);
 return 'CI_FIXTURE_SCHEDULED';})()
'@
    $expression = $expression.Replace('FIXTURE_ROOT', $fixtureJson)
    $request = @{id=1;method='Runtime.evaluate';params=@{expression=$expression;returnByValue=$true}} | ConvertTo-Json -Depth 8 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($request)
    [void]($socket.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, $cancel.Token).GetAwaiter().GetResult())
    $response = $null
    do {
        $message = New-Object IO.MemoryStream
        do {
            $buffer = New-Object byte[] 65536
            $part = $socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), $cancel.Token).GetAwaiter().GetResult()
            if ($part.MessageType -ne [Net.WebSockets.WebSocketMessageType]::Text) { throw 'FIXTURE_WEBSOCKET_CLOSED' }
            $message.Write($buffer, 0, $part.Count)
            if ($message.Length -gt 1048576) { throw 'FIXTURE_RESPONSE_TOO_LARGE' }
        } while (-not $part.EndOfMessage)
        $value = [Text.Encoding]::UTF8.GetString($message.ToArray()) | ConvertFrom-Json
        if ($value.PSObject.Properties['id'] -and $value.id -eq 1) { $response = $value }
        $message.Dispose()
    } while (-not $response)
    if ($response.PSObject.Properties['error'] -or $response.result.PSObject.Properties['exceptionDetails'] -or $response.result.result.value -cne 'CI_FIXTURE_SCHEDULED') { throw 'FIXTURE_CALL_FAILED' }
    try { [void]($socket.CloseAsync([Net.WebSockets.WebSocketCloseStatus]::NormalClosure, 'fixture scheduled', $cancel.Token).GetAwaiter().GetResult()) } catch {}
    $socket.Dispose(); $socket = $null; $cancel.Dispose()
    # The production diagnostic never closes the real user's app. In this
    # disposable CI account, simulate the requested normal window/menu exit.
    $closeRequested = $false
    $closeDeadline = [DateTime]::UtcNow.AddSeconds(60)
    do {
        if (Test-Path -LiteralPath $stdoutPath) {
            $closeRequested = @(Get-Content -LiteralPath $stdoutPath | Where-Object { $_ -ceq 'GO_ERP_DIAGNOSTIC_CAPTURED_CLOSE_PROGRAM_FROM_MENU' }).Count -eq 1
        }
        if (!$closeRequested -and !$diagnostic.HasExited) { Start-Sleep -Milliseconds 200 }
    } until ($closeRequested -or $diagnostic.HasExited -or [DateTime]::UtcNow -ge $closeDeadline)
    if (!$closeRequested) { throw ('NORMAL_EXIT_REQUEST_MISSING:' + (Get-FixedDiagnosticFailure)) }
    $windowDeadline = [DateTime]::UtcNow.AddSeconds(10)
    do {
        $appProcess.Refresh()
        if (!$appProcess.HasExited -and ($appProcess.MainWindowHandle -eq 0 -or !$appProcess.Responding)) { Start-Sleep -Milliseconds 100 }
    } until ($appProcess.HasExited -or ($appProcess.MainWindowHandle -ne 0 -and $appProcess.Responding) -or [DateTime]::UtcNow -ge $windowDeadline)
    $windowBeforeClose = $appProcess.MainWindowHandle
    $exitedBeforeClose = $appProcess.HasExited
    $windowExists = !$exitedBeforeClose -and $appProcess.MainWindowHandle -ne 0
    $responding = !$exitedBeforeClose -and $appProcess.Responding
    $closeSent = $false; $exitedAfterClose = $false
    if (!$exitedBeforeClose -and $windowExists -and $responding) { $closeSent = $appProcess.CloseMainWindow() }
    if ($closeSent) { $exitedAfterClose = $appProcess.WaitForExit(60000) }
    if (!$closeSent -or !$exitedAfterClose) {
        $appProcess.Refresh()
        $windowChanged = !$appProcess.HasExited -and $appProcess.MainWindowHandle -ne 0 -and $appProcess.MainWindowHandle -ne $windowBeforeClose
        $startupDialogs = $null
        try { if (Test-Path -LiteralPath $dialogReceiptPath) { $startupDialogs = (Get-Content -LiteralPath $dialogReceiptPath -Raw -ErrorAction Stop | ConvertFrom-Json).count } } catch {}
        $waitingForDebugger = $null
        try {
            if (Test-Path -LiteralPath $stderrPath) { $text = Get-Content -LiteralPath $stderrPath -Raw -ErrorAction Stop; if ($null -ne $text) { $waitingForDebugger = [bool]($text -like '*Waiting for the debugger to disconnect*') } }
        } catch {} # Optional observation must not hide the normal-exit evidence.
        Write-Output ('GO_ERP_CI_NORMAL_EXIT ' + (@{ exitedBeforeClose = $exitedBeforeClose; windowExists = $windowExists; responding = $responding; closeSent = $closeSent; exitedAfterClose = $exitedAfterClose; windowChanged = $windowChanged; startupDialogs = $startupDialogs; waitingForDebugger = $waitingForDebugger } | ConvertTo-Json -Compress))
        throw 'PACKAGED_APP_DID_NOT_RESUME_AND_CLOSE'
    }
    if (-not $diagnostic.WaitForExit(15000)) { throw 'DIAGNOSTIC_DID_NOT_EXIT' }
    $output = [IO.File]::ReadAllText($stdoutPath)
    $errorOutput = [IO.File]::ReadAllText($stderrPath)
    if ($output.Contains('CI_RAW_SECRET_MUST_NOT_APPEAR') -or $output.Contains('CI_STDOUT_SECRET_MUST_NOT_APPEAR') -or $output.Contains('CI_SYNTHETIC_ONLY') -or $errorOutput.Contains('CI_RAW_SECRET_MUST_NOT_APPEAR') -or $errorOutput.Contains('CI_STDOUT_SECRET_MUST_NOT_APPEAR') -or $errorOutput.Contains('CI_SYNTHETIC_ONLY')) { throw 'PRIVATE_VALUE_LEAKED' }
    $resultLines = @($output -split '\r?\n' | Where-Object { $_.StartsWith('GO_ERP_SETUP_DIAGNOSTIC ') })
    if ($resultLines.Count -ne 1) { throw 'DIAGNOSTIC_RESULT_MISSING' }
    $result = $resultLines[0].Substring(24) | ConvertFrom-Json
    if ($result.operation -cne 'database-stage' -or $result.exitCode -ne 17 -or $result.overflow -ne $false -or $result.timedOut -ne $false -or $result.import.phase -cne 'import' -or $result.import.type -cne 'RuntimeException' -or $result.import.file -cne 'fixture.php' -or $result.import.line -ne 7 -or $null -ne $result.import.status) { throw 'SANITIZED_CAUSE_DIFFERS' }
    $expectedKeys = @('operation','exitCode','overflow','timedOut','import','fatal') | Sort-Object
    if (@(Compare-Object @($result.PSObject.Properties.Name | Sort-Object) $expectedKeys).Count -ne 0) { throw 'UNEXPECTED_DIAGNOSTIC_FIELD' }
    if (@(Compare-Object @($result.import.PSObject.Properties.Name | Sort-Object) @('file','line','phase','status','type')).Count -ne 0) { throw 'UNEXPECTED_IMPORT_FIELD' }
    $fatalKeys = @('phpFatal','phpParse','outOfMemory','phpStartup','accessDenied','connectionRefused','missingExtension','missingFile') | Sort-Object
    if (@(Compare-Object @($result.fatal.PSObject.Properties.Name | Sort-Object) $fatalKeys).Count -ne 0) { throw 'UNEXPECTED_FATAL_FIELD' }
    foreach ($key in $fatalKeys) { if ($result.fatal.$key -isnot [bool] -or $result.fatal.$key -ne ($key -cin @('phpFatal', 'outOfMemory'))) { throw 'FATAL_CLASSIFICATION_DIFFERS' } }
    Write-Output 'GO_ERP_CI_CAPTURE_VALIDATED'
    if ($diagnostic.ExitCode -ne 0) {
        $httpReachable = $false
        try { $null = Invoke-RestMethod -DisableKeepAlive -Uri ('http://127.0.0.1:' + $ready.Port + '/json/list') -TimeoutSec 2; $httpReachable = $true } catch {}
        Write-Output ('GO_ERP_CI_LISTENER ' + (@{ httpReachable = $httpReachable; appExited = $appProcess.HasExited; appResponding = $appProcess.Responding; windowExists = ($appProcess.MainWindowHandle -ne 0) } | ConvertTo-Json -Compress))
        throw ('DIAGNOSTIC_CAPTURE_FAILED:' + (Get-FixedDiagnosticFailure))
    }
    if (@($output -split '\r?\n' | Where-Object { $_ -ceq 'GO_ERP_INSPECTOR_CLOSED' }).Count -ne 1) { throw 'INSPECTOR_CLEANUP_NOT_CONFIRMED' }
    $stillListening = $true
    try { $null = Invoke-RestMethod -DisableKeepAlive -Uri ('http://127.0.0.1:' + $ready.Port + '/json/list') -TimeoutSec 2 } catch { $stillListening = $false }
    if ($stillListening) { throw 'INSPECTOR_LISTENER_LEFT_OPEN' }
    $dialogReceipt = Get-Content -LiteralPath $dialogReceiptPath -Raw | ConvertFrom-Json
    if ($dialogReceipt.installed -ne $true -or $dialogReceipt.count -lt 1 -or $dialogReceipt.count -gt 8) { throw 'CI_STARTUP_DIALOG_NOT_OBSERVED' }
    $proof = [ordered]@{result='GO_ERP_PACKAGED_INSPECTOR_PROOF';powershell=$PSVersionTable.PSVersion.ToString();electron='44.6.0';version='0.4.4-preview.25';sourceRevision='a0178ead801e8d39185cd0830171804141767995';actualPackagedMain=$true;actualLocalRun=$true;sanitizedCause=$true;rawValuesExcluded=$true;resumed=$true;normalUserExitRequired=$true;ciStartupDialogsAcknowledged=$dialogReceipt.count;inspectorClosed=$true}
    $proof | ConvertTo-Json -Compress
} finally {
    if ($socket) { $socket.Dispose() }
    # CI owns this brand-new account and fixture. Use the normal close path;
    # do not kill processes or delete any application profile/lock.
    if ($appProcess) {
        $appProcess.Refresh()
        if (-not $appProcess.HasExited -and $appProcess.MainWindowHandle -ne 0) { $null = $appProcess.CloseMainWindow(); $null = $appProcess.WaitForExit(10000) }
    }
}
