# Confirm restaurant payments before staff acceptance

The earlier board patch kept NULL-status card/Vodafone checkouts out of Completed, but did not activate a paid checkout. Restaurant orders still depended on the customer's return URL. The retained return path could roll back activation when broadcast/email delivery failed, and unnecessarily recalculated taxes and reset the order's creation time.

This change independently verifies Paymob's transaction, then atomically records the payment receipt and activates an eligible restaurant order from NULL to Pending. It does not accept, dispatch or complete the order. The normal branch actions then display Accept, Reject and Details, with a localized green Paid badge on the card and receipt. Staff acceptance alone moves it into Preparing. Payment and delivery remain separate.

## Confirmation and recovery

- `RestaurantOrderPayment::settle()` uses the existing authenticated `PaymobTransactionInquiry`. Inquiry happens outside database transactions. Exact gateway order/transaction, EGP amount against the saved checkout amount, integration and live/settled flags are checked, and identity/source facts are rechecked under order/payment locks. A known historical success is independently rechecked by its exact stored transaction ID.
- Duplicate receipts preserve delivery state. Cancelled/refunded/inconsistent/competing receipts cannot reopen an order. No wallet credit/debit, inventory posting or payment/refund initiation belongs to activation. Order creation time, saved tax/amount fields and acceptance flags are preserved.
- New intentions include `/api/order-payments/paymob/notify` and `/api/order-payments/paymob/return`. POST callbacks require the configured HMAC plus independent inquiry. GET results are independently verified, never trusted from `success=true`. Old intentions using `/api/pament/callback` share the same service. Existing signed failure evidence remains supported on the return path.
- Notifications/broadcasts/email are best effort after committed activation, so provider failure cannot undo a paid order. Duplicate callbacks do not repeat activation effects.
- `order-payments:reconcile` is read-only by default. `--apply --limit=5` verifies bounded unresolved attempts, rotates through older retries, and recovers only confirmed payments. The central scheduler runs this every minute. Local desktop schedulers and this command refuse device execution. This also provides recovery for pre-existing intentions and when callback delivery is unavailable.

Paymob's current documentation distinguishes server notifications from customer redirects and describes authenticated transaction inquiry: [callbacks](https://developers.paymob.com/paymob-docs/manage-callback/transaction-callbacks), [intentions](https://developers.paymob.com/paymob-docs/intention-apis/create-intention), [inquiry](https://developers.paymob.com/paymob-docs/developers/transaction-inquiry-apis/transaction-inquiry/by-order-id-or-reference). The existing read-only inquiry transport is reused; this change does not modify gateway integration IDs, credentials or charges.

## Guarded publication

The pinned installer publishes only the fourteen product paths in `paymob_order_confirmation.json`. It accepts the reviewed previous fix and original captured source where applicable, rejects unreviewed live drift, backs up original files/metadata, preserves ownership/modes, creates new classes as fasakha and supports idempotent retries/partial installation. It takes the source watcher's lock before publication and leaves the watcher-managed Git branch, employee changes and desktop version configuration untouched.

`apply_paymob_order_confirmation_root.py --git-dir <isolated-bare-repository> --release <pinned-commit>` checks only. `--apply --reconcile` publishes, clears route/view caches as fasakha, then performs a bounded authoritative recovery of existing orders. Recovery results must be assessed from the normalized counts; publication success alone does not prove that an unresolved checkout was paid. No migration is required.

## Validation

- 53 payment/display/callback/reconciliation tests, 630 assertions passed.
- 27 additional board tests, 308 assertions passed, including the real branch Accept POST after verified activation for both payment methods. Two unrelated historical SwiftMailer-injection tests were excluded because the available desktop test runtime uses Symfony Mailer.
- Rendered production card inspected in Chromium at 390 and 320 pixels: Paid, Accept, Reject and Details visible without horizontal overflow.
- PHP syntax and Git whitespace checks passed. Tests used the available Laravel 12 desktop compatibility runtime, an adapter for its anonymous legacy media migration, fake gateway inquiry and fake providers. They are not a live Paymob payment, production Laravel 8 run, physical printer test or new Windows installer verification.
- Publication fixtures: 19 tests, 18 passed; one foreign-UID ownership test skipped because this container maps only UID 0. Backups, reviewed baselines/new files, metadata, repeat application, symlink/drift rejection, rollback, publication order and sanitized reconciliation receipts are covered. Live source application and existing-order recovery still require the pinned root entry on srv1836524.
