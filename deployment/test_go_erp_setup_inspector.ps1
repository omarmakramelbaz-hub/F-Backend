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
$script:ciSequence = 0; $script:ciOwnedPid = 0; $script:ciStartupDismissed = 0; $script:cancel = $null
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;

namespace GoErpInspectorCi {
    public enum DialogStatus {
        NoDialog, Dismissed, UnexpectedDialog, AmbiguousDialog, UnsafeButton,
        ChangedBeforeClick, EnumerationLimit, EnumerationFailed, TextReadFailed,
        SendTimedOut, SendFailed, StillVisible
    }
    public sealed class DialogResult {
        public DialogStatus Status;
        public int VisibleDialogs;
        public int ExactMessageMatches;
        public int StaticControls;
        public int LengthMatches;
        public int TextReadFailures;
        public int TextReadCalls;
        public bool ClickSent;
    }
    public static class StartupDialog {
        // Exact application constant; no text from the window is ever returned.
        private const string ExpectedMessage = "\u062c\u0647\u0651\u0632 \u0627\u0644\u062c\u0647\u0627\u0632 \u0645\u0646 \u062d\u0633\u0627\u0628 \u0627\u0644\u0633\u064a\u0631\u0641\u0631 \u0642\u0628\u0644 \u0627\u0644\u0639\u0645\u0644 \u0628\u062f\u0648\u0646 \u0625\u0646\u062a\u0631\u0646\u062a.";
        private const uint BM_CLICK = 0x00F5;
        private const uint WM_GETTEXT = 0x000D;
        private const uint WM_GETTEXTLENGTH = 0x000E;
        private const uint SendFlags = 0x0001 | 0x0002 | 0x0020;
        private delegate bool EnumProc(IntPtr window, IntPtr parameter);

        [DllImport("user32.dll", SetLastError=true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool EnumWindows(EnumProc callback, IntPtr parameter);
        [DllImport("user32.dll", SetLastError=true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool EnumChildWindows(IntPtr parent, EnumProc callback, IntPtr parameter);
        [DllImport("user32.dll")]
        private static extern uint GetWindowThreadProcessId(IntPtr window, out uint processId);
        [DllImport("user32.dll", CharSet=CharSet.Unicode)]
        private static extern int GetClassName(IntPtr window, StringBuilder name, int capacity);
        [DllImport("user32.dll")]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool IsWindow(IntPtr window);
        [DllImport("user32.dll")]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool IsWindowVisible(IntPtr window);
        [DllImport("user32.dll")]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool IsWindowEnabled(IntPtr window);
        [DllImport("user32.dll")]
        private static extern IntPtr GetDlgItem(IntPtr parent, int item);
        [DllImport("user32.dll")]
        private static extern int GetDlgCtrlID(IntPtr window);
        [DllImport("user32.dll")]
        private static extern IntPtr GetParent(IntPtr window);
        [DllImport("user32.dll", EntryPoint="SendMessageTimeoutW", CharSet=CharSet.Unicode, SetLastError=true)]
        private static extern IntPtr SendMessageTimeoutValue(IntPtr window, uint message,
            UIntPtr wparam, IntPtr lparam, uint flags, uint timeout, out UIntPtr result);
        [DllImport("user32.dll", EntryPoint="SendMessageTimeoutW", CharSet=CharSet.Unicode, SetLastError=true)]
        private static extern IntPtr SendMessageTimeoutText(IntPtr window, uint message,
            UIntPtr wparam, [Out] StringBuilder text, uint flags, uint timeout, out UIntPtr result);

        private sealed class Observation {
            public int VisibleDialogs;
            public int ExactMessageMatches;
            public int ExactDialogs;
            public int StaticControls;
            public int LengthMatches;
            public int TextReadFailures;
            public int TextReadCalls;
            public bool Unexpected;
            public bool AmbiguousMessage;
            public bool Limit;
            public bool Failed;
            public IntPtr Dialog;
        }
        private sealed class ReadBudget {
            public int Remaining = 16;
            public DateTime Deadline = DateTime.UtcNow.AddSeconds(4);
        }
        private static bool BeginRead(ReadBudget budget, Observation observation, out uint timeout) {
            double remaining = (budget.Deadline - DateTime.UtcNow).TotalMilliseconds;
            timeout = 0;
            if (budget.Remaining <= 0 || remaining < 1) { observation.Limit = true; return false; }
            budget.Remaining--; observation.TextReadCalls++;
            timeout = (uint)Math.Min(1000, remaining);
            return true;
        }
        private static bool Owned(IntPtr window, uint processId) {
            uint owner;
            return IsWindow(window) && GetWindowThreadProcessId(window, out owner) != 0 && owner == processId;
        }
        private static bool ClassIs(IntPtr window, string expected) {
            StringBuilder name = new StringBuilder(64);
            return GetClassName(window, name, name.Capacity) == expected.Length
                && String.Equals(name.ToString(), expected, StringComparison.OrdinalIgnoreCase);
        }
        private static int ExactStaticMatches(IntPtr dialog, uint processId, Observation observation, ReadBudget budget) {
            int children = 0;
            int matches = 0;
            EnumChildWindows(dialog, delegate(IntPtr child, IntPtr parameter) {
                if (++children > 128) { observation.Limit = true; return false; }
                // Only the exact bound application PID's STATIC controls are read.
                if (!Owned(child, processId) || !ClassIs(child, "Static") || GetParent(child) != dialog) return true;
                observation.StaticControls++;
                // GetWindowText does not read another process's control text.
                // Fixed system messages marshal Unicode safely across processes;
                // each read has a one-second bound and never returns raw text.
                UIntPtr length;
                uint timeout;
                if (!BeginRead(budget, observation, out timeout)) return false;
                if (SendMessageTimeoutValue(child, WM_GETTEXTLENGTH, UIntPtr.Zero, IntPtr.Zero,
                    SendFlags, timeout, out length) == IntPtr.Zero) {
                    observation.TextReadFailures++; return false;
                }
                if (length.ToUInt64() != (ulong)ExpectedMessage.Length) return true;
                observation.LengthMatches++;
                if (!Owned(child, processId) || !ClassIs(child, "Static") || GetParent(child) != dialog) {
                    observation.TextReadFailures++; return false;
                }
                // One extra character prevents a growing caption being truncated
                // to the expected message between the length/read operations.
                StringBuilder text = new StringBuilder(ExpectedMessage.Length + 2);
                UIntPtr copied;
                if (!BeginRead(budget, observation, out timeout)) return false;
                if (SendMessageTimeoutText(child, WM_GETTEXT, new UIntPtr((uint)text.Capacity), text,
                    SendFlags, timeout, out copied) == IntPtr.Zero) {
                    observation.TextReadFailures++; return false;
                }
                if (!Owned(child, processId) || !ClassIs(child, "Static") || GetParent(child) != dialog) {
                    observation.TextReadFailures++; return false;
                }
                if (copied.ToUInt64() == (ulong)ExpectedMessage.Length
                    && text.Length == ExpectedMessage.Length
                    && String.Equals(text.ToString(), ExpectedMessage, StringComparison.Ordinal)) matches++;
                return true;
            }, IntPtr.Zero);
            return matches;
        }
        private static Observation Observe(uint processId, ReadBudget budget) {
            Observation observation = new Observation();
            int windows = 0;
            bool completed = EnumWindows(delegate(IntPtr window, IntPtr parameter) {
                if (++windows > 2048) { observation.Limit = true; return false; }
                if (!Owned(window, processId) || !IsWindowVisible(window) || !ClassIs(window, "#32770")) return true;
                observation.VisibleDialogs++;
                if (observation.VisibleDialogs > 8) { observation.Limit = true; return false; }
                int matches = ExactStaticMatches(window, processId, observation, budget);
                observation.ExactMessageMatches += matches;
                if (matches == 1) { observation.ExactDialogs++; observation.Dialog = window; }
                else { observation.Unexpected = true; if (matches > 1) observation.AmbiguousMessage = true; }
                return !observation.Limit && observation.TextReadFailures == 0;
            }, IntPtr.Zero);
            observation.Failed = !completed && !observation.Limit;
            return observation;
        }
        private static DialogResult Result(DialogStatus status, Observation observation, bool clicked) {
            DialogResult result = new DialogResult();
            result.Status = status;
            result.VisibleDialogs = observation.VisibleDialogs;
            result.ExactMessageMatches = observation.ExactMessageMatches;
            result.StaticControls = observation.StaticControls;
            result.LengthMatches = observation.LengthMatches;
            result.TextReadFailures = observation.TextReadFailures;
            result.TextReadCalls = observation.TextReadCalls;
            result.ClickSent = clicked;
            return result;
        }
        private static DialogStatus Validate(Observation observation) {
            if (observation.Limit) return DialogStatus.EnumerationLimit;
            if (observation.TextReadFailures > 0) return DialogStatus.TextReadFailed;
            if (observation.Failed) return DialogStatus.EnumerationFailed;
            if (observation.AmbiguousMessage || observation.ExactDialogs > 1) return DialogStatus.AmbiguousDialog;
            if (observation.Unexpected) return DialogStatus.UnexpectedDialog;
            if (observation.VisibleDialogs == 0) return DialogStatus.NoDialog;
            if (observation.VisibleDialogs != 1 || observation.ExactDialogs != 1) return DialogStatus.AmbiguousDialog;
            return DialogStatus.Dismissed; // Validation passed; no click has happened yet.
        }
        public static DialogResult Dismiss(uint processId) {
            ReadBudget budget = new ReadBudget();
            Observation first = Observe(processId, budget);
            DialogStatus valid = Validate(first);
            if (valid != DialogStatus.Dismissed) return Result(valid, first, false);
            // Re-enumerate immediately before acting. A newly appeared unexpected
            // or duplicate dialog must not be ignored in favor of the old handle.
            Observation current = Observe(processId, budget);
            valid = Validate(current);
            if (valid != DialogStatus.Dismissed || current.Dialog != first.Dialog)
                return Result(DialogStatus.ChangedBeforeClick, current, false);
            IntPtr dialog = current.Dialog;
            IntPtr button = GetDlgItem(dialog, 1);
            if (!Owned(dialog, processId) || !IsWindowVisible(dialog) || !ClassIs(dialog, "#32770")
                || button == IntPtr.Zero || !Owned(button, processId) || GetParent(button) != dialog
                || GetDlgCtrlID(button) != 1 || !ClassIs(button, "Button")
                || !IsWindowVisible(button) || !IsWindowEnabled(button))
                return Result(DialogStatus.UnsafeButton, current, false);
            UIntPtr nativeResult;
            IntPtr sent = SendMessageTimeoutValue(button, BM_CLICK, UIntPtr.Zero, IntPtr.Zero,
                SendFlags, 1000, out nativeResult);
            if (sent == IntPtr.Zero) {
                return Result(Marshal.GetLastWin32Error() == 1460 ? DialogStatus.SendTimedOut : DialogStatus.SendFailed,
                    current, false);
            }
            for (int attempt = 0; attempt < 10; attempt++) {
                if (!Owned(dialog, processId) || !IsWindowVisible(dialog) || !ClassIs(dialog, "#32770"))
                    return Result(DialogStatus.Dismissed, current, true);
                Thread.Sleep(50);
            }
            return Result(DialogStatus.StillVisible, current, true);
        }
    }
}
'@
function Invoke-GoErpCiStartupDialogDismissal {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][ValidateRange(1, 2147483647)][int]$ReadyProcessId)
    if ($env:GITHUB_ACTIONS -cne 'true' -or $env:RUNNER_OS -cne 'Windows') { throw 'GO_ERP_ISOLATED_WINDOWS_CI_REQUIRED' }
    $observation = [GoErpInspectorCi.StartupDialog]::Dismiss([uint32]$ReadyProcessId)
    [pscustomobject]@{
        Status = $observation.Status.ToString()
        VisibleDialogs = $observation.VisibleDialogs
        ExactMessageMatches = $observation.ExactMessageMatches
        StaticControls = $observation.StaticControls
        LengthMatches = $observation.LengthMatches
        TextReadFailures = $observation.TextReadFailures
        TextReadCalls = $observation.TextReadCalls
        ClickSent = $observation.ClickSent
    }
}
function Dismiss-CiStartupDialog {
    # An ordinary fixed IDOK acknowledgement models the attended user's action.
    # It never dismisses any other dialog, and never changes the application.
    $deadline = [DateTime]::UtcNow.AddSeconds(10)
    do {
        $dialog = Invoke-GoErpCiStartupDialogDismissal -ReadyProcessId $script:ciOwnedPid
        if ($dialog.Status -ceq 'NoDialog') { return }
        if ($dialog.Status -cne 'Dismissed' -or !$dialog.ClickSent -or $dialog.ExactMessageMatches -ne 1 -or $dialog.VisibleDialogs -ne 1) {
            Write-Output ('GO_ERP_CI_STARTUP_DIALOG_REFUSED ' + ($dialog | ConvertTo-Json -Compress))
            throw ('CI_STARTUP_DIALOG_' + $dialog.Status.ToUpperInvariant())
        }
        $script:ciStartupDismissed++
        if ($script:ciStartupDismissed -gt 8) { throw 'CI_STARTUP_DIALOG_REPEAT_LIMIT' }
        Start-Sleep -Milliseconds 100
    } while ([DateTime]::UtcNow -lt $deadline)
    throw 'CI_STARTUP_DIALOG_TIMEOUT'
}
function Connect-CiInspector {
    $targets = @(Invoke-RestMethod -DisableKeepAlive -Uri ('http://127.0.0.1:' + $ready.Port + '/json/list') -TimeoutSec 5)
    if ($targets.Count -ne 1 -or $targets[0].webSocketDebuggerUrl -notmatch ('^ws://127\.0\.0\.1:' + $ready.Port + '/[a-fA-F0-9-]+$')) { throw 'INSPECTOR_TARGET_INVALID' }
    $script:socket = New-Object Net.WebSockets.ClientWebSocket
    $script:cancel = New-Object Threading.CancellationTokenSource
    $script:cancel.CancelAfter(10000)
    [void]($script:socket.ConnectAsync([Uri]$targets[0].webSocketDebuggerUrl, $script:cancel.Token).GetAwaiter().GetResult())
}
function Invoke-CiEvaluate([string]$expression) {
    $script:ciSequence++
    $id = $script:ciSequence
    $request = @{id=$id;method='Runtime.evaluate';params=@{expression=$expression;returnByValue=$true;silent=$true}} | ConvertTo-Json -Depth 8 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($request)
    [void]($script:socket.SendAsync([ArraySegment[byte]]::new($bytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, $script:cancel.Token).GetAwaiter().GetResult())
    do {
        $message = New-Object IO.MemoryStream
        try {
            do {
                $buffer = New-Object byte[] 65536
                $part = $script:socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), $script:cancel.Token).GetAwaiter().GetResult()
                if ($part.MessageType -ne [Net.WebSockets.WebSocketMessageType]::Text) { throw 'FIXTURE_WEBSOCKET_CLOSED' }
                $message.Write($buffer, 0, $part.Count)
                if ($message.Length -gt 1048576) { throw 'FIXTURE_RESPONSE_TOO_LARGE' }
            } while (-not $part.EndOfMessage)
            $value = [Text.Encoding]::UTF8.GetString($message.ToArray()) | ConvertFrom-Json
        } finally { $message.Dispose() }
        if ($value.PSObject.Properties['id'] -and $value.id -eq $id) {
            if ($value.PSObject.Properties['error'] -or $value.result.PSObject.Properties['exceptionDetails'] -or !$value.result.result.PSObject.Properties['value']) { throw 'CI_EVALUATION_FAILED' }
            return $value.result.result.value
        }
    } while ($true)
}
function Assert-CiBinding {
    $binding = Invoke-CiEvaluate "JSON.stringify({pid:process.pid,electron:process.versions.electron,version:process.mainModule.require('electron').app.getVersion(),executable:process.execPath})" | ConvertFrom-Json
    if ($binding.pid -ne $ready.Pid -or $binding.electron -cne '44.6.0' -or $binding.version -cne '0.4.4-preview.30' -or $binding.executable -cne (Join-Path $installed 'GO ERP.exe')) { throw 'CI_BINDING_DIFFERS' }
}
function Disconnect-CiInspector {
    if ($script:socket) {
        $closeCancel = New-Object Threading.CancellationTokenSource
        $closeCancel.CancelAfter(3000)
        try {
            if ($script:socket.State -eq [Net.WebSockets.WebSocketState]::Open) { [void]($script:socket.CloseAsync([Net.WebSockets.WebSocketCloseStatus]::NormalClosure, 'CI complete', $closeCancel.Token).GetAwaiter().GetResult()) }
        } catch {} finally { $closeCancel.Dispose(); $script:socket.Abort(); $script:socket.Dispose(); $script:socket = $null }
    }
    if ($script:cancel) { $script:cancel.Dispose(); $script:cancel = $null }
}
try {
    $install = Start-Process -FilePath $InstallerPath -ArgumentList @('/S', ('/D=' + $installed)) -PassThru
    if (-not $install.WaitForExit(300000) -or $install.ExitCode -ne 0) { throw 'CI_INSTALL_FAILED' }
    $script = Join-Path $PSScriptRoot 'diagnose_go_erp_setup.ps1'
    # Exercise the exact production probe with a controlled local listener.
    # No short timeout may be mistaken for proof that the port is closed.
    $parseErrors = $null; $parseTokens = $null
    $parsedScript = [Management.Automation.Language.Parser]::ParseFile($script, [ref]$parseTokens, [ref]$parseErrors)
    if ($parseErrors.Count) { throw 'CI_PRODUCTION_SCRIPT_PARSE_FAILED' }
    $portFunction = @($parsedScript.FindAll({param($node)
        $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -ceq 'Go-PortOpen'
    }, $true))
    if ($portFunction.Count -ne 1) { throw 'CI_PORT_PROBE_FUNCTION_DIFFERS' }
    Invoke-Expression $portFunction[0].Extent.Text
    $probeListener = New-Object Net.Sockets.TcpListener -ArgumentList @([Net.IPAddress]::Loopback, 0)
    try {
        $probeListener.Start()
        $script:goPort = $probeListener.LocalEndpoint.Port
        if ((Go-PortOpen) -ne $true) { throw 'CI_LIVE_PORT_REPORTED_CLOSED' }
        $probeListener.Stop()
        $probeWatch = [Diagnostics.Stopwatch]::StartNew()
        $closedPortResult = Go-PortOpen
        $probeWatch.Stop()
        if ($closedPortResult -ne $false) { throw 'CI_CLOSED_PORT_NOT_CONFIRMED' }
        Write-Output ('GO_ERP_CI_PORT_PROBE ' + (@{liveDetected=$true;refusalDetected=$true;closedElapsedMs=$probeWatch.ElapsedMilliseconds} | ConvertTo-Json -Compress))
    } finally { $probeListener.Stop() }
    $diagnostic = Start-Process -FilePath "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $script + '"')) -PassThru -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath
    $ready = $null; $launched = $null
    $deadline = [DateTime]::UtcNow.AddSeconds(90)
    do {
        if ($diagnostic.HasExited) { throw ('DIAGNOSTIC_EXITED_BEFORE_READY:' + (Get-FixedDiagnosticFailure)) }
        if (Test-Path -LiteralPath $stdoutPath) {
            $launchPrefix = 'GO_ERP_INSPECTOR_STARTED '
            $launchLines = @(Get-Content -LiteralPath $stdoutPath | Where-Object { $_.StartsWith($launchPrefix) })
            if ($launchLines.Count -gt 1) { throw 'MULTIPLE_LAUNCH_MESSAGES' }
            if (!$launched -and $launchLines.Count -eq 1) {
                $launched = $launchLines[0].Substring($launchPrefix.Length) | ConvertFrom-Json
                if ($launched.Pid -lt 1 -or $launched.Port -lt 1024 -or $launched.Port -gt 65535) { throw 'LAUNCH_MESSAGE_INVALID' }
                $appProcess = Get-Process -Id $launched.Pid
                if ($appProcess.Path -cne (Join-Path $installed 'GO ERP.exe')) { throw 'WRONG_PACKAGED_PROCESS' }
                $script:ciOwnedPid = $launched.Pid
            }
            if ($script:ciOwnedPid -gt 0) { Dismiss-CiStartupDialog }
            $line = @(Get-Content -LiteralPath $stdoutPath | Where-Object { $_.StartsWith('GO_ERP_INSPECTOR_READY ') })
            if ($line.Count -gt 1) { throw 'MULTIPLE_READY_MESSAGES' }
            if ($line.Count -eq 1) { $ready = $line[0].Substring(23) | ConvertFrom-Json }
        }
        if (-not $ready) { Start-Sleep -Milliseconds 200 }
    } while (-not $ready -and [DateTime]::UtcNow -lt $deadline)
    if (-not $ready -or $ready.Pid -ne $script:ciOwnedPid -or $ready.Port -ne $launched.Port -or $ready.Port -lt 1024 -or $ready.Port -gt 65535 -or $ready.Pid -lt 1) { throw 'READY_MESSAGE_INVALID' }
    $appProcess = Get-Process -Id $ready.Pid
    if ($appProcess.Path -cne (Join-Path $installed 'GO ERP.exe')) { throw 'WRONG_PACKAGED_PROCESS' }
    Dismiss-CiStartupDialog
    Connect-CiInspector
    Assert-CiBinding
    # Production never automates native dialogs: the attended user dismisses
    # prompts. This CI-only helper acknowledges an exact known prompt only if
    # observed; the count below is evidence, not a compulsory coverage claim.
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
    try {
        if ((Invoke-CiEvaluate $expression) -cne 'CI_FIXTURE_SCHEDULED') { throw 'FIXTURE_CALL_FAILED' }
    } finally { Disconnect-CiInspector }
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
    Dismiss-CiStartupDialog
    Connect-CiInspector
    try {
        Assert-CiBinding
        # Invoke only the installed application's exact normal menu role. The
        # production diagnostic never performs this CI-only action.
        $menuExit = @'
(()=>{const {Menu}=process.mainModule.require('electron');
 const menu=Menu.getApplicationMenu();
 const roots=menu?menu.items.filter(i=>i.label==='\u0627\u0644\u0628\u0631\u0646\u0627\u0645\u062c'):[];
 if(roots.length!==1||!roots[0].submenu||!roots[0].enabled||!roots[0].visible)throw Error('CI_PROGRAM_MENU_DIFFERS');
 const items=roots[0].submenu.items.filter(i=>i.label==='\u0625\u063a\u0644\u0627\u0642 \u0627\u0644\u0628\u0631\u0646\u0627\u0645\u062c');
 if(items.length!==1||items[0].role!=='quit'||!items[0].enabled||!items[0].visible||typeof items[0].click!=='function')throw Error('CI_EXIT_MENU_DIFFERS');
 setTimeout(()=>items[0].click({},null,null),250);
 return 'CI_NORMAL_MENU_EXIT_SCHEDULED';})()
'@
        if ((Invoke-CiEvaluate $menuExit) -cne 'CI_NORMAL_MENU_EXIT_SCHEDULED') { throw 'NORMAL_MENU_EXIT_NOT_SCHEDULED' }
    } finally { Disconnect-CiInspector }
    if (!$appProcess.WaitForExit(60000)) { throw 'PACKAGED_APP_NORMAL_MENU_EXIT_TIMEOUT' }
    if (-not $diagnostic.WaitForExit(15000)) { throw 'DIAGNOSTIC_DID_NOT_EXIT' }
    $output = [IO.File]::ReadAllText($stdoutPath)
    $errorOutput = [IO.File]::ReadAllText($stderrPath)
    if ($output.Contains('CI_RAW_SECRET_MUST_NOT_APPEAR') -or $output.Contains('CI_STDOUT_SECRET_MUST_NOT_APPEAR') -or $output.Contains('CI_SYNTHETIC_ONLY') -or $errorOutput.Contains('CI_RAW_SECRET_MUST_NOT_APPEAR') -or $errorOutput.Contains('CI_STDOUT_SECRET_MUST_NOT_APPEAR') -or $errorOutput.Contains('CI_SYNTHETIC_ONLY')) { throw 'PRIVATE_VALUE_LEAKED' }
    $resultLines = @($output -split '\r?\n' | Where-Object { $_.StartsWith('GO_ERP_SETUP_DIAGNOSTIC ') })
    if ($resultLines.Count -ne 1) { throw 'DIAGNOSTIC_RESULT_MISSING' }
    $result = $resultLines[0].Substring(24) | ConvertFrom-Json
    if ($result.operation -cne 'database-stage' -or $result.exitCode -ne 17 -or $result.overflow -ne $false -or $result.timedOut -ne $false -or $null -ne $result.nativeCode -or $null -ne $result.signal -or $result.import.phase -cne 'import' -or $result.import.type -cne 'RuntimeException' -or $result.import.file -cne 'fixture.php' -or $result.import.line -ne 7 -or $null -ne $result.import.status) { throw 'SANITIZED_CAUSE_DIFFERS' }
    $expectedKeys = @('operation','exitCode','overflow','timedOut','nativeCode','signal','import','fatal') | Sort-Object
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
    $proof = [ordered]@{result='GO_ERP_PACKAGED_INSPECTOR_PROOF';powershell=$PSVersionTable.PSVersion.ToString();electron='44.6.0';version='0.4.4-preview.30';sourceRevision='c6605145f740371485944aed1a04045efe71e828';actualPackagedMain=$true;actualLocalRun=$true;sanitizedCause=$true;rawValuesExcluded=$true;resumed=$true;normalUserExitRequired=$true;actualNormalMenuExit=$true;ciStartupDialogObserved=($script:ciStartupDismissed -gt 0);ciStartupDialogsDismissed=$script:ciStartupDismissed;inspectorClosed=$true}
    $proof | ConvertTo-Json -Compress
} finally {
    Disconnect-CiInspector
    # No generic window dismissal, process kill, profile deletion or fallback
    # exit can make a failed proof pass. CI retains its isolated failure state.
}
