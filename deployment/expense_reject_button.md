# Pending expense reject button

Adds a red **رفض المصروف / Reject expense** button beside the pending status and
the existing **اعتماد وصرف / Approve and pay** action. It uses the existing reject
review flow, permissions, required reason, and server endpoint.

Only these four frontend files are patched:

- `public/dashboard/js/branch-expenses.js`
- `public/dashboard/css/branch-expenses.css`
- `resources/lang/ar/expenses.php`
- `resources/lang/en/expenses.php`

The installer changes small, unique anchors in each current live file. It does
not check out a commit, replace whole files from the release, boot Laravel,
run migrations, or change database records. Any unmatched or duplicate anchor
fails before application files are written. Already applied changes are safe
to rerun, including a partially applied release whose remaining anchors match.

The root wrapper requires host `srv1836524`, account `fasakha`, application root
`/home/fasakha/public_html`, and the cPanel PHP 8.2 executable. Supply the full
release commit already fetched into that repository. It verifies the installer
against the Python blob in that pinned commit and runs it as `fasakha`.

```bash
bash deployment/apply_expense_reject_button_root.sh FULL_RELEASE_COMMIT --check
bash deployment/apply_expense_reject_button_root.sh FULL_RELEASE_COMMIT
```

The `--check` mode builds and syntax checks candidates without changing the four
application files or creating a backup. PHP language files are parsed with the
supplied PHP executable; JavaScript is parsed when Node is installed. The normal
run takes an exclusive lock, preflights all four files, then stores private,
timestamped originals with checksums and metadata under
`/home/fasakha/expense-reject-button-backups/`. Writes are atomic and preserve
file mode and ownership. An error during the writes restores the written files
from their in-memory originals; an unrelated concurrent edit is retained and
reported instead of being overwritten during rollback.

Expected success output: `EXPENSE_REJECT_BUTTON_CHECK_OK`, then
`EXPENSE_REJECT_BUTTON_APPLIED`. Repeated application reports
`EXPENSE_REJECT_BUTTON_ALREADY_APPLIED`. Refresh the expenses screen after
deployment. The server remains responsible for permission and status checks;
approved expenses do not receive this button.
