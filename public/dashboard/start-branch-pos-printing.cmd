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
rem A dedicated profile preserves the kiosk flag without closing other browser windows.
start "Fasakhansta Branch POS" "%FASAKHANSTA_CHROME%" --user-data-dir="%LOCALAPPDATA%\FasakhanstaBranchPOS" --kiosk-printing --app="https://fasakhaninja.com/admin/phone-orders?view=orders"
endlocal
