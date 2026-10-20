; Per-user installation. The SQLite order ledger is in Electron userData and is preserved.
!include "MUI2.nsh"
!include "x64.nsh"
!include "FileFunc.nsh"
!include "${PROJECT_DIR}/dist/installer-version.nsh"
Name "${FASAKHANSTA_PRODUCT_NAME}"
; Retain the existing preview install location when the visible product name changes.
InstallDir "$LOCALAPPDATA\Programs\Fasakhansta Dashboard Preview"
RequestExecutionLevel user
Unicode true
SetCompressor /SOLID lzma
VIProductVersion "${FASAKHANSTA_NUMERIC_VERSION}"
VIAddVersionKey /LANG=1033 "ProductName" "${FASAKHANSTA_PRODUCT_NAME}"
VIAddVersionKey /LANG=1033 "FileDescription" "${FASAKHANSTA_PRODUCT_NAME} Setup"
VIAddVersionKey /LANG=1033 "InternalName" "${FASAKHANSTA_PRODUCT_NAME} Setup"
VIAddVersionKey /LANG=1033 "OriginalFilename" "GO-ERP-Preview-Setup.exe"
VIAddVersionKey /LANG=1033 "CompanyName" "GO ERP"
VIAddVersionKey /LANG=1033 "LegalCopyright" "Copyright (C) GO ERP"
VIAddVersionKey /LANG=1033 "FileVersion" "${FASAKHANSTA_VERSION}"
VIAddVersionKey /LANG=1033 "ProductVersion" "${FASAKHANSTA_VERSION}"
Var LegacyPreviewOwned
Var GoErpAutoUpdate
!ifndef MUI_ICON
  !define MUI_ICON "${PROJECT_DIR}/src/assets/app.ico"
!endif
!ifndef MUI_UNICON
  !define MUI_UNICON "${PROJECT_DIR}/src/assets/app.ico"
!endif
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
Function .onInit
  StrCpy $GoErpAutoUpdate "0"
  ${GetParameters} $R0
  ClearErrors
  ${GetOptions} $R0 "/GOERPAUTOUPDATE" $R1
  IfErrors update_init_manual
  StrCmp $R1 "" 0 update_init_failed
  IfSilent update_init_auto update_init_failed
  update_init_auto:
  StrCpy $GoErpAutoUpdate "1"
  ; Automatic replacement belongs only to this existing per-user GO install.
  ReadRegStr $R2 HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "DisplayName"
  StrCmp $R2 "${FASAKHANSTA_PRODUCT_NAME}" 0 update_init_failed
  ReadRegStr $R2 HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "UninstallString"
  StrCmp $R2 '"$INSTDIR\Uninstall.exe"' 0 update_init_failed
  ClearErrors
  ${GetOptions} $R0 "/GOERPWAITPID=" $R1
  IfErrors update_init_failed
  ; Canonical positive signed 32-bit decimal only; never interpret switches.
  StrLen $R2 $R1
  IntCmp $R2 1 0 update_init_failed 0
  IntCmp $R2 10 0 0 update_init_failed
  StrCpy $R3 0
  update_pid_digit:
  StrCpy $R4 $R1 1 $R3
  StrCmp $R4 "" update_pid_checked
  StrCmp $R4 "0" update_pid_next
  StrCmp $R4 "1" update_pid_next
  StrCmp $R4 "2" update_pid_next
  StrCmp $R4 "3" update_pid_next
  StrCmp $R4 "4" update_pid_next
  StrCmp $R4 "5" update_pid_next
  StrCmp $R4 "6" update_pid_next
  StrCmp $R4 "7" update_pid_next
  StrCmp $R4 "8" update_pid_next
  StrCmp $R4 "9" update_pid_next update_init_failed
  update_pid_next:
  IntOp $R3 $R3 + 1
  Goto update_pid_digit
  update_pid_checked:
  IntOp $R3 $R1 + 0
  IntCmp $R3 1 0 update_init_failed 0
  IntFmt $R4 "%u" $R3
  StrCmp $R1 $R4 0 update_init_failed
  ; Wait without killing any process. Timeout/access denial leaves the old install.
  StrCpy $0 $R3
  System::Call 'kernel32::OpenProcess(i 0x00100000, i 0, i r0) p.r1'
  StrCmp $1 "0" update_pid_open_failed
  System::Call 'kernel32::WaitForSingleObject(p r1, i 90000) i.r2'
  System::Call 'kernel32::CloseHandle(p r1)'
  StrCmp $2 "0" update_init_done update_init_failed
  update_pid_open_failed:
  System::Call 'kernel32::GetLastError() i.r2'
  ; ERROR_INVALID_PARAMETER means this PID has already gone away.
  StrCmp $2 "87" update_init_done update_init_failed
  update_init_manual:
  ; A wait switch without the automatic-upgrade contract is never accepted.
  ClearErrors
  ${GetOptions} $R0 "/GOERPWAITPID=" $R1
  IfErrors update_init_done update_init_failed
  update_init_failed:
  SetErrorLevel 1603
  Abort
  update_init_done:
FunctionEnd
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
  StrCmp $GoErpAutoUpdate "1" 0 current_go_preflight_done
  IfFileExists "$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe" 0 legacy_upgrade_failed
  System::Call 'kernel32::CreateFileW(w "$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe", i 0x40000000, i 7, p 0, i 3, i 0, p 0) p.r2'
  StrCmp $2 "-1" legacy_upgrade_failed
  System::Call 'kernel32::CloseHandle(p r2)'
  current_go_preflight_done:
  ; Only a matching previous preview registration proves these old names belong
  ; to this installation. An unrelated file in a custom directory is preserved.
  StrCpy $LegacyPreviewOwned "0"
  ReadRegStr $2 HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "DisplayName"
  StrCmp $2 "Fasakhansta Dashboard Preview" 0 legacy_preflight_done
  ReadRegStr $2 HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "UninstallString"
  StrCmp $2 '"$INSTDIR\Uninstall.exe"' 0 legacy_preflight_done
  StrCpy $LegacyPreviewOwned "1"
  IfFileExists "$INSTDIR\Fasakhansta Dashboard Preview.exe" 0 legacy_preflight_done
  ; OPEN_EXISTING with write access checks a running/locked image without
  ; creating, truncating or changing the previous executable.
  System::Call 'kernel32::CreateFileW(w "$INSTDIR\Fasakhansta Dashboard Preview.exe", i 0x40000000, i 7, p 0, i 3, i 0, p 0) p.r2'
  StrCmp $2 "-1" legacy_upgrade_failed
  System::Call 'kernel32::CloseHandle(p r2)'
  legacy_preflight_done:
  SetOutPath "$INSTDIR"
  File /r "${PROJECT_DIR}/dist/win-unpacked/*"
  ; Keep the old executable until the new application has been extracted.
  StrCmp $LegacyPreviewOwned "1" 0 legacy_cleanup_done
  IfFileExists "$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe" 0 legacy_upgrade_failed
  IfFileExists "$DESKTOP\Fasakhansta Dashboard Preview.lnk" 0 legacy_menu_cleanup
  ClearErrors
  Delete "$DESKTOP\Fasakhansta Dashboard Preview.lnk"
  IfErrors legacy_upgrade_failed
  legacy_menu_cleanup:
  IfFileExists "$SMPROGRAMS\Fasakhansta Dashboard Preview\Fasakhansta Dashboard Preview.lnk" 0 legacy_directory_cleanup
  ClearErrors
  Delete "$SMPROGRAMS\Fasakhansta Dashboard Preview\Fasakhansta Dashboard Preview.lnk"
  IfErrors legacy_upgrade_failed
  legacy_directory_cleanup:
  ; Nonrecursive removal deliberately retains any unrelated start-menu files.
  RMDir "$SMPROGRAMS\Fasakhansta Dashboard Preview"
  IfFileExists "$INSTDIR\Fasakhansta Dashboard Preview.exe" 0 legacy_cleanup_done
  ClearErrors
  Delete "$INSTDIR\Fasakhansta Dashboard Preview.exe"
  IfErrors legacy_upgrade_failed
  legacy_cleanup_done:
  ClearErrors
  WriteUninstaller "$INSTDIR\Uninstall.exe"
  CreateShortcut "$DESKTOP\${FASAKHANSTA_PRODUCT_NAME}.lnk" "$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe" "" "$INSTDIR\resources\app.ico"
  CreateDirectory "$SMPROGRAMS\${FASAKHANSTA_PRODUCT_NAME}"
  CreateShortcut "$SMPROGRAMS\${FASAKHANSTA_PRODUCT_NAME}\${FASAKHANSTA_PRODUCT_NAME}.lnk" "$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe" "" "$INSTDIR\resources\app.ico"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "DisplayName" "${FASAKHANSTA_PRODUCT_NAME}"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "UninstallString" '"$INSTDIR\Uninstall.exe"'
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "DisplayVersion" "${FASAKHANSTA_VERSION}"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "Publisher" "GO ERP"
  WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "DisplayIcon" '"$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe",0'
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "NoModify" 1
  WriteRegDWORD HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${FASAKHANSTA_UNINSTALL_KEY}" "NoRepair" 1
  Goto install_complete
  legacy_upgrade_failed:
  MessageBox MB_OK|MB_ICONSTOP "Close the previous GO ERP dashboard and run setup again. The existing device data has been preserved." /SD IDOK
  SetErrorLevel 1603
  Abort
  install_complete:
  StrCmp $GoErpAutoUpdate "1" 0 auto_restart_done
  IfRebootFlag auto_restart_done
  Exec '"$INSTDIR\${FASAKHANSTA_PRODUCT_NAME}.exe"'
  auto_restart_done:
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
