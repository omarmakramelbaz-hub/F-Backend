# Windows Visual C++ prerequisite

Official Windows PHP builds require the Microsoft Visual C++ x64 runtime. The
dashboard installer bundles the official Microsoft redistributable so preparing a
clean branch computer does not require a separate download or an Internet
connection during installation. The dashboard itself remains a per-user install.

The reviewed payload is **14.51.36247.0**, 18,731,856 bytes, SHA-256
`843068991daaa1f73ad9f6239bce4d0f6a07a51f18c37ea2a867e9beca71295c`.
`desktop-pos/windows-vcredist.json` records its fixed Microsoft URL and the actual
Microsoft signing certificate SHA-256. Preparation and installation check the
complete file hash, size, file version, Windows Authenticode validity and exact
Microsoft signer. The source artifact was independently verified with
`osslsigncode`: its image digest, signing chain to Microsoft's published Root
Certificate Authority 2011 and current CRL verification passed. No binary is
committed to the repository.

Before copying any dashboard files, registering uninstall information or creating
shortcuts, setup checks the 64-bit Visual C++ registry entry. A registered equal or
newer version proceeds without elevation. A missing or older version runs only
the verified Microsoft executable using `/install /passive /norestart`; silent
setup uses `/quiet`. Windows may request administrator approval for that Microsoft
component. Cancellation, verification failure, setup failure or a missing runtime
after setup aborts before dashboard installation. A read lock prevents replacing
the verified executable while its elevated process is running. A required restart
is retained and the finish page does not launch the dashboard before that restart.
The app uninstaller leaves the shared Microsoft runtime installed.

On a Windows build host, prepare the exact payload before the ordinary packaging
command:

```powershell
& .github/scripts/prepare-desktop-vcredist.ps1 -Project desktop-pos
& desktop-pos/test/windows-prerequisite.ps1 -Project desktop-pos -PayloadPath desktop-pos/dist/prerequisites/vc_redist.x64.exe
```

Both installer workflows perform preparation and execute the behavior fixture in
PowerShell 7 and Windows PowerShell 5.1, which setup uses. The fixture exercises
missing, older, equal and newer versions, cancellation, failure, missing registry
confirmation, restart, a concurrent newer install and the executable read lock.
It checks the real Microsoft signature and rejects tampered bytes, a changed signer
pin and a changed version. Native installer acceptance additionally compiles the
actual NSIS script with a deliberately tampered prerequisite and a tiny app fixture,
then requires failure before extraction, shortcuts or registration. The accepted
installer's receipt includes the real registered runtime version and verified
payload descriptor. These new Windows checks must pass on the published source;
Linux orchestration checks and a successful NSIS compile alone do not establish
clean-machine Windows acceptance.

Upstream references:

- [PHP Windows downloads and Visual C++ requirement](https://www.php.net/downloads.php?os=windows)
- [Microsoft latest supported Visual C++ redistributables](https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist)
- [Microsoft runtime detection, command options and redistribution](https://learn.microsoft.com/en-us/cpp/windows/redistributing-visual-cpp-files)
