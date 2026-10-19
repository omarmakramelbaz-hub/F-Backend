; Per-user installation. The SQLite order ledger is in Electron userData and is preserved.
!include "MUI2.nsh"
!include "x64.nsh"
!include "${PROJECT_DIR}/dist/installer-version.nsh"
Name "${FASAKHANSTA_PRODUCT_NAME}"
InstallDir "$LOCALAPPDATA\Programs\${FASAKHANSTA_PRODUCT_NAME}"
RequestExecutionLevel user
Unicode true
SetCompressor /SOLID lzma
Icon "${PROJECT_DIR}/src/assets/app.ico"
UninstallIcon "${PROJECT_DIR}/src/assets/app.ico"
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!define MUI_FINISHPAGE_RUN "$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe"
!define MUI_FINISHPAGE_RUN_TEXT "فتح الداشبورد الآن"
!define MUI_FINISHPAGE_RUN_FUNCTION LaunchDashboard
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro addLangs
Function LaunchDashboard
  IfRebootFlag needs_reboot
  Exec '"$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe"'
  needs_reboot:
FunctionEnd
Section "Install"
  ; Complete the machine prerequisite before touching the per-user application.
  ; The only elevated executable is Microsoft's signed, pinned redistributable.
  ${IfNot} ${RunningX64}
    MessageBox MB_OK|MB_ICONSTOP "This dashboard requires 64-bit Windows." /SD IDOK
    SetErrorLevel 1603
    Abort
  ${EndIf}
  InitPluginsDir
  SetOutPath "$PLUGINSDIR\fasakhansta-vcredist"
  File /oname=vc_redist.x64.exe "${PROJECT_DIR}/dist/prerequisites/vc_redist.x64.exe"
  File /oname=windows-vcredist.json "${PROJECT_DIR}/windows-vcredist.json"
  File /oname=windows-vcredist.ps1 "${PROJECT_DIR}/windows-vcredist.ps1"
  DetailPrint "Checking Microsoft Visual C++ x64. Windows may request administrator approval for this component."
  IfSilent prerequisite_silent prerequisite_visible
  prerequisite_visible:
    StrCpy $1 ""
    Goto prerequisite_run
  prerequisite_silent:
    StrCpy $1 "-Silent"
  prerequisite_run:
  nsExec::ExecToLog '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "$PLUGINSDIR\fasakhansta-vcredist\windows-vcredist.ps1" $1'
  Pop $0
  StrCmp $0 "0" prerequisite_ready
  StrCmp $0 "3010" prerequisite_reboot
  MessageBox MB_OK|MB_ICONSTOP "Microsoft Visual C++ x64 could not be installed or administrator approval was cancelled. The dashboard has not been installed. Run setup again to retry." /SD IDOK
  SetErrorLevel 1603
  Abort
  prerequisite_reboot:
    SetRebootFlag true
    SetErrorLevel 3010
  prerequisite_ready:
  SetOutPath "$INSTDIR"
  File /r "${PROJECT_DIR}/dist/win-unpacked/*"
  WriteUninstaller "$INSTDIR\Uninstall.exe"
  CreateShortcut "$DESKTOP\${FASAKHANSTA_PRODUCT_NAME}.lnk" "$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe" "" "$INSTDIR\resources\app.ico"
  CreateDirectory "$SMPROGRAMS\${FASAKHANSTA_PRODUCT_NAME}"
  CreateShortcut "$SMPROGRAMS\${FASAKHANSTA_PRODUCT_NAME}\${FASAKHANSTA_PRODUCT_NAME}.lnk" "$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe" "" "$INSTDIR\resources\app.ico"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "DisplayName" "${FASAKHANSTA_PRODUCT_NAME}"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "UninstallString" '"$INSTDIR\Uninstall.exe"'
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "DisplayVersion" "${FASAKHANSTA_VERSION}"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "Publisher" "Fasakhansta"
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "NoModify" 1
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "NoRepair" 1
SectionEnd
Section "Uninstall"
  Delete "$DESKTOP\${FASAKHANSTA_PRODUCT_NAME}.lnk"
  Delete "$SMPROGRAMS\${FASAKHANSTA_PRODUCT_NAME}\${FASAKHANSTA_PRODUCT_NAME}.lnk"
  RMDir "$SMPROGRAMS\${FASAKHANSTA_PRODUCT_NAME}"
  DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}"
  ; Deliberately preserve $APPDATA\fasakhansta-desktop-pos and its unsynced orders.
  !include "${PROJECT_DIR}/dist/uninstall-files.nsh"
  Delete "$INSTDIR\Uninstall.exe"
  RMDir "$INSTDIR"
SectionEnd
