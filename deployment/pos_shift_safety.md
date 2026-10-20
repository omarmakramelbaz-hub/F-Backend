# POS kitchen and shift closing safety

A dining ticket can be sent to the kitchen only once during its lifetime. Later
changes are saved and included in the bill without creating another kitchen
print job. When a changed ticket is billed, the changes are saved before billing.
After billing, only completion and collection remain available.

Shift closing shows and blocks unfinished dining, delivery, and application orders,
including orders left open on previous days. Existing continuous drawer behavior
is retained. This release does not restore the historical reset-to-zero behavior.

## Release scope and measured source

The installer changes exactly five existing source files:

| Path | Measured live Git blob | Measured live SHA256 |
| --- | --- | --- |
| `app/Services/Dashboard/PosServiceTicket.php` | `e95cde8a1bd663d5ad131e8066870ae2b5c220e2` | `875537c790a999b29057fcda1a00a2112843607f3bcd47727b658e26b87b2591` |
| `public/dashboard/js/dining-pos.js` | `49f806a82558a7951e5dfeb344a3a41b5e36dd60` | `a6cd60d392f32fde807b6dca42cfd0add95423f521997dd5ca67c5022c6fd46e` |
| `app/Services/Dashboard/BranchShiftClosing.php` | `6eb5b4dc7b64ea87974a6b39089156b9186f940e` | `21d13d560857700d2904a82af73af798b072f5d56c350c708d85f7723b714bfb` |
| `public/dashboard/js/branch-shifts.js` | `d441027bd27724cf13be5ce4e0e0c06dabc3eb40` | `4c3e7d91162b7a37437cdb14b868484b9710dd22cb16ba55e5da3f0a39e1e0bb` |
| `resources/views/admin/branch_shifts/index.blade.php` | `e043d8ad4a40e4c1e24940cfc3385dc4a902f238` | `658fb3e802aa0b2e5e0f5800ec1f043e931239db02edf7c47da3fa9c227fe4fb` |

These before versions come from measured live source retained by the desktop
integration and the server baseline of 2026-10-19. They are not inferred from the
historical branch. Release ancestry must include
`47b03e8ac95ad565645424d1deb3931b1b60bd66`. Each live payload file must match
its measured before hash or the exact reviewed after version. Unknown source is
preserved and causes refusal before file publication, including independently
edited drawer logic. Known mixed before/after states can finish safely on retry.

The installer makes no schema or migration changes. It changes no stock figure,
ticket data, order state, queue record, printer setting, scheduling service, or
other application source. It does not run a checkout/reset, bootstrap Laravel,
connect to the database, call an HTTP endpoint, send email, or create a test print.

## Server application

Fetch the reviewed branch as `fasakha` with a pinned full commit. Verify that
`FETCH_HEAD` equals the reviewed commit. Extract
`deployment/apply_pos_shift_safety_root.sh` from that exact commit into a
root-owned temporary directory, verify its Git blob against the reviewed blob,
and invoke it as root with that commit:

```bash
bash /private/temporary/apply_pos_shift_safety_root.sh REVIEWED_REVISION
```

The wrapper requires host `srv1836524`, application
`/home/fasakha/public_html`, deployment account `fasakha`, and executable
`/opt/cpanel/ea-php82/root/usr/bin/php`. It serializes the whole invocation with
`/run/fasakhansta-pos-shift-safety.lock`, verifies the installer extracted from
the pinned commit, and runs preflight before applying. A separate `--check`
invocation is optional; it checks without publishing application files.

Preflight parses PHP using the CLI syntax checker and compiles the Blade view
with `Illuminate\View\Compilers\BladeCompiler` loaded through the existing live
`vendor/autoload.php`. It parses the compiled PHP in a disposable private directory
without bootstrapping the application. JavaScript is checked with `node --check`
if Node is available. If Node is absent, the wrapper explicitly reports
`POS_SHIFT_SAFETY_JS_PARSE_CHECK_UNAVAILABLE node_missing=1`; PHP and Blade checks
still must pass. Local regression tests are not run against production orders.

## Backups and retry

The account-owned installer verifies regular files, ownership, symlinks, source
hashes, destination access, and unchanged source metadata before publishing.
Private originals and the receipt are stored outside the web root beneath
`/home/fasakha/pos-shift-safety-backups/`. Original permission modes and group
ownership are retained. No application Git reset or full source replacement is
required.

Each file replacement is atomic. A publication failure rolls back already
published files while they still match the exact candidate. A concurrent edit
is preserved and recorded as `rollback_needs_review` rather than overwritten.
Success reports `POS_SHIFT_SAFETY_RELEASE_COMPLETE` and a receipt path. Repeat
application reports `POS_SHIFT_SAFETY_ALREADY_INSTALLED` without rewriting the
payload. The receipt records that no print command or database bootstrap ran.

## Local installer validation

```bash
python3 -m unittest tests/test_pos_shift_safety_install.py -v
bash -n deployment/apply_pos_shift_safety_root.sh
python3 -m py_compile deployment/apply_pos_shift_safety.py
```

The installer tests use disposable local Git repositories. They cover the full
five-file update, measured-before enforcement, idempotent and mixed-state retry,
private backups, metadata retention, symlink/hardlink refusal, syntax rejection,
concurrent edits, rollback after four published files, and root-wrapper locking.
The real JavaScript parser is exercised against an invalid candidate before any
application source is published.
