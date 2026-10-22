# Windows PowerShell 5.1. Captures only fixed classifications on the next normal Prepare click.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
$goExpectedVersion = '0.4.4-preview.25'
$goExpectedRevision = 'a0178ead801e8d39185cd0830171804141767995'
$goExpectedSource = '4a0ab46d8a7d79ad4842879fb83e5ea08276d795fdeb538ac416b220c054dafc'
$goSocket = $null; $goBreakpoint = $null; $goPaused = $false
$goTarget = $null; $goPort = 0; $goCaptured = $false; $goClosed = $true; $goBound = $false
$goPhase = 'preflight'
$goCleanup = New-Object System.Collections.Generic.List[string]
$goSequence = 0; $goEvents = New-Object System.Collections.Queue
$goScripts = New-Object System.Collections.Generic.List[object]
$goDeadline = [DateTime]::UtcNow.AddSeconds(600)
$goCancel = New-Object System.Threading.CancellationTokenSource
$goCancel.CancelAfter(610000)
function Go-Receive {
    $stream = New-Object System.IO.MemoryStream
    try {
        do {
            if ([DateTime]::UtcNow -gt $script:goDeadline) { throw 'GO_ERP_DIAGNOSTIC_TIMEOUT' }
            $buffer = New-Object byte[] 8192
            $segment = New-Object 'System.ArraySegment[byte]' -ArgumentList @(,$buffer)
            $read = $script:goSocket.ReceiveAsync($segment, $script:goCancel.Token).GetAwaiter().GetResult()
            if ($read.MessageType -ne [System.Net.WebSockets.WebSocketMessageType]::Text) { throw 'GO_ERP_INSPECTOR_DISCONNECTED' }
            $stream.Write($buffer, 0, $read.Count)
            if ($stream.Length -gt 1048576) { throw 'GO_ERP_INSPECTOR_MESSAGE_LIMIT' }
        } until ($read.EndOfMessage)
        return ([Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json)
    } finally { $stream.Dispose() }
}
function Go-Observe($message) {
    if ($message.PSObject.Properties['method']) {
        if ($message.method -ceq 'Debugger.paused') { $script:goPaused = $true; $script:goEvents.Enqueue($message) }
        if ($message.method -ceq 'Debugger.resumed') { $script:goPaused = $false }
        if ($message.method -ceq 'Debugger.scriptParsed' -and $message.params.url -match '[/\\]app\.asar[/\\]src[/\\]local-runtime\.cjs$') {
            $script:goScripts.Add($message.params)
        }
    }
}
function Go-Send([string]$method, $parameters) {
    $script:goSequence++
    $id = $script:goSequence
    $json = @{ id = $id; method = $method; params = $parameters } | ConvertTo-Json -Depth 12 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    $segment = New-Object 'System.ArraySegment[byte]' -ArgumentList @(,$bytes)
    [void]($script:goSocket.SendAsync($segment, [Net.WebSockets.WebSocketMessageType]::Text, $true, $script:goCancel.Token).GetAwaiter().GetResult())
    return $id
}
function Go-Command([string]$method, $parameters) {
    $id = Go-Send $method $parameters
    while ($true) {
        $message = Go-Receive
        Go-Observe $message
        if ($message.PSObject.Properties['id'] -and $message.id -eq $id) {
            if ($message.PSObject.Properties['error']) { throw 'GO_ERP_INSPECTOR_COMMAND_FAILED' }
            return $message.result
        }
    }
}
function Go-Evaluate([string]$expression) {
    $reply = Go-Command 'Runtime.evaluate' @{ expression = $expression; returnByValue = $true; silent = $true }
    if ($reply.PSObject.Properties['exceptionDetails']) { throw 'GO_ERP_INSPECTOR_EVALUATION_FAILED' }
    return $reply.result.value
}
function Go-PortOpen {
    $tcp = New-Object Net.Sockets.TcpClient
    try {
        $pending = $tcp.ConnectAsync('127.0.0.1', $script:goPort)
        if (!$pending.Wait(500)) { return $true } # An uncertain connection is not proof of closure.
        [void]($pending.GetAwaiter().GetResult()); return $true
    } catch {
        $reason = $_.Exception
        while ($reason.InnerException) { $reason = $reason.InnerException }
        return ($reason -isnot [Net.Sockets.SocketException] -or $reason.SocketErrorCode -ne [Net.Sockets.SocketError]::ConnectionRefused)
    } finally { $tcp.Dispose() }
}
try {
    if ($PSVersionTable.PSVersion.Major -lt 5) { throw 'GO_ERP_POWERSHELL_5_REQUIRED' }
    if (@(Get-Process -Name 'GO ERP' -ErrorAction SilentlyContinue).Count) { throw 'GO_ERP_CLOSE_PROGRAM_USING_ITS_EXIT_MENU_FIRST' }
    $installed = Get-ItemProperty -LiteralPath 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaDashboardPreview'
    if ($installed.DisplayVersion -cne $goExpectedVersion -or $installed.UninstallString -cnotmatch '^"([A-Za-z]:\\[^"]+)\\Uninstall\.exe"$') { throw 'GO_ERP_EXACT_PREVIEW_25_REQUIRED' }
    $directory = $Matches[1]
    $executable = Join-Path $directory 'GO ERP.exe'
    $receipt = Get-Content -LiteralPath (Join-Path $directory 'resources\desktop-build.json') -Raw | ConvertFrom-Json
    if ($receipt.version -cne $goExpectedVersion -or $receipt.sourceRevision -cne $goExpectedRevision -or !(Test-Path -LiteralPath $executable -PathType Leaf)) { throw 'GO_ERP_BUILD_RECEIPT_MISMATCH' }
    $listener = New-Object Net.Sockets.TcpListener -ArgumentList @([Net.IPAddress]::Loopback, 0)
    $listener.Start(); $goPort = $listener.LocalEndpoint.Port; $listener.Stop()
    $goPhase = 'launch'
    $goTarget = Start-Process -FilePath $executable -ArgumentList @("--inspect=127.0.0.1:$goPort") -PassThru
    $goClosed = $false
    $endpointDeadline = [DateTime]::UtcNow.AddSeconds(20)
    $target = $null
    do {
        if ($goTarget.HasExited) { throw 'GO_ERP_INSPECTOR_LAUNCH_FAILED' }
        try {
            $targets = @(Invoke-RestMethod -Uri "http://127.0.0.1:$goPort/json/list" -TimeoutSec 1 -UseBasicParsing)
            if ($targets.Count -eq 1 -and $targets[0].type -ceq 'node') { $target = $targets[0] }
        } catch {}
        if (!$target) { Start-Sleep -Milliseconds 100 }
    } until ($target -or [DateTime]::UtcNow -ge $endpointDeadline)
    if (!$target) { throw 'GO_ERP_INSPECTOR_UNAVAILABLE' }
    $socketUri = [Uri]$target.webSocketDebuggerUrl
    if ($socketUri.Scheme -cne 'ws' -or $socketUri.Host -cne '127.0.0.1' -or $socketUri.Port -ne $goPort) { throw 'GO_ERP_INSPECTOR_ENDPOINT_MISMATCH' }
    $goPhase = 'connect'
    $goSocket = New-Object Net.WebSockets.ClientWebSocket
    [void]($goSocket.ConnectAsync($socketUri, $goCancel.Token).GetAwaiter().GetResult())
    $goPhase = 'bind-process'
    $binding = Go-Evaluate 'JSON.stringify({pid:process.pid,electron:process.versions.electron})' | ConvertFrom-Json
    if ($binding.pid -ne $goTarget.Id -or $binding.electron -cne '44.6.0') { throw 'GO_ERP_INSPECTOR_PROCESS_MISMATCH' }
    $goBound = $true
    # A bounded fallback closes the listener even if this diagnostic terminal is closed.
    $goPhase = 'watchdog'
    [void](Go-Evaluate '(()=>{const inspector=process.getBuiltinModule("node:inspector");setTimeout(()=>inspector.close(),620000).unref();return true})()')
    $goPhase = 'source'
    [void](Go-Command 'Debugger.enable' @{})
    $goCancel.CancelAfter(20000)
    try { while ($goScripts.Count -eq 0) { Go-Observe (Go-Receive) } }
    catch { throw 'GO_ERP_INSPECTOR_SOURCE_UNAVAILABLE' }
    $goCancel.CancelAfter([Math]::Max(1, [int](($goDeadline - [DateTime]::UtcNow).TotalMilliseconds)))
    $expectedPath = [IO.Path]::GetFullPath((Join-Path $directory 'resources\app.asar\src\local-runtime.cjs'))
    $scripts = @($goScripts | Where-Object {
        try { $uri = [Uri]$_.url; $uri.IsFile -and [IO.Path]::GetFullPath($uri.LocalPath).Equals($expectedPath, [StringComparison]::OrdinalIgnoreCase) } catch { $false }
    })
    if ($scripts.Count -ne 1) { throw 'GO_ERP_INSPECTOR_SOURCE_MISMATCH' }
    $source = (Go-Command 'Debugger.getScriptSource' @{ scriptId = $scripts[0].scriptId }).scriptSource.Replace("`r`n", "`n")
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $digest = [BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($source))).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
    if ($digest -cne $goExpectedSource) { throw 'GO_ERP_INSPECTOR_SOURCE_MISMATCH' }
    $goPhase = 'breakpoint'
    $breakpoint = Go-Command 'Debugger.setBreakpointByUrl' @{ lineNumber = 60; url = $scripts[0].url }
    $goBreakpoint = $breakpoint.breakpointId
    if (@($breakpoint.locations).Count -ne 1 -or $breakpoint.locations[0].lineNumber -ne 60 -or $breakpoint.locations[0].scriptId -cne $scripts[0].scriptId) { throw 'GO_ERP_INSPECTOR_BREAKPOINT_MISMATCH' }
    Write-Output ('GO_ERP_INSPECTOR_READY ' + (@{ Port = $goPort; Pid = $goTarget.Id } | ConvertTo-Json -Compress))
    Write-Output 'Open Program > Prepare for offline work once. This diagnostic waits up to ten minutes.'
    $goPhase = 'wait-prepare'
    $paused = $null
    while (!$paused) {
        if ($goEvents.Count) { $message = $goEvents.Dequeue() } else { $message = Go-Receive; Go-Observe $message; if ($goEvents.Count) { $message = $goEvents.Dequeue() } }
        if ($message.PSObject.Properties['method'] -and $message.method -ceq 'Debugger.paused') {
            if (@($message.params.hitBreakpoints) -notcontains $goBreakpoint) { throw 'GO_ERP_INSPECTOR_UNEXPECTED_PAUSE' }
            $paused = $message.params
        }
    }
    $frame = $paused.callFrames[0]
    if ($frame.location.scriptId -cne $scripts[0].scriptId -or $frame.location.lineNumber -ne 60) { throw 'GO_ERP_INSPECTOR_FRAME_MISMATCH' }
    $expression = @'
(()=>{const script=typeof args[2]==='string'?args[2].split(/[\\/]/).pop():'';
const executable=typeof file==='string'?file.split(/[\\/]/).pop():'';
const operation=executable==='mariadb-install-db.exe'?'database-initialize':script==='database.php'?(args[3]==='stage'?'database-stage':args[3]==='shutdown'?'database-shutdown':'database-ready'):({'import.php':'import','verify.php':'verify','verify-media.php':'verify-media','archive.php':'archive'}[script]||'unknown');
const tail=child.stderrTail();
return JSON.stringify({operation,exitCode:cause.exitCode,overflow:cause.overflow,timedOut:cause.timedOut,...(cause.import?{import:{phase:cause.import.phase,type:cause.import.type,file:cause.import.file,line:cause.import.line,status:cause.import.status}}:{}),fatal:{phpFatal:/PHP Fatal error:/i.test(tail),phpParse:/PHP Parse error:/i.test(tail),outOfMemory:/Allowed memory size of [0-9]+ bytes exhausted|Out of memory/i.test(tail),phpStartup:/PHP (?:Warning: )?PHP Startup:|PHP Startup:/i.test(tail),accessDenied:/Access denied for user/i.test(tail),connectionRefused:/SQLSTATE\[HY000\] \[2002\]|actively refused|Connection refused/i.test(tail),missingExtension:/Unable to load dynamic library|could not find driver|Call to undefined function/i.test(tail),missingFile:/Could not open input file:|Failed opening required/i.test(tail)}})})()
'@
    $goPhase = 'capture'
    $evaluation = Go-Command 'Debugger.evaluateOnCallFrame' @{ callFrameId = $frame.callFrameId; expression = $expression; returnByValue = $true; silent = $true }
    if ($evaluation.PSObject.Properties['exceptionDetails'] -or $evaluation.result.type -cne 'string') { throw 'GO_ERP_INSPECTOR_CAUSE_UNAVAILABLE' }
    $diagnostic = $evaluation.result.value | ConvertFrom-Json
    if ($diagnostic.operation -cnotmatch '^(database-initialize|database-stage|database-shutdown|database-ready|import|verify|verify-media|archive|unknown)$' -or $diagnostic.overflow -isnot [bool] -or $diagnostic.timedOut -isnot [bool]) { throw 'GO_ERP_INSPECTOR_CAUSE_INVALID' }
    [void](Go-Command 'Debugger.removeBreakpoint' @{ breakpointId = $goBreakpoint }); $goBreakpoint = $null
    [void](Go-Command 'Debugger.resume' @{}); $goPaused = $false
    Write-Output ('GO_ERP_SETUP_DIAGNOSTIC ' + ($diagnostic | ConvertTo-Json -Depth 6 -Compress))
    $goCaptured = $true
} catch {
    $code = [string]$_.Exception.Message
    if ($code -cnotmatch '^GO_ERP_[A-Z0-9_]+$') { $code = 'GO_ERP_DIAGNOSTIC_FAILED' }
    Write-Output $code
    $type = $_.Exception.GetType().FullName
    if ($type -cnotmatch '^[A-Za-z0-9_.+]{1,160}$') { $type = 'other' }
    Write-Output ('GO_ERP_DIAGNOSTIC_LOCATION ' + (@{ phase = $goPhase; type = $type; line = $_.InvocationInfo.ScriptLineNumber; hresult = $_.Exception.HResult } | ConvertTo-Json -Compress))
} finally {
    if ($goBound -and $goSocket) {
        # Each cleanup action is independent: a failed optional removal must
        # never skip resume or closing the listener. The fallback timer remains
        # armed if the transport itself has already disconnected.
        foreach ($action in @('resume', 'remove', 'close')) {
            if ($goSocket.State -ne [Net.WebSockets.WebSocketState]::Open) { break }
            try {
                $goDeadline = [DateTime]::UtcNow.AddSeconds(3)
                $goCancel.Dispose()
                $goCancel = New-Object System.Threading.CancellationTokenSource
                $goCancel.CancelAfter(3000)
                if ($action -ceq 'resume') { [void](Go-Command 'Debugger.resume' @{}); $goPaused = $false }
                if ($action -ceq 'remove' -and $goBreakpoint) { [void](Go-Command 'Debugger.removeBreakpoint' @{ breakpointId = $goBreakpoint }) }
                if ($action -ceq 'close') {
                    # Closing the active inspector has no CDP reply. Send once,
                    # then close this WebSocket and verify the listener below.
                    [void](Go-Send 'Runtime.evaluate' @{ expression = 'process.getBuiltinModule("node:inspector").close()'; silent = $true })
                }
                $goCleanup.Add($action + '-ok')
            } catch { $goCleanup.Add($action + '-failed') }
        }
    }
    if ($goSocket) {
        $closeCancel = New-Object System.Threading.CancellationTokenSource
        $closeCancel.CancelAfter(3000)
        try {
            if ($goSocket.State -eq [Net.WebSockets.WebSocketState]::Open) { [void]($goSocket.CloseAsync([Net.WebSockets.WebSocketCloseStatus]::NormalClosure, 'diagnostic complete', $closeCancel.Token).GetAwaiter().GetResult()) }
        } catch {} finally { $closeCancel.Dispose() }
        $goSocket.Abort(); $goSocket.Dispose()
    }
    if ($goPort -gt 0) {
        $closeDeadline = [DateTime]::UtcNow.AddSeconds(5)
        do { $goClosed = !(Go-PortOpen); if (!$goClosed) { Start-Sleep -Milliseconds 100 } } until ($goClosed -or [DateTime]::UtcNow -ge $closeDeadline)
    }
    $goCancel.Dispose()
    if ($goClosed -and $goTarget) { Write-Output 'GO_ERP_INSPECTOR_CLOSED' }
    if (!$goClosed) {
        Write-Output 'GO_ERP_INSPECTOR_CLOSE_PROGRAM_USING_ITS_EXIT_MENU'
        Write-Output ('GO_ERP_INSPECTOR_CLEANUP ' + (@{ bound = $goBound; captured = $goCaptured; actions = @($goCleanup.ToArray()) } | ConvertTo-Json -Compress))
    }
}
if (!$goCaptured -or !$goClosed) { exit 1 }
