# Stock email limited to physical branches

The consolidated stock email covers Fasakhansta's physical branch registry only.
Retail stores and GO identities cannot trigger, delay, or appear in the physical
branch email. Existing stock figures, minimum thresholds, episode history, and
outbox rows are retained. The owner recipient and styled report view are reused.

## Exact source scope

Only `app/Services/Dashboard/BranchStockMinimums.php` is published.

| Known installed source | Value |
| --- | --- |
| Parent commit | `2ce66fdb11030c54593f1d6941d7354936077c4a` |
| Git blob | `4c139c020ef6e5b9db15fdbce6ab5853ec183aac` |
| SHA256 | `7e9cef9cef02be01fbb3f4c405dae213e8f60fde1c161b2c170f7c2f052264b7` |

The known before service is identical to the previously installed consolidated
report service at `a032130a`. The installer requires release ancestry from the
full parent above. It accepts only that exact known before body or the exact
pinned after body. Unknown independent edits are preserved and cause refusal
before source publication. Reapplying the same after version is idempotent.

The installer makes no schema, migration, scheduling, printer, stock-data,
minimum-setting, or mailbox changes. It does not load Composer dependencies,
bootstrap Laravel, connect to a database, invoke a notification command, or send
a test email. It does not run a checkout/reset or replace other application files.

## Production invocation

Fetch `codex/stock-email-branches-only-20261020` as `fasakha` with the reviewed
full commit pinned. Verify `FETCH_HEAD` equals that commit. Extract
`deployment/apply_stock_branches_only_root.sh` from that exact commit into a
root-owned temporary directory and verify its reviewed Git blob before running:

```bash
bash /private/temporary/apply_stock_branches_only_root.sh REVIEWED_REVISION
```

The wrapper requires root on `srv1836524`, application root
`/home/fasakha/public_html`, deployment account `fasakha`, and executable
`/opt/cpanel/ea-php82/root/usr/bin/php`. It verifies the Python installer from the
pinned commit, runs preflight automatically, and then applies the single file.
A separate `--check` invocation is optional and publishes no application source.

The entire wrapper, including cleanup, is serialized by the root-owned
`/run/fasakhansta-stock-branches-only.lock`. Preflight uses `php -l` only. It
requires neither Node nor a `vendor` directory and cannot call application mail
code. The account-owned installer also holds a private file lock, verifies
source ownership and symlinks, and checks for concurrent source changes.

## Backup, retry, and rollback

Original source, permissions, ownership metadata, before/after hashes, and the
receipt are stored outside the web root in
`/home/fasakha/stock-branches-only-backups/`. Source replacement is atomic and
retains its permission mode and group. No database state is modified during the
installation.

Success prints `STOCK_BRANCHES_ONLY_RELEASE_COMPLETE` and the installed receipt
path. A repeat prints `STOCK_BRANCHES_ONLY_ALREADY_INSTALLED`. If publication
fails, the original is restored while the current body is still the exact
candidate. Failures that occur immediately after the atomic rename are covered
by rollback as well. An independent edit encountered after publication is
preserved and recorded as `rollback_needs_review`; it is never overwritten during
recovery.

The installer sends no message. The existing scheduled process uses the updated
service on a subsequent run; its configuration is unchanged.

## Local validation

```bash
python3 -m unittest tests/test_stock_branches_only_install.py -v
bash -n deployment/apply_stock_branches_only_root.sh
python3 -m py_compile deployment/apply_stock_branches_only.py
```

Disposable Git/file fixtures cover the exact one-file scope, pinned before
provenance, syntax refusal, idempotence, backups and metadata, symlink/hardlink
refusal, concurrent edits, failures before and after rename, rollback preservation,
and simultaneous root-wrapper invocations. No test contacts a mailbox or database.
