The contract PDF checks use the original Laravel application, login controller,
permission middleware, contract model, Blade view and Snappy implementation.
They create a unique disposable MariaDB database from the 106 inspected table
shapes, then add the existing three journal tables. All rows are synthetic.

Run the portable route/render regression:

```text
php tests/desktop_dashboard_runtime/contract-pdf-http.php application maria-port artifact-directory render source-revision
```

Run the native smoke on Windows against an untouched generated bundle, preferably
under a directory containing spaces:

```text
php tests/desktop_dashboard_runtime/contract-pdf-http.php application maria-port artifact-directory windows source-revision
python tests/desktop_dashboard_runtime/verify-contract-pdf.py artifact-directory source-revision
```

The artifact directory must exist. The Python parser is a test-only dependency
installed from `requirements-pdf-smoke.txt` with pip's `--require-hashes` and
`--only-binary=:all:` options. It is not included in the application runtime.

`receipt.json` records source/helper hashes, actual HTTP attempts, scoped and
permission denials, template/rendered-text hashes, database cleanup, unchanged
table hashes, an empty journal and zero external-asset canary requests. Windows
mode additionally verifies the bundled executable's policy hash, binary hash and
native version, and downloads the PDF through the original signed-in HTTP route.
It retains `contract_100.html` and `contract_100.pdf`. The separate strict parser
checks the PDF page tree and extracts the saved English, Arabic and literal
placeholder text, then writes `parsed-pdf-receipt.json` bound to the HTTP receipt.
CI binds all helpers to the exact runtime Git revision; local runs explicitly
report their working-tree helper binding.

Contract records contain saved global templates (`type`, `template`, `added_by`),
not populated vendor/date contract instances. Preview and PDF preserve literal
placeholders, strip stored markup into escaped text, and retain block/line breaks.
No contract data or journal operation is written by either read route.

The route requires `contract-list`, matching `contracts.show`. It retains the
existing model scope: AdminScope restricts JSON/API/chat requests; ordinary HTML
continues to display shared templates as the current show route does. The test
therefore proves foreign-template 404 with JSON reads and separately checks the
existing ordinary-HTML behavior. It does not claim broader owner isolation.

These checks leave `fullDashboard=false`. PDF text extraction does not certify
physical printer behavior or visual Arabic typography on every installed font.
