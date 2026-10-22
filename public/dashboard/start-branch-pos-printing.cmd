@echo off
setlocal
set "FASAKHANSTA_LAUNCHER_SOURCE=%~f0"
powershell.exe -NoLogo -NoProfile -Command "$source=[IO.File]::ReadAllText($env:FASAKHANSTA_LAUNCHER_SOURCE); $parts=$source -split '(?m)^# POWERSHELL_START\r?$',2; if($parts.Count -ne 2){exit 1}; & ([ScriptBlock]::Create($parts[1]))"
if errorlevel 1 (
  echo Fasakhansta direct printing could not start. See the message above.
  pause
  exit /b 1
)
exit /b 0
# POWERSHELL_START
$ErrorActionPreference = 'Stop'
try {
    $chrome = @($env:ProgramFiles, ${env:ProgramFiles(x86)}, $env:LOCALAPPDATA) | Where-Object { $_ } |
        ForEach-Object { Join-Path $_ 'Google\Chrome\Application\chrome.exe' } |
        Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } | Select-Object -First 1
    if (-not $chrome) { throw 'Install Google Chrome on this branch computer, then run this file again.' }

    $defaultPrinter = Get-CimInstance -ClassName Win32_Printer | Where-Object Default | Select-Object -First 1
    if (-not $defaultPrinter) { throw 'Choose the branch receipt printer as the Windows default printer, then run this file again.' }
    if ($defaultPrinter.Name -match '(?i)Microsoft Print to PDF|Microsoft XPS Document Writer|OneNote|Fax') {
        throw 'Choose the physical branch receipt printer as the Windows default instead of a PDF or virtual printer.'
    }

    # This profile is separate from ordinary Chrome, old PWAs and the V2 cashier.
    # Chrome reuses an existing profile process and ignores new startup flags.
    $profile = Join-Path $env:LOCALAPPDATA 'FasakhanstaCashierDirectV3'
    $profileArgument = '--user-data-dir="' + $profile + '"'
    $processes = @(Get-CimInstance Win32_Process -Filter "Name = 'chrome.exe'" | Where-Object {
        $_.CommandLine -and $_.CommandLine.Contains($profile) -and $_.CommandLine -notmatch '--type='
    })
    if ($processes | Where-Object { $_.CommandLine -notmatch '(?:^|\s)--kiosk-printing(?:\s|$)' }) {
        throw 'Close the Fasakhansta direct-print cashier window, then open this launcher again. Other Chrome windows can stay open.'
    }

    $installDirectory = Join-Path $env:LOCALAPPDATA 'FasakhanstaPOS'
    [IO.Directory]::CreateDirectory($installDirectory) | Out-Null
    $installedLauncher = Join-Path $installDirectory 'start-direct-printing.cmd'
    $isSetup = -not [String]::Equals($env:FASAKHANSTA_LAUNCHER_SOURCE, $installedLauncher, [StringComparison]::OrdinalIgnoreCase)
    if ($isSetup) { Copy-Item -LiteralPath $env:FASAKHANSTA_LAUNCHER_SOURCE -Destination $installedLauncher -Force }

    # The shortcut runs this launcher every time, retaining the kiosk flag and
    # printer checks even after the downloaded file is moved or deleted.
    $shortcutPath = Join-Path ([Environment]::GetFolderPath('Desktop')) 'Fasakhansta POS.lnk'
    $shortcut = (New-Object -ComObject WScript.Shell).CreateShortcut($shortcutPath)
    $shortcut.TargetPath = Join-Path $env:SystemRoot 'System32\cmd.exe'
    $shortcut.Arguments = '/d /c ""' + $installedLauncher + '""'
    $shortcut.WorkingDirectory = $installDirectory
    $shortcut.IconLocation = $chrome + ',0'
    $shortcut.WindowStyle = 7
    $shortcut.Save()

    $page = if ($isSetup) { 'https://fasakhaninja.com/admin/print-settings' } else { 'https://fasakhaninja.com/admin/dashboard' }
    Start-Process -FilePath $chrome -ArgumentList @($profileArgument, '--kiosk-printing', '--no-first-run', '--no-default-browser-check', ('--app="' + $page + '"'))
    Write-Host ('Direct-print cashier opened. Windows default printer: ' + $defaultPrinter.Name)
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
