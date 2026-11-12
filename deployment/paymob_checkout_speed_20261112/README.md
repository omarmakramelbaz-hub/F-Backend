# Paymob checkout creation reliability

This package removes redundant legacy auth/order requests before Intention creation. It publishes only the active `checkingOut()` method and the wallet's pre-write email validation, against the exact deployed baseline `31d4020a9195904e1578bd6207f5dc805aa1541d`. It never replaces a live controller with the repository's older main version.

The request uses one Intention POST with a 3-second connection timeout and 15-second total timeout. HTTP/JSON/amount/currency/identifier errors return a structured failure instead of indexing invalid gateway data. A lost response remains uncertain; the POST is never automatically retried and creation never confirms payment.

The two existing private checkout settings are extracted only after full-file digest checks and retained in the generated live method. This public package contains placeholders and digests, not those settings. The guarded publisher never prints source text, upstream bodies, credentials, or PHP diagnostics. Original runtime source is saved only in root-owned `0700` backup directories with `0600` files.

## Checks

```sh
python3 -m unittest discover -s deployment/paymob_checkout_speed_20261112/tests -p 'test_*.py' -v
php deployment/paymob_checkout_speed_20261112/tests/checkout_harness.php
```

The PHP harness uses synthetic model/HTTP/authentication stubs; it makes no network calls. The focused GitHub workflow runs it using PHP 8.2. Python tests cover exact publication, stale/foreign baselines, repeated execution, untouched callbacks/transfers, symlink/hardlink rejection, metadata/backup permissions, and rollback on partial publication failure.

## Publication

Use `run_root.sh` with a full pinned commit SHA. Its default mode checks only; pass `--apply` for publication. The runner verifies downloaded file digests and runs the publisher on `srv1836524` as root.

The publisher rejects any unexpected edit to either complete live source file and verifies the existing Money runtime dependency against its pinned digest without changing it. If it reports `BASELINE_MISMATCH` or `DEPENDENCY_MISMATCH`, obtain a new read-only fingerprint and review the delta; do not weaken the guard or use a whole controller from main. Before publication it lints the generated complete PHP files with the server's PHP 8.2 binary, then creates protected backups and replaces files atomically. It preserves ownership/mode and checks for concurrent edits again before each replacement. It does not change callbacks, settlement services, balances, routes, scheduler settings, or disabled live-update timers.

For rollback, run the same pinned runner with `--rollback` and the exact `BACKUP=` path it returned. Rollback refuses files changed since publication and creates an additional protected backup. Application PHP-FPM cache behavior and a user-driven checkout still need live verification after source publication. No real charge is part of these tests.

## Deliberate boundary

This package does not claim durable creation idempotency. Repeated client requests can still create separate pending wallet attempts. It preserves existing merchant metadata and does not introduce a new reference format or assume a gateway deduplication parameter. Adding a persisted user-bound attempt key, parameter fingerprint, and result replay is a separate schema-backed change. Existing independently verified, idempotent settlement remains unchanged.
