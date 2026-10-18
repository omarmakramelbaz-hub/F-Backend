# Branch stock minimum alerts

Each raw stock item has an optional minimum for each Fasakhansta branch. The stock
balance page exposes a compact editor to the Owner and central Admin accounts.
Branch staff can see the minimum and low-stock state but cannot change it.

- Minimums use the item's existing unit: kilograms (up to three decimals) or whole pieces.
- An empty minimum disables its alert. Zero enables an alert at zero or a negative balance.
- A balance equal to or below the configured minimum is highlighted in red.
- The all-branch view keeps every minimum attached to the branch named on its row.
- No initial minimum values are guessed or applied to existing stock.

Stock movements record a low-stock episode in the same transaction as the balance.
An outbox entry is created once per low-stock episode. Recovery above the minimum
cancels an unsent entry and allows a new entry on a future drop. Changing a minimum
while the item stays low does not create repeated notices. Failed sales and
receipts roll back their pending notices with their stock movements.

`branch-stock:notify-minimum` reconciles current balances and dispatches pending
email to the canonical Owner account (user 1, active central admin) using its
current saved email address. Messages contain the branch, stock item, current
balance, minimum, unit, Cairo detection time, and a branch stock page link.
No SMTP call runs inside a sale, receipt, or threshold-save transaction. Delivery
failures stay pending and retry with backoff; a missing Owner email or a log/array
mailer is treated as a pending delivery failure. Generic SMTP cannot guarantee
exactly-once delivery across a process crash after provider acceptance.

The normal Laravel scheduler runs the command every minute. The guarded root
installer also enables a dedicated timer so alerts do not depend on an open
dashboard or the presence of a general scheduler cron. Database claims protect
against concurrent runners. Desktop local instances do not dispatch mail.
An idle visible stock page refreshes its data every 30 seconds, preserving forms
and minimum edits in progress.

## Installation

Use the pinned release's `deployment/apply_branch_stock_minimum_root.sh` with
the full release commit as its first argument. Add `--check` for preflight.
The target is `/home/fasakha/public_html` on `srv1836524`; PHP 8.2 and application
operations run as `fasakha`.

The installer applies only this feature's reviewed hunks to shared live files.
Unrelated live changes are retained; an overlapping edit stops the update for
review. New files must be absent or byte-identical. It backs up originals outside
the public web directory, compiles staged Blade templates, checks PHP syntax, and
runs only `2026_10_18_230000_create_branch_stock_minimum_alerts.php`.
The additive tables preserve existing balances, sales, expenses, and recipes.
The installer does not send a test email or dispatch pending notifications.

After installation, open **إضافة بضاعة → البضاعة والأرصدة** and use **تحديد** in
the **الحد الأدنى** column. Save the required values for each branch. Delivery
requires a valid saved Owner email and a working delivery mail transport.

## Validation

The isolated database suite covers boundary equality, branch isolation, units,
permissions, revision/idempotency conflicts, recovery, retry, and transaction
rollback. Browser DOM checks cover red rows, per-branch editing, zero and disable,
failed saves, and background refresh. Installer tests cover unrelated live drift,
overlaps, targeted schema installation, reruns, and code rollback.
