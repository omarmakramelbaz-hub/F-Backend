# GO service marketplace — staged rollout

This module is isolated from legacy delivery and direct service requests. It is OFF by default. This commit does not deploy a server or alter legacy Paymob settings.

## Business contract
Customer creates a job without choosing a partner. Approved online professionals of the same profession receive batches of five, ordered by geographic distance from their approved registration location, always within their own 5/10/15/20 km radius. A new wave is scheduled every 120 seconds, or immediately after a new rejection/skip. Previously invited partners are not re-invited. Other live quotes survive a rejection. Search lasts 60 minutes with a cap of 100 recipients; an offer is valid for 30 minutes or the remaining search period, whichever is shorter. All these defaults are in config/go_services.php. This first version reserves a professional for one active job, including a scheduled booking; it does not implement a scheduling calendar.

Quotes include final price, exact scope, whether materials are included, arrival minutes and work duration. No commission at quotation. Customer acceptance locks the job, chosen offer and wallet owners, checks current eligibility, and accepts exactly one offer. Commission uses users.delegate_fees (the existing dashboard percentage field), not shipping_min_price. A changed commission invalidates the existing quote rather than silently changing the partner's terms. Amounts and commission are snapshotted in cents/basis points. A unique ledger key prevents charging twice. GeneralSettings general.app_balance is credited in the same transaction, using a locked settings row. The uncached default database settings repository is required; cached/custom settings must be adapted before enabling.

Cash: customer pays the professional directly; the platform takes only the already-debited commission. App wallet/electronic: hold the gross job amount until customer completion, then credit the professional with the gross amount because commission was already charged. A second net payout would double-charge commission and is deliberately avoided.

Before work begins, cancellation returns commission exactly once; app-wallet holds are returned atomically. Card/e-wallet captures requiring refunds are marked refund_pending/refund_due for manual original-method refund and reconciliation. The implementation does NOT claim or execute an automatic gateway refund. Disputes block automatic payout and require support resolution. Do not enable production until operational refund/dispute procedures are staffed and tested. Never delete ledger rows to resolve a dispute.

## Deployment checklist (not executed by this change)
1. Back up the database; merge reviewed backend/app branches in staging. Run this new migration only after review.
2. Confirm InnoDB, accepted GO customer scope `go`, GO professional scope `go_partner`, approval metadata, valid registration coordinates/radii, and individual delegate_fees for every professional. Set SETTINGS_CACHE_ENABLED=false with the default database settings repository.
3. Verify `php artisan route:list --path=go-services` and `php artisan schedule:list`; the provider appends go-services:dispatch once per minute. Keep existing cron `schedule:run`. Start/verify existing notification infrastructure.
4. Set GO_SERVICES_ENABLED=true only in staging initially. Feature capabilities allow apps to retain their legacy screens on an un-upgraded backend.
5. Run cash and app-wallet end-to-end tests and simultaneous acceptance tests on actual deployment MySQL. Test account isolation, offline partners, expired quotes, insufficient funds, retries, cancellation/refunds and job resumption after app restart.
6. Electronic methods remain hidden unless GO_SERVICES_PAYMOB_ENABLED=true, PAYMOB_SECRET_KEY/PAYMOB_PUBLIC_KEY/PAYMOB_HMAC_SECRET and each dedicated GO_SERVICES_PAYMOB_*_ID are configured. The app shows only configured methods. This does not activate Apple Pay or Google Pay on the merchant account.
7. Configure the processed transaction POST callback to `/api/go-services/paymob/webhook` and hosted-checkout return to `/api/go-services/payment-return`; validate intention_order_id and the provider callback contract in the merchant sandbox. GO_SERVICES_PAYMOB_LIVE must match the dedicated integrations. Verify HMAC, amount, currency, integration, order binding, authorization-vs-capture, failures, repeated callbacks and late/duplicate captures. Never trust the browser redirect as payment confirmation. No actual gateway calls were made by the test suite.
8. Test mobile/web notification navigation and private photo display. Event payload uses go_service_job_id, distinct from legacy partner_service_request_id. Outbox delivery is at least once; clients should use event_id to deduplicate alerts.

## Test commands
`php tests/go_services_unit.php` checks exact money conversion, rounding, distance and the documented Paymob HMAC field order. The isolated tests/go_services_runtime package runs the real migration and Marketplace/Payments services on SQLite and MySQL; the GitHub workflow also races two MySQL acceptance requests. It does not boot the full legacy app, validate Flutter screens, contact a payment gateway, or replace staging end-to-end tests.

## Support queries (read-only; restrict to authorized staff)
- Open disputes: go_service_jobs.status = disputed.
- Original-method refund queue: go_service_jobs.payment_status = refund_pending; join go_service_payments and go_service_payment_receipts by job_id.
- Late/duplicate captures: go_service_payment_receipts.status = refund_due.
- Gateway reversals: go_service_payment_receipts.status = reversal_review or go_service_jobs.payment_status = review.
- Financial audit: go_service_ledger joined to wallets by wallet_id. Job IDs are separate from legacy order IDs and must not be put into wallets.order_id.

Disabling GO_SERVICES_ENABLED stops new creation/quotes/acceptance; existing payments, cancellations, expiry and fulfillment remain available. A migration rollback refuses to destroy existing financial history.
