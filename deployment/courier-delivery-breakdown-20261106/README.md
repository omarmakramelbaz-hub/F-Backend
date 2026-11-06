# Courier collection receipt: separate delivery charges

The combined phone-order collection receipt used to show only each order's final total.
It now prints the order value excluding delivery, the saved delivery charge, and the
unchanged final total in separate columns. The summary prints separate order and
delivery totals followed by the collected grand total.

The breakdown reads the original settled `takeaway_orders` receipts, so it also works
for batches collected before this change. It uses their saved `delivery_cents`,
including manually overridden fees and free delivery. It does not recalculate current
tariffs, use editable tickets, create a courier expense, change the till, or collect a
payment again. An unavailable or inconsistent original is shown as unknown, preserving
the original collection amount.

The existing branch authorization runs before reading the linked receipts. Every
source must match the batch branch, phone channel, ticket ID and saved total.

## Scoped deployment

`RUN_ROOT.sh COMMIT --check` stages and renders the candidate without installing it.
`RUN_ROOT.sh COMMIT --apply` backs up and installs only five files under
`/home/fasakha/public_html` on `srv1836524`, clears Blade views, and reruns verification.
The service edit patches only `PhoneDeliveryBoard::receipt`, preserving other server
changes. Unknown source conflicts stop before installation. A failed verification rolls
back the installed files. No database migration or data write is performed.

## Validation

- `tests/phone_batch_runtime/render.php ROOT AUTOLOAD OUTPUT [OVERLAY]`: seven receipt
  fixtures in Arabic and English; exact money, free/manual delivery, original totals,
  missing originals, escaped names and up to 50 orders. Requires Laravel 8 dependencies.
- `tests/phone_batch_runtime/database.php ROOT AUTOLOAD`: in-memory SQLite integration,
  with query-only reprints, branch access, linked receipt isolation and repeat printing.
- `python -m unittest discover -s tests -p test_courier_receipt_deployment.py`: scoped
  patching, rollback, idempotency, conflict handling and candidate permissions.
- Rendered print-layout inspection at 72mm printable width, including large amounts.
