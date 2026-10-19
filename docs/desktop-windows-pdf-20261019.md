# Windows contract PDF preparation

This increment prepares the original contract PDF route for the Windows desktop
runtime. It remains a preview with `fullDashboard=false`; no production action,
publication, completed Windows smoke or physical-printer acceptance is claimed.

The previous configuration selected a Linux h4cc executable on Windows. The
configuration now selects the sibling `wkhtmltox/bin/wkhtmltopdf.exe` on Windows
and keeps the existing Linux path. The Windows process timeout is 30 seconds.
The image executable uses the same bundled Windows distribution.

The existing PDF controller also referenced obsolete instance fields and a
missing view. It now reads the saved `template`, requires the existing
`contract-list` permission, and renders escaped plain text with block/line breaks.
The saved placeholders remain literal because current contract rows do not
contain populated vendor/date instance data. Existing `AdminScope` semantics and
ordinary shared-template HTML visibility are retained. No new button, data field,
persistence or journal operation is introduced.

The PDF has inline styles, system fonts and no external assets. The route disables
JavaScript, plugins, images, local-file loading and link actions. The HTTP fixture
checks malicious markup, Blade-like text, a local-file sentinel and a loopback
asset canary against the actual application.

## Pinned native input and packaging

The input is the [official portable x64 release](https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox-0.12.6-1.mxe-cross-win64.7z),
14,659,532 bytes, SHA256
`6ce994c89d5f6018fa158e149a2de8fbbbeb14d8ff5cb656c89893bc556e0557`.
This digest was computed from the official upstream download. Upstream does not
publish an archive checksum or binary signature for this asset; the executables
have no embedded Authenticode certificate. Signed source tags do not constitute
a signature on those binaries.

`desktop-pos/src/wkhtmltox-windows.json` pins the archive, all eight native/header
files, and copied license/source notices. The preparation script verifies the
archive before extraction and rejects unexpected or changed payload files. The
runtime builder adds all original payload files, notices and `provenance.json`.
`manifest.pdfRuntime` binds this component independently of application sources.
Installer validation requires it when the application has Snappy configuration,
and rejects absent, changed or extra native files. Complete runtime receipts retain
every PDF byte across application updates. Previously authenticated runtime
receipts remain authoritative for their own older PDF version and unsynced data.

The notices include LGPL/GPL, Qt/WebKit and other upstream texts, exact source and
Qt-submodule commits, and pinned packaging/MXE recipes. They preserve review
evidence and do not assert that binary-redistribution obligations or release
review are complete. The normal dependency/release gates remain unchanged.

On Windows, prepare a new native input directory, then pass its `wkhtmltox`
subdirectory as the final builder argument:

```text
node .github/scripts/desktop-wkhtmltox.cjs new-input-directory
node desktop-pos/build-runtime.cjs source target dependencies php mariadb source-revision new-input-directory/wkhtmltox
```

## Evidence and remaining native gate

All 256 local Node checks pass, including the actual downloaded payload, license
retention, installer admission, tamper rejection and retained-version recovery.
The 152 deployment/source checks pass with 104 executed and 48 root-ownership
checks skipped locally. A clean Git source bundle from `6ecae31` matches the
reviewed source fingerprint and all 35 native/notice hashes. Its real
Laravel/MariaDB render and permission fixture passes 52 checks with exact Git
helper bindings, all 109 fixture tables unchanged, no
journal commands, no asset-canary requests and complete private-child/database
cleanup. The native Windows executable has not been run locally.

The Windows workflow restores the pinned component, builds under a path containing
spaces, and executes the original authenticated contract-download route through
the actual Barryvdh/Knp generator. A separate test-only pypdf6.10.0 wheel is pinned
by its verified PyPI SHA256; it is not shipped in the application. The parser
requires the PDF's English/Arabic text and literal placeholders, checks its page
tree/actions, and writes a receipt bound to the HTTP PDF and exact source revision.
The workflow retains the PDF, rendered HTML and both receipts.

The source manifest remains bound to the measured 1,157-file production baseline.
This target has 1,286 source files, fingerprint
`97a185c1791ccc52ad6ef3c2abf9e563a0b0268f3009b1916f03532845f1b514`.
Its launcher pins are refreshed for review; no server script has been run.
Successful Windows evidence is still required before describing PDF support as
verified on Windows. Visual Arabic typography and physical printers require
their own device acceptance.

The contract smoke does not cover other order/report PDF views. Some existing
views load remote fonts, logos or CSS; selecting a Windows executable does not
establish those exports' complete offline assets or rendering behavior.
