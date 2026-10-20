# Consolidated low-stock email report

When a new low-stock episode needs notification, the owner receives one email
containing the full inventory report, grouped by branch, with the low items
highlighted. The existing recipient and stock thresholds are retained. Existing
pending item alerts are folded into the consolidated delivery rather than sent
individually. The report is prepared from current stock at dispatch time.

## Scope

The installer updates exactly these three paths:

- `app/Services/Dashboard/BranchStockMinimums.php`
- `app/Mail/BranchStockMinimumAlert.php`
- `resources/views/emails/branch_stock_minimum.blade.php`

It makes no schema changes, runs no migration, invokes no notification command,
and sends no test mail. Other dashboard source and stock figures are untouched.
The application does not need a Git checkout or reset.

The installer accepts only the known source published by
`d7b0f1cb122251adc34fb62f9a0be7a203660ffc`, or the exact payload from the pinned
new commit. A later independent edit to any of the three files causes a refusal
before publishing; inspect and incorporate that edit before retrying. The known
before blobs also match the measured server source inventory:

| Path | Known Git blob |
| --- | --- |
| Service | `ebd34a6608111bcfc62351cedf1a70ee66f5324a` |
| Mailable | `9dd1c031d0fd8d5a7a41bb18c42a4e42a5ed6483` |
| Email view | `1f014aaa284ff37a6d270d1688f1d00ce383a20d` |

## Run on the production server

Use the full reviewed release commit in place of `REVIEWED_REVISION` and fetch
`codex/stock-minimum-summary-email-20261020` as `fasakha` first. Extract the root
wrapper from that exact commit into a private temporary directory, verify its
Git blob against the expected reviewed blob, and run as root:

```bash
bash /private/temporary/apply_stock_summary_email_root.sh REVIEWED_REVISION --check
bash /private/temporary/apply_stock_summary_email_root.sh REVIEWED_REVISION
```

The wrapper requires host `srv1836524`, root `/home/fasakha/public_html`, account
`fasakha`, and `/opt/cpanel/ea-php82/root/usr/bin/php`. It first checks file
compatibility and parses the candidate PHP and compiled Blade in a disposable
directory. This check neither bootstraps Laravel nor contacts the database.

A root-level file lock serializes the entire wrapper, including preflight,
timer changes, file publication, and the timer restart in its exit handler.
A simultaneous installer exits with `STOCK_SUMMARY_INSTALLER_ALREADY_RUNNING`
before reading or changing scheduler state.

For application it requires the existing dedicated stock email timer and service,
pauses the timer if active, and waits for a running service to finish rather than
interrupting SMTP. A service that does not finish within 190 seconds blocks the
update. The initial active/inactive timer state is retained. The wrapper never
enables a disabled timer and never starts the mail service directly. An active
timer resumes its existing scheduled checks after the update.

The source also registers `branch-stock:notify-minimum` in Laravel's scheduler.
Registration alone does not show that the server runs `artisan schedule:run` from
cron or another service. This wrapper coordinates the observed dedicated systemd
worker and assumes no independent invocation of this notification command during
the brief file replacement. It does not stop or alter the general Laravel
scheduler. If an independent invocation is observed on the live server, coordinate
that specific notification job for deployment before applying; do not stop broad
scheduling based only on the source registration.

## Recovery and verification

Success prints `STOCK_SUMMARY_RELEASE_COMPLETE` and an installed receipt path.
Private originals and before/after hashes are stored beneath
`/home/fasakha/stock-summary-email-backups/`, outside the public web root. A repeat
reports `STOCK_SUMMARY_ALREADY_INSTALLED` without rewriting the three files.
The receipt marks whether installation completed or was rolled back.

If publication fails, already published files are restored only while their
content still matches the installed payload. An independent edit discovered
during rollback is preserved and reported as `rollback_needs_review`. Do not
copy entire historical application trees over the live application for recovery.

The installer performs no live email delivery test. Normal scheduled delivery
uses the existing owner mailbox and configured transport.

## Local checks

```bash
python3 -m unittest tests/test_stock_summary_email_install.py -v
bash -n deployment/apply_stock_summary_email_root.sh
python3 -m py_compile deployment/apply_stock_summary_email.py
```

The tests use disposable repositories and source files only. They cover
preflight, exact scope, backups, metadata retention, repeat/mixed-state retries,
unknown content, symlinks/hardlinks, concurrent edits, parse rejection, rollback,
and absence of any mail command from the root wrapper.
