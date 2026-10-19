# Windows PowerShell 5.1: the app stays per-user; only Microsoft's signed runtime requests UAC.
[CmdletBinding()]
param(
    [ValidateSet('ValidatePayload', 'EnsureInstalled')][string] $Action = 'EnsureInstalled',
    [string] $PayloadPath = (Join-Path $PSScriptRoot 'vc_redist.x64.exe'),
    [string] $PolicyPath = (Join-Path $PSScriptRoot 'windows-vcredist.json'),
    [string] $ReceiptPath,
    [switch] $Silent
)

function Read-DesktopVcPolicy {
    param([string] $Path)
    $policy = Get-Content -LiteralPath $Path -Raw -ErrorAction Stop | ConvertFrom-Json
    if ($policy.format -ne 1 -or $policy.architecture -cne 'x64' -or
        $policy.version -notmatch '^14\.[0-9]+\.[0-9]+\.[0-9]+$' -or
        $policy.bytes -lt 1000000 -or $policy.bytes -gt 100000000 -or
        $policy.sha256 -cnotmatch '^[a-f0-9]{64}$' -or
        $policy.signerSha256 -cnotmatch '^[a-f0-9]{64}$' -or
        $policy.url -cnotmatch '^https://download\.visualstudio\.microsoft\.com/download/pr/[a-f0-9-]+/[A-F0-9]{64}/VC_redist\.x64\.exe$') {
        throw 'The pinned Microsoft Visual C++ prerequisite policy is invalid.'
    }
    return $policy
}

function Get-DesktopVcInstalledVersion {
    # Explicit Registry64 avoids the 32-bit NSIS/PowerShell registry redirector.
    $hive = [Microsoft.Win32.RegistryKey]::OpenBaseKey(
        [Microsoft.Win32.RegistryHive]::LocalMachine, [Microsoft.Win32.RegistryView]::Registry64)
    $key = $null
    try {
        $key = $hive.OpenSubKey('SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64')
        if ($null -eq $key -or $key.GetValue('Installed') -ne 1) { return $null }
        $parts = @('Major', 'Minor', 'Bld', 'Rbld') | ForEach-Object { $key.GetValue($_) }
        if ($parts.Count -ne 4 -or @($parts | Where-Object { $null -eq $_ -or $_ -lt 0 -or $_ -gt 65535 }).Count) {
            return $null
        }
        return [version]($parts -join '.')
    } finally {
        if ($null -ne $key) { $key.Dispose() }
        $hive.Dispose()
    }
}

function Test-DesktopVcPayload {
    param([string] $Path, $Policy)
    $file = Get-Item -LiteralPath $Path -ErrorAction Stop
    if ($file.Length -ne $Policy.bytes -or
        (Get-FileHash -LiteralPath $Path -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant() -cne $Policy.sha256) {
        throw 'The bundled Microsoft Visual C++ prerequisite does not match its pinned bytes.'
    }
    $signature = Get-AuthenticodeSignature -LiteralPath $Path -ErrorAction Stop
    if ($signature.Status -ne [System.Management.Automation.SignatureStatus]::Valid -or
        $null -eq $signature.SignerCertificate -or
        $signature.SignerCertificate.GetNameInfo([Security.Cryptography.X509Certificates.X509NameType]::SimpleName, $false) -cne 'Microsoft Corporation') {
        throw 'The bundled Visual C++ prerequisite does not have a valid Microsoft signature.'
    }
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $signer = ([BitConverter]::ToString($sha.ComputeHash($signature.SignerCertificate.RawData))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
    $version = [Diagnostics.FileVersionInfo]::GetVersionInfo($file.FullName).FileVersion
    if ($signer -cne $Policy.signerSha256 -or $version -cne $Policy.version) {
        throw 'The Microsoft prerequisite signer or version differs from the reviewed release.'
    }
    return [ordered]@{ format = 1; architecture = 'x64'; version = $version; bytes = $file.Length;
        sha256 = $Policy.sha256; signerSha256 = $signer; signatureValid = $true }
}

function Invoke-DesktopVcInstaller {
    param([string] $Path, [switch] $Quiet)
    $display = if ($Quiet) { '/quiet' } else { '/passive' }
    try {
        # Never elevate a custom bootstrap script or the per-user dashboard itself.
        $process = Start-Process -FilePath $Path -ArgumentList @('/install', $display, '/norestart') `
            -Verb RunAs -Wait -PassThru -ErrorAction Stop
        return $process.ExitCode
    } catch {
        if ($_.Exception -is [ComponentModel.Win32Exception] -and $_.Exception.NativeErrorCode -eq 1223) {
            $failure = New-Object InvalidOperationException('Windows administrator approval for Microsoft Visual C++ was cancelled.')
            $failure.Data['InstallerExitCode'] = 1223
            throw $failure
        }
        throw
    }
}

function Initialize-DesktopVcRuntime {
    param([string] $Path, $Policy, [switch] $Quiet)
    if (-not [Environment]::Is64BitOperatingSystem) { throw 'The dashboard requires 64-bit Windows.' }
    # Hold a read-only sharing handle through validation and process completion:
    # another process cannot replace the verified executable before UAC launches it.
    $locked = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
    try {
        $verified = Test-DesktopVcPayload -Path $Path -Policy $Policy
        $minimum = [version]$Policy.version
        $installed = Get-DesktopVcInstalledVersion
        if ($null -ne $installed -and $installed -ge $minimum) {
            return [ordered]@{ format = 1; state = 'already-installed'; installedVersion = $installed.ToString();
                rebootRequired = $false; payload = $verified }
        }
        $code = Invoke-DesktopVcInstaller -Path $Path -Quiet:$Quiet
        if ($code -notin @(0, 3010, 1638)) {
            throw ('Microsoft Visual C++ setup failed with exit code ' + $code + '. The dashboard was not installed.')
        }
        $installed = Get-DesktopVcInstalledVersion
        if ($null -eq $installed -or $installed -lt $minimum) {
            throw 'Microsoft Visual C++ setup did not register the required x64 runtime. The dashboard was not installed.'
        }
        # 1638 is accepted only after a concurrent newer installation is proven.
        return [ordered]@{ format = 1; state = $(if ($code -eq 1638) { 'already-installed-after-race' } else { 'installed' });
            installedVersion = $installed.ToString(); rebootRequired = ($code -eq 3010); payload = $verified }
    } finally { $locked.Dispose() }
}

if ($MyInvocation.InvocationName -ne '.') {
    $ErrorActionPreference = 'Stop'
    try {
        $policy = Read-DesktopVcPolicy -Path $PolicyPath
        if ($Action -eq 'ValidatePayload') { $result = Test-DesktopVcPayload -Path $PayloadPath -Policy $policy }
        else { $result = Initialize-DesktopVcRuntime -Path $PayloadPath -Policy $policy -Quiet:$Silent }
        $json = $result | ConvertTo-Json -Depth 5
        if ($ReceiptPath) { [IO.File]::WriteAllText($ReceiptPath, $json + [Environment]::NewLine, (New-Object Text.UTF8Encoding($false))) }
        Write-Output $json
        if ($Action -eq 'EnsureInstalled' -and $result.rebootRequired) { exit 3010 }
        exit 0
    } catch {
        [Console]::Error.WriteLine($_.Exception.Message)
        if ($_.Exception.Data['InstallerExitCode']) { exit ([int]$_.Exception.Data['InstallerExitCode']) }
        exit 1603
    }
}
