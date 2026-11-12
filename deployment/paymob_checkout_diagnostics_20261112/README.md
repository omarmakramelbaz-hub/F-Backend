# Read-only Paymob source diagnostic

Use this package when exact live checkout source digests differ from the reviewed deployment baseline. It does not accept a new baseline or publish any application change.

The reader opens only the two fixed live controller paths, requires safe regular files and a stable read, then prints file hashes, ownership, size and line-ending metadata. A PHP `token_get_all` lexer extracts only `checkingOut`, `charging_wallet` and an optional constructor. It never includes, evaluates or executes the inspected source. Comments and string/interpolated/heredoc contents are removed, except a small exact allowlist of schema/config names and public URLs without queries. All numeric literals and unknown identifiers are masked. Namespace/import context is limited to expected public names/booleans.

The existing reviewed publisher is imported only for its pure transformation functions. Candidate source stays in memory; output contains only a success/unsupported status and SHA256. No candidate file, application state or datastore is written. These hashes support later source review; they do not approve deployment.

`run_root.sh FULL_COMMIT_SHA` downloads the three pinned reviewed Python/template files, verifies their hashes, then runs the diagnostic on `srv1836524`. The diagnostic performs no HTTP calls and never reads `.env`. PHP runs with configuration files disabled; if tokenizer is a shared standard extension, it attempts to load only that extension. PHP errors and exception contents are not returned.

Tests:

```sh
python3 -m unittest discover -s deployment/paymob_checkout_diagnostics_20261112/tests -p 'test_*.py' -v
```

The focused CI runs tokenizer/redaction/non-execution tests using actual PHP 8.2. Without local PHP, those cases skip explicitly while pure reader/candidate tests still run.
