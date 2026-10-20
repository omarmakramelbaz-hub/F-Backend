[CmdletBinding()]
param([string] $Project = 'desktop-pos', [string] $PayloadPath)
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath $Project).Path
. (Join-Path $projectRoot 'windows-vcredist.ps1') -PayloadPath $PayloadPath
$policy = Read-DesktopVcPolicy -Path (Join-Path $projectRoot 'windows-vcredist.json')
$checks = 0
function Assert-Vc { param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }; $script:checks++ }
function Assert-VcFailure { param([scriptblock] $Body, [string] $Message)
    $failed = $false
    try { & $Body | Out-Null } catch { $failed = $true }
    Assert-Vc $failed $Message }

if ($PayloadPath) {
    $actual = Test-DesktopVcPayload -Path $PayloadPath -Policy $policy
    Assert-Vc ($actual.signatureValid -eq $true -and $actual.sha256 -ceq $policy.sha256) 'The real Microsoft signed payload was not verified.'
    $wrongSigner = $policy | Select-Object *; $wrongSigner.signerSha256 = '0' * 64
    Assert-VcFailure { Test-DesktopVcPayload -Path $PayloadPath -Policy $wrongSigner } 'A changed Microsoft signer pin was accepted.'
    $wrongVersion = $policy | Select-Object *; $wrongVersion.version = '14.0.0.0'
    Assert-VcFailure { Test-DesktopVcPayload -Path $PayloadPath -Policy $wrongVersion } 'A different signed binary version was accepted.'
    $bad = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString('N') + '.exe')
    try {
        $bytes = [IO.File]::ReadAllBytes($PayloadPath); $bytes[1024] = $bytes[1024] -bxor 1
        [IO.File]::WriteAllBytes($bad, $bytes)
        Assert-VcFailure { Test-DesktopVcPayload -Path $bad -Policy $policy } 'A same-length tampered Microsoft executable was accepted.'
    } finally { if (Test-Path -LiteralPath $bad) { Remove-Item -LiteralPath $bad -Force } }
}

# Run the real entrypoint in a fresh engine: param defaults and module discovery
# happen before same-process mocks can observe either Windows PowerShell failure.
$defaultsRoot = Join-Path ([IO.Path]::GetTempPath()) ('vc defaults ' + [Guid]::NewGuid().ToString('N'))
$enginePath = [Diagnostics.Process]::GetCurrentProcess().MainModule.FileName
$childHasSignedPayload = [bool]$PayloadPath
function Invoke-VcChild {
    param([string] $Arguments, [string] $ModulePath)
    $start = New-Object Diagnostics.ProcessStartInfo
    $start.FileName = $enginePath; $start.Arguments = $Arguments
    $start.UseShellExecute = $false; $start.CreateNoWindow = $true
    $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
    $start.EnvironmentVariables['VC_DEFAULTS_ROOT'] = $defaultsRoot
    $start.EnvironmentVariables['VC_SIGNED_PAYLOAD'] = [string][int]$childHasSignedPayload
    if ($ModulePath) { $start.EnvironmentVariables['PSModulePath'] = $ModulePath }
    $child = New-Object Diagnostics.Process
    $child.StartInfo = $start
    try {
        $null = $child.Start()
        $output = $child.StandardOutput.ReadToEndAsync(); $errors = $child.StandardError.ReadToEndAsync()
        if (-not $child.WaitForExit(60000)) { $child.Kill(); throw 'The prerequisite validation child exceeded its deadline.' }
        return [pscustomobject]@{ Code = $child.ExitCode; Output = $output.Result; Error = $errors.Result }
    } finally { $child.Dispose() }
}
try {
    $null = [IO.Directory]::CreateDirectory($defaultsRoot)
    $alternate = Join-Path $defaultsRoot 'explicit paths'; $null = [IO.Directory]::CreateDirectory($alternate)
    $foreignModules = Join-Path $defaultsRoot 'foreign Modules'; $null = [IO.Directory]::CreateDirectory($foreignModules)
    if ($PSVersionTable.PSEdition -eq 'Core') { $foreignModules = [IO.Path]::Combine($PSHOME, 'Modules') }
    elseif ($env:ProgramFiles) {
        $coreModules = [IO.Path]::Combine($env:ProgramFiles, 'PowerShell', '7', 'Modules')
        if ([IO.Directory]::Exists($coreModules)) { $foreignModules = $coreModules }
    }
    $helper = Join-Path $defaultsRoot 'windows-vcredist.ps1'
    [IO.File]::Copy((Join-Path $projectRoot 'windows-vcredist.ps1'), $helper)
    [IO.File]::Copy((Join-Path $projectRoot 'windows-vcredist.json'), (Join-Path $defaultsRoot 'windows-vcredist.json'))
    [IO.File]::Copy((Join-Path $projectRoot 'windows-vcredist.json'), (Join-Path $alternate 'policy.json'))
    $adjacentPayload = Join-Path $defaultsRoot 'vc_redist.x64.exe'
    if ($childHasSignedPayload) { [IO.File]::Copy($PayloadPath, $adjacentPayload) }
    else {
        # Equal length forces the real hash command to run; the invalid binary
        # must be rejected before Authenticode on non-Windows development hosts.
        [IO.File]::WriteAllBytes($adjacentPayload, (New-Object byte[] ([int]$policy.bytes)))
    }
    [IO.File]::Copy($adjacentPayload, (Join-Path $alternate 'payload.exe'))
    $dotSourceBody = @'
$ErrorActionPreference = 'Stop'
$root = $env:VC_DEFAULTS_ROOT
$helper = [IO.Path]::Combine($root, 'windows-vcredist.ps1')
$expectedPayload = [IO.Path]::Combine($root, 'vc_redist.x64.exe')
$expectedPolicy = [IO.Path]::Combine($root, 'windows-vcredist.json')
if ($mode -eq 'explicit') {
    $expectedPayload = [IO.Path]::Combine($root, 'explicit paths', 'payload.exe')
    $expectedPolicy = [IO.Path]::Combine($root, 'explicit paths', 'policy.json')
    . $helper -Action ValidatePayload -PayloadPath $expectedPayload -PolicyPath $expectedPolicy
} elseif ($mode -eq 'empty') {
    . $helper -Action ValidatePayload -PayloadPath '' -PolicyPath ''
    if ($PayloadPath -cne '' -or $PolicyPath -cne '') { throw 'An explicit empty path fell back to a default.' }
    $rejected = $false
    try { $null = Read-DesktopVcPolicy -Path $PolicyPath } catch { $rejected = $true }
    if (-not $rejected) { throw 'An explicit empty policy path was accepted.' }
    Write-Output 'VC_CHILD_OK'; exit 0
} else { . $helper -Action ValidatePayload }
if ($PayloadPath -cne $expectedPayload -or $PolicyPath -cne $expectedPolicy) { throw 'The child resolved incorrect prerequisite paths.' }
foreach ($name in @('Microsoft.PowerShell.Management', 'Microsoft.PowerShell.Utility', 'Microsoft.PowerShell.Security')) {
    $module = Microsoft.PowerShell.Core\Get-Module -Name $name
    if ($module.Path -ne [IO.Path]::Combine($PSHOME, 'Modules', $name, ($name + '.psd1'))) { throw 'A prerequisite module came from another engine.' }
}
$p = Read-DesktopVcPolicy -Path $PolicyPath
if ($env:VC_SIGNED_PAYLOAD -eq '1') {
    $verified = Test-DesktopVcPayload -Path $PayloadPath -Policy $p
    if (-not $verified.signatureValid -or $verified.sha256 -cne $p.sha256) { throw 'Child payload validation was not complete.' }
} else {
    $rejected = $false
    try { $null = Test-DesktopVcPayload -Path $PayloadPath -Policy $p }
    catch { $rejected = $_.Exception.Message -ceq 'The bundled Microsoft Visual C++ prerequisite does not match its pinned bytes.' }
    if (-not $rejected) { throw 'The child did not enforce the real payload hash.' }
}
Write-Output 'VC_CHILD_OK'
'@
    foreach ($environment in @('inherited', 'restricted')) {
        $modulePath = if ($environment -eq 'restricted') { $foreignModules } else { $null }
        $fileResult = Invoke-VcChild -Arguments ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $helper + '" -Action ValidatePayload') -ModulePath $modulePath
        if ($childHasSignedPayload) {
            $receipt = if ($fileResult.Code -eq 0) { $fileResult.Output | ConvertFrom-Json } else { $null }
            Assert-Vc ($fileResult.Code -eq 0 -and $receipt.signatureValid -eq $true -and $receipt.sha256 -ceq $policy.sha256) ('Fresh -File default-path validation failed with ' + $environment + ' modules: ' + $fileResult.Error)
        } else {
            $invalidCode = if ([Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT) { 1603 } else { 1603 % 256 }
            Assert-Vc ($fileResult.Code -eq $invalidCode -and $fileResult.Error.Contains('The bundled Microsoft Visual C++ prerequisite does not match its pinned bytes.')) ('Fresh -File did not reach real hash validation with ' + $environment + ' modules: ' + $fileResult.Error)
        }
        $modes = if ($environment -eq 'inherited') { @('default', 'explicit', 'empty') } else { @('default') }
        foreach ($mode in $modes) {
            $command = '$mode = ''' + $mode + '''' + [Environment]::NewLine + $dotSourceBody
            $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($command))
            $dotResult = Invoke-VcChild -Arguments ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' + $encoded) -ModulePath $modulePath
            Assert-Vc ($dotResult.Code -eq 0 -and $dotResult.Output.Trim() -ceq 'VC_CHILD_OK') ('Fresh dot-source ' + $mode + ' validation failed with ' + $environment + ' modules: ' + $dotResult.Error)
        }
    }
} finally { if ([IO.Directory]::Exists($defaultsRoot)) { [IO.Directory]::Delete($defaultsRoot, $true) } }

# Exercise production control flow with only Windows registry/process/signature boundaries replaced.
# No runtime test switch, alternate payload path, or test bypass is compiled into the installer.
$savedPayload = ${function:Test-DesktopVcPayload}
$savedRegistry = ${function:Get-DesktopVcInstalledVersion}
$savedInstaller = ${function:Invoke-DesktopVcInstaller}
$fixture = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString('N') + '.exe')
[IO.File]::WriteAllText($fixture, 'a non-executable fixture held open by the real orchestration')
try {
    function Test-DesktopVcPayload { param($Path, $Policy)
        if ($script:invalidPayload) { throw 'test signature rejection' }
        return @{ sha256 = $Policy.sha256; signatureValid = $true } }
    function Get-DesktopVcInstalledVersion { return $script:registeredVersion }
    function Invoke-DesktopVcInstaller { param($Path, [switch] $Quiet)
        $script:launches++
        Assert-Vc ($Quiet -eq $script:expectQuiet) 'The requested visible/quiet mode changed.'
        if ([Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT) {
            Assert-VcFailure { [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Write, [IO.FileShare]::Read).Dispose() } 'Validated executable was replaceable during elevated process launch.'
        }
        if ($script:cancelApproval) { throw 'test UAC cancellation' }
        $script:registeredVersion = $script:afterVersion
        return $script:exitCode }
    $script:invalidPayload = $false; $script:cancelApproval = $false; $script:launches = 0
    $script:expectQuiet = $false; $script:exitCode = 0
    foreach ($installed in @($policy.version, '14.99.60000.0')) {
        $script:registeredVersion = [version]$installed
        $result = Initialize-DesktopVcRuntime -Path $fixture -Policy $policy
        Assert-Vc ($result.state -ceq 'already-installed' -and $script:launches -eq 0) 'Equal or newer runtime incorrectly requested elevation.'
    }
    foreach ($before in @($null, [version]'14.0.0.0')) {
        $script:registeredVersion = $before; $script:afterVersion = [version]$policy.version
        $result = Initialize-DesktopVcRuntime -Path $fixture -Policy $policy
        Assert-Vc ($result.state -ceq 'installed' -and $result.rebootRequired -eq $false) 'Missing/older runtime did not complete installation.'
    }
    $script:registeredVersion = $null; $script:exitCode = 3010; $script:expectQuiet = $true
    $result = Initialize-DesktopVcRuntime -Path $fixture -Policy $policy -Quiet
    Assert-Vc ($result.rebootRequired -eq $true) 'Restart requirement from Microsoft setup was lost.'
    $script:expectQuiet = $false; $script:registeredVersion = $null; $script:exitCode = 1638
    $script:afterVersion = [version]'14.99.60000.0'
    $result = Initialize-DesktopVcRuntime -Path $fixture -Policy $policy
    Assert-Vc ($result.state -ceq 'already-installed-after-race') 'A proven concurrent newer installation was not handled.'
    $script:registeredVersion = $null; $script:afterVersion = $null
    Assert-VcFailure { Initialize-DesktopVcRuntime -Path $fixture -Policy $policy } '1638 without a registered runtime was accepted.'
    foreach ($code in @(0, 3010, 1603, 1223)) {
        $script:registeredVersion = $null; $script:exitCode = $code
        Assert-VcFailure { Initialize-DesktopVcRuntime -Path $fixture -Policy $policy } 'Failed or unregistered prerequisite was accepted.'
    }
    $script:registeredVersion = $null; $script:cancelApproval = $true
    Assert-VcFailure { Initialize-DesktopVcRuntime -Path $fixture -Policy $policy } 'UAC decline was accepted.'
    $script:cancelApproval = $false; $script:invalidPayload = $true
    $beforeLaunches = $script:launches
    Assert-VcFailure { Initialize-DesktopVcRuntime -Path $fixture -Policy $policy } 'An invalid prerequisite reached process launch.'
    Assert-Vc ($script:launches -eq $beforeLaunches) 'An invalid payload requested administrator elevation.'
} finally {
    ${function:Test-DesktopVcPayload} = $savedPayload
    ${function:Get-DesktopVcInstalledVersion} = $savedRegistry
    ${function:Invoke-DesktopVcInstaller} = $savedInstaller
    Remove-Item -LiteralPath $fixture -Force
}
Write-Output ('PASS ' + $checks + ' Visual C++ prerequisite checks: missing/older/newer, cancellation, failure and restart; real signed payload=' +
    [bool]$PayloadPath + '; Windows executable lock=' + ([Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT) + '.')
