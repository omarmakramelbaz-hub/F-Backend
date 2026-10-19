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
