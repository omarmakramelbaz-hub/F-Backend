param([string]$ProbePath = 'deployment/diagnose_go_erp_desktop_import_php.ps1')
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# This harness is for a fresh disposable Windows CI runner only. It refuses an
# existing GO ERP registration and never starts PHP or an actual GO ERP build.
if (-not $env:CI -or [Environment]::OSVersion.Platform -ne 'Win32NT' -or
    $PSVersionTable.PSVersion.Major -ne 5 -or $PSVersionTable.PSVersion.Minor -ne 1) {
    throw 'GO_ERP_PROBE_TEST_REQUIRES_DISPOSABLE_WINDOWS_CI'
}
$goKey = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaDashboardPreview'
if (Test-Path -LiteralPath $goKey) { throw 'GO_ERP_PROBE_TEST_EXISTING_INSTALLATION' }
$goProbe = (Resolve-Path -LiteralPath $ProbePath).Path
$goTokens = $null
$goErrors = $null
[System.Management.Automation.Language.Parser]::ParseFile($goProbe, [ref]$goTokens, [ref]$goErrors) | Out-Null
if ($goErrors.Count -ne 0) { throw 'GO_ERP_PROBE_TEST_PARSE_FAILED' }
$goSource = [IO.File]::ReadAllText($goProbe)
$goPhpMatch = [regex]::Match($goSource, '(?s)\$goCode = @''\r?\n(.*?)\r?\n''@')
if (-not $goPhpMatch.Success) { throw 'GO_ERP_PROBE_TEST_INLINE_PHP_NOT_FOUND' }
$goPhpCode = $goPhpMatch.Groups[1].Value.Replace("`r`n", "`n")
$goSha = [Security.Cryptography.SHA256]::Create()
try {
    $goCodeHash = ([BitConverter]::ToString($goSha.ComputeHash([Text.Encoding]::UTF8.GetBytes($goPhpCode)))).Replace('-', '').ToLowerInvariant()
} finally { $goSha.Dispose() }
$goRoot = Join-Path ([IO.Path]::GetTempPath()) ('GO ERP probe fixture ' + [guid]::NewGuid().ToString('N'))
$goRegistryCreated = $false
$goNames = @('APPDATA', 'PHP_INI_SCAN_DIR', 'GO_ERP_FAKE_CASE', 'GO_ERP_FAKE_CWD', 'GO_ERP_FAKE_INI', 'GO_ERP_FAKE_SCAN', 'GO_ERP_FAKE_CODE_HASH', 'GO_ERP_FAKE_CALLS')
$goPrevious = @{}
foreach ($goName in $goNames) { $goPrevious[$goName] = [Environment]::GetEnvironmentVariable($goName, 'Process') }

try {
    New-Item -ItemType Directory -Path $goRoot | Out-Null
    $goAppData = Join-Path $goRoot 'App Data'
    $goProfile = Join-Path $goAppData 'fasakhansta-dashboard-preview\dashboard'
    $goRevision = '27eae737be190a9900fed67810393a0b68627119'
    $goRuntime = Join-Path $goProfile ('runtimes\' + $goRevision)
    $goApplication = Join-Path $goRuntime 'application'
    $goPhp = Join-Path $goRuntime 'php\php.exe'
    $goIni = Join-Path $goProfile ('php-' + $goRevision + '.ini')
    $goReceipt = Join-Path $goProfile ('runtimes\' + $goRevision + '.enc')
    $goInstall = Join-Path $goRoot 'Installed App'
    $goStart = Join-Path $goRoot 'Starting Directory'
    foreach ($goDirectory in @($goApplication, (Split-Path $goPhp), (Join-Path $goRuntime 'php\conf.d'), (Join-Path $goInstall 'resources'), $goStart)) {
        New-Item -ItemType Directory -Path $goDirectory -Force | Out-Null
    }
    [IO.File]::WriteAllText($goIni, 'serialize_precision=17')
    [IO.File]::WriteAllText($goReceipt, 'synthetic receipt marker, never DPAPI data')
    $goBuildPath = Join-Path $goInstall 'resources\desktop-build.json'
    [IO.File]::WriteAllText($goBuildPath, ('{"version":"0.4.4-preview.31","sourceRevision":"' + $goRevision + '"}'))
    New-Item -Path $goKey -Force | Out-Null
    $goRegistryCreated = $true
    New-ItemProperty -LiteralPath $goKey -Name DisplayName -Value 'GO ERP' -PropertyType String -Force | Out-Null
    New-ItemProperty -LiteralPath $goKey -Name DisplayVersion -Value '0.4.4-preview.31' -PropertyType String -Force | Out-Null
    New-ItemProperty -LiteralPath $goKey -Name UninstallString -Value ('"' + (Join-Path $goInstall 'Uninstall.exe') + '"') -PropertyType String -Force | Out-Null

    $goFakeSource = @'
using System;
using System.IO;
using System.Security.Cryptography;
using System.Text;
public static class GoErpImportPhpFixture {
    static string Env(string key) { return Environment.GetEnvironmentVariable(key); }
    static bool Same(string a, string b) { return String.Equals(Path.GetFullPath(a), Path.GetFullPath(b), StringComparison.OrdinalIgnoreCase); }
    public static int Main(string[] args) {
        File.AppendAllText(Env("GO_ERP_FAKE_CALLS"), "call\n");
        if (args.Length != 7 || args[0] != "-c" || args[2] != "-d" || args[3] != "memory_limit=-1" || args[4] != "-r") return 31;
        if (!Same(Environment.CurrentDirectory, Env("GO_ERP_FAKE_CWD")) || !Same(args[1], Env("GO_ERP_FAKE_INI")) || !Same(args[6], Env("GO_ERP_FAKE_INI"))) return 32;
        if (!Same(Env("PHP_INI_SCAN_DIR"), Env("GO_ERP_FAKE_SCAN"))) return 33;
        using (var sha = SHA256.Create()) {
            string hash = BitConverter.ToString(sha.ComputeHash(Encoding.UTF8.GetBytes(args[5].Replace("\r\n", "\n")))).Replace("-", "").ToLowerInvariant();
            if (hash != Env("GO_ERP_FAKE_CODE_HASH")) return 34;
        }
        if (Env("GO_ERP_FAKE_CASE") == "nonzero") { Console.Error.WriteLine("SQL customer-secret@example.invalid token=private-fixture"); return 7; }
        string json = @"{""result"":""GO_ERP_DESKTOP_IMPORT_PHP_SETTINGS"",""sapi"":""cli"",""php_version"":""8.2.31"",""php_int_size"":8,""serialize_precision"":""17"",""precision"":""14"",""memory_limit"":""-1"",""expected_ini_loaded"":true,""additional_ini_files"":false,""float_encoding_control"":""0.10000000000000001"",""private_field"":""customer-secret@example.invalid token=private-fixture""}";
        if (Env("GO_ERP_FAKE_CASE") == "invalid-type") json = json.Replace("\"serialize_precision\":\"17\"", "\"serialize_precision\":{}");
        Console.WriteLine(json);
        return 0;
    }
}
'@
    Add-Type -TypeDefinition $goFakeSource -Language CSharp -OutputAssembly $goPhp -OutputType ConsoleApplication
    $goCalls = Join-Path $goRoot 'fake-calls.txt'
    [IO.File]::WriteAllText($goCalls, '')
    $goWrapper = Join-Path $goRoot 'run probe.ps1'
    $goWrapperSource = @'
param([string]$Probe)
$beforePath=(Get-Location).Path
$beforeScan=[Environment]::GetEnvironmentVariable('PHP_INI_SCAN_DIR','Process')
$probeCode=0
try {
    & $Probe
    $probeCode=$LASTEXITCODE
} finally {
    $samePath=(Get-Location).Path -eq $beforePath
    $sameScan=[Environment]::GetEnvironmentVariable('PHP_INI_SCAN_DIR','Process') -ceq $beforeScan
    'GO_ERP_PROBE_TEST_STATE '+([ordered]@{cwd_restored=$samePath;scan_restored=$sameScan}|ConvertTo-Json -Compress)
}
if($probeCode -ne 0){exit 1}
'@
    [IO.File]::WriteAllText($goWrapper, $goWrapperSource)
    $env:APPDATA = $goAppData
    $env:GO_ERP_FAKE_CWD = $goApplication
    $env:GO_ERP_FAKE_INI = $goIni
    $env:GO_ERP_FAKE_SCAN = Join-Path $goRuntime 'php\conf.d'
    $env:GO_ERP_FAKE_CODE_HASH = $goCodeHash
    $env:GO_ERP_FAKE_CALLS = $goCalls
    $goPowerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'

    function Invoke-ProbeFixture([string]$Case, [bool]$Success, [bool]$ScanPresent) {
        $env:GO_ERP_FAKE_CASE = $Case
        $scan = if ($ScanPresent) { 'C:\existing fixture\config' } else { $null }
        [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', $scan, 'Process')
        $startInfo = New-Object Diagnostics.ProcessStartInfo
        $startInfo.FileName = $goPowerShell
        $startInfo.Arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $goWrapper + '" -Probe "' + $goProbe + '"'
        $startInfo.WorkingDirectory = $goStart
        $startInfo.UseShellExecute = $false
        $startInfo.RedirectStandardOutput = $true
        $startInfo.RedirectStandardError = $true
        $process = New-Object Diagnostics.Process
        $process.StartInfo = $startInfo
        try {
            if (-not $process.Start()) { throw 'GO_ERP_PROBE_TEST_CHILD_START_FAILED' }
            $null = $process.Handle
            $stdoutTask = $process.StandardOutput.ReadToEndAsync()
            $stderrTask = $process.StandardError.ReadToEndAsync()
            if (-not $process.WaitForExit(30000)) { $process.Kill(); throw 'GO_ERP_PROBE_TEST_CHILD_TIMED_OUT' }
            $stdout = $stdoutTask.GetAwaiter().GetResult()
            $stderr = $stderrTask.GetAwaiter().GetResult()
            $code = $process.ExitCode
        } finally { $process.Dispose() }
        if (($Success -and $code -ne 0) -or (-not $Success -and $code -eq 0)) {
            throw ('GO_ERP_PROBE_TEST_EXIT_MISMATCH_' + $Case)
        }
        if (($stdout + $stderr) -match 'customer-secret|private-fixture|private_field|SQL') { throw 'GO_ERP_PROBE_TEST_PRIVATE_OUTPUT' }
        if ($stderr) { throw 'GO_ERP_PROBE_TEST_UNEXPECTED_STDERR' }
        $lines = @($stdout -split '\r?\n')
        $stateLines = @($lines | Where-Object { $_.StartsWith('GO_ERP_PROBE_TEST_STATE ') })
        if ($stateLines.Count -ne 1) { throw 'GO_ERP_PROBE_TEST_STATE_MISSING' }
        $state = $stateLines[0].Substring('GO_ERP_PROBE_TEST_STATE '.Length) | ConvertFrom-Json
        if ($state.cwd_restored -ne $true -or $state.scan_restored -ne $true) { throw 'GO_ERP_PROBE_TEST_STATE_NOT_RESTORED' }
        $payload = ($lines | Where-Object { -not $_.StartsWith('GO_ERP_PROBE_TEST_STATE ') }) -join "`n"
        $report = $payload | ConvertFrom-Json
        if ($Success) {
            $expected = @('result','sapi','php_version','php_int_size','serialize_precision','precision','memory_limit','expected_ini_loaded','additional_ini_files','float_encoding_control')
            $keys = @($report.PSObject.Properties.Name)
            if ($keys.Count -ne $expected.Count -or @($keys | Where-Object { $_ -notin $expected }).Count -ne 0) { throw 'GO_ERP_PROBE_TEST_OUTPUT_FIELDS' }
            if ($report.result -ne 'GO_ERP_DESKTOP_IMPORT_PHP_SETTINGS' -or $report.serialize_precision -cne '17' -or $report.precision -cne '14' -or $report.memory_limit -cne '-1') { throw 'GO_ERP_PROBE_TEST_SETTINGS' }
        } else {
            $keys = @($report.PSObject.Properties.Name)
            if ($keys.Count -ne 2 -or @($keys | Where-Object { $_ -notin @('result','reason') }).Count -ne 0 -or
                $report.result -ne 'GO_ERP_DESKTOP_IMPORT_PHP_SETTINGS_FAILED' -or $report.reason -notmatch '^GO_ERP_[A-Z0-9_]+$') { throw 'GO_ERP_PROBE_TEST_FAILURE_FIELDS' }
        }
        Write-Output ('PASS PowerShell51 probe ' + $Case + ' scanPresent=' + $ScanPresent)
    }

    Invoke-ProbeFixture 'success' $true $true
    Invoke-ProbeFixture 'success' $true $false
    Invoke-ProbeFixture 'invalid-type' $false $true
    Invoke-ProbeFixture 'nonzero' $false $false
    $goBeforeCalls = @(Get-Content -LiteralPath $goCalls).Count
    Set-ItemProperty -LiteralPath $goKey -Name DisplayVersion -Value '0.4.4-preview.30'
    Invoke-ProbeFixture 'wrong-version' $false $true
    Set-ItemProperty -LiteralPath $goKey -Name DisplayVersion -Value '0.4.4-preview.31'
    [IO.File]::WriteAllText($goBuildPath, '{"version":"0.4.4-preview.31","sourceRevision":"0000000000000000000000000000000000000000"}')
    Invoke-ProbeFixture 'wrong-revision' $false $false
    [IO.File]::WriteAllText($goBuildPath, ('{"version":"0.4.4-preview.31","sourceRevision":"' + $goRevision + '"}'))
    Remove-Item -LiteralPath $goReceipt
    Invoke-ProbeFixture 'missing-receipt' $false $true
    if (@(Get-Content -LiteralPath $goCalls).Count -ne $goBeforeCalls) { throw 'GO_ERP_PROBE_TEST_REFUSAL_STARTED_PHP' }
    Write-Output 'GO_ERP_DESKTOP_IMPORT_PHP_PROBE_TESTS_PASSED cases=7 actual_php=false actual_account=false'
} finally {
    foreach ($goName in $goNames) { [Environment]::SetEnvironmentVariable($goName, $goPrevious[$goName], 'Process') }
    if ($goRegistryCreated) { Remove-Item -LiteralPath $goKey -Recurse -Force }
    if (Test-Path -LiteralPath $goRoot) { Remove-Item -LiteralPath $goRoot -Recurse -Force }
}
