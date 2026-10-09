; Per-user installation. The SQLite order ledger is in Electron userData and is preserved.
!include "MUI2.nsh"
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
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro addLangs
Section "Install"
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
