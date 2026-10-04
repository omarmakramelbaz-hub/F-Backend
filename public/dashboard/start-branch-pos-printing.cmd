@echo off
setlocal
set "FASAKHANSTA_CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not exist "%FASAKHANSTA_CHROME%" set "FASAKHANSTA_CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not exist "%FASAKHANSTA_CHROME%" set "FASAKHANSTA_CHROME=%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"
if not exist "%FASAKHANSTA_CHROME%" (
  echo Google Chrome is required. Install Chrome and run this file again.
  pause
  exit /b 1
)
rem Use a separate profile so ordinary Chrome and installed-app windows keep their settings.
set "FASAKHANSTA_PROFILE=%LOCALAPPDATA%\FasakhanstaCashierV2"
powershell -NoProfile -Command "$s=(New-Object -ComObject WScript.Shell).CreateShortcut([IO.Path]::Combine([Environment]::GetFolderPath('Desktop'),'Fasakhansta POS.lnk'));$s.TargetPath=$env:FASAKHANSTA_CHROME;$s.Arguments='--user-data-dir='+[char]34+$env:FASAKHANSTA_PROFILE+[char]34+' --kiosk-printing --app='+[char]34+'https://fasakhaninja.com/admin/phone-orders?view=orders'+[char]34;$s.IconLocation=$env:FASAKHANSTA_CHROME+',0';$s.Save()"
if errorlevel 1 echo Desktop shortcut could not be created. Keep this launcher to open the cashier.
start "Fasakhansta Branch POS" "%FASAKHANSTA_CHROME%" --user-data-dir="%FASAKHANSTA_PROFILE%" --kiosk-printing --app="https://fasakhaninja.com/admin/print-settings"
endlocal
