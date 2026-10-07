; Per-user installation. The SQLite order ledger is in Electron userData and is preserved.
!include "MUI2.nsh"
Name "Fasakhansta POS"
InstallDir "$LOCALAPPDATA\Programs\Fasakhansta POS"
RequestExecutionLevel user
Unicode true
SetCompressor /SOLID lzma
Icon "${PROJECT_DIR}/src/assets/app.ico"
UninstallIcon "${PROJECT_DIR}/src/assets/app.ico"
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro addLangs
Section "Install"
  SetOutPath "$INSTDIR"
  File /r "${PROJECT_DIR}/dist/win-unpacked/*"
  WriteUninstaller "$INSTDIR\Uninstall.exe"
  CreateShortcut "$DESKTOP\Fasakhansta POS.lnk" "$INSTDIR\Fasakhansta POS.exe" "" "$INSTDIR\resources\app.ico"
  CreateDirectory "$SMPROGRAMS\Fasakhansta POS"
  CreateShortcut "$SMPROGRAMS\Fasakhansta POS\Fasakhansta POS.lnk" "$INSTDIR\Fasakhansta POS.exe" "" "$INSTDIR\resources\app.ico"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaPOS" "DisplayName" "Fasakhansta POS"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaPOS" "UninstallString" '"$INSTDIR\Uninstall.exe"'
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaPOS" "DisplayVersion" "0.1.0"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaPOS" "Publisher" "Fasakhansta"
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaPOS" "NoModify" 1
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaPOS" "NoRepair" 1
SectionEnd
Section "Uninstall"
  Delete "$DESKTOP\Fasakhansta POS.lnk"
  Delete "$SMPROGRAMS\Fasakhansta POS\Fasakhansta POS.lnk"
  RMDir "$SMPROGRAMS\Fasakhansta POS"
  DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaPOS"
  ; Deliberately preserve $APPDATA\fasakhansta-desktop-pos and its unsynced orders.
  !include "${PROJECT_DIR}/dist/uninstall-files.nsh"
  Delete "$INSTDIR\Uninstall.exe"
  RMDir "$INSTDIR"
SectionEnd
