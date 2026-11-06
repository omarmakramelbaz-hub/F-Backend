# Physical branch daily email

The root installer adds an independent systemd service and timer on `srv1836524`.
The service boots the primary Laravel application at `/home/fasakha/public_html`.
It does not replace application source, run migrations, close a shift, or update business records.

## Data and delivery

- Branch identities come exclusively from `FasakhanstaDeliveryOrigin::PHYSICAL_BRANCH_IDS`; Cairo retail origins and the GO store namespace are rejected.
- Drawer values use the owner's existing `BranchShiftClosing::ownerBalances` calculation, including cash already assigned to the fulfillment branch. Store rows are never listed separately.
- Inventory uses `stock_ingredients`/`branch_inventory` and any recorded `branch_stock` rows. Recipe-derived menu availability is not counted as extra physical stock. An ingredient without a branch receipt has zero stock, consistent with the dashboard.
- Quantities remain integer micro-units and cash remains integer cents until formatting. Different units and independent SKU/ingredient quantities are never added together.
- The HTML email retains the approved Arabic RTL navy/orange design and has a plaintext alternative.
- Existing Laravel mail settings supply the sender and credentials. The installer accepts the recipient as an argument; credentials are never printed or stored in the repository.
- The timer uses `00:00:00 Africa/Cairo` explicitly and follows Cairo daylight saving changes. The displayed timestamp records the actual snapshot time, including catch-up runs.

## Reliability

The installer verifies every downloaded payload, checks PHP syntax, executes fixture tests,
and renders a real read-only snapshot before publication. `--check` stops before sending
mail or changing the timer. Normal installation starts the first report immediately.

Report snapshots and sent receipts are stored privately outside the web root, under the
application user's state directory. A file lock and per-date sent receipt prevent normal
duplicate execution. Retries reuse the original snapshot; unsent snapshots are retried
before the next day's report. Systemd retries failures every five minutes and catches
up missed calendar runs after downtime. SMTP acceptance and the local sent receipt
cannot be committed atomically: a process or disk failure between them may still cause
a duplicate. The stable daily Message-ID helps identify the same report.

Run fixture tests with PHP 8.2: `php deployment/branch_daily_digest/test.php`.
Inspect the next scheduled send using `systemctl list-timers fasakhansta-daily-report.timer`.
Inspect delivery results using `journalctl -u fasakhansta-daily-report.service -n 12 --no-pager -o cat`.
Pause future sends with `systemctl stop fasakhansta-daily-report.timer fasakhansta-daily-report.service`.
