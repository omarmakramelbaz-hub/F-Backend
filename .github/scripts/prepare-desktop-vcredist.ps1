[CmdletBinding()]
param([string] $Project = 'desktop-pos')
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath $Project).Path
. (Join-Path $projectRoot 'windows-vcredist.ps1')
$policy = Read-DesktopVcPolicy -Path (Join-Path $projectRoot 'windows-vcredist.json')
$directory = Join-Path $projectRoot 'dist/prerequisites'
New-Item -ItemType Directory -Path $directory -Force | Out-Null
$payload = Join-Path $directory 'vc_redist.x64.exe'
$temporary = Join-Path $directory ([Guid]::NewGuid().ToString('N') + '.exe')
try {
    Invoke-WebRequest -Uri $policy.url -OutFile $temporary -UseBasicParsing
    $verified = Test-DesktopVcPayload -Path $temporary -Policy $policy
    Move-Item -LiteralPath $temporary -Destination $payload -Force
    $verified | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $directory 'vcredist-package.json') -Encoding utf8
    Write-Output ('Verified official Microsoft Visual C++ x64 ' + $verified.version + ' with its pinned SHA256 and trusted Microsoft signature.')
} finally {
    if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force }
}
