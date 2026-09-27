# GO professional quotations

New professional work uses the quotation marketplace. Delivery and product
orders remain separate flows.

1. The customer describes the work, adds optional photos, pins its location and
   submits without selecting a professional.
2. The server invites approved, online professionals of the matching profession
   within their work radius, excluding professionals already assigned to a job.
3. Each invited professional submits a final price, scope, materials inclusion,
   expected arrival and duration. Submitting a quote does not debit a wallet.
4. The customer compares quotes and explicitly accepts one with an enabled
   payment method. Posting a quote is the professional's agreement; customer
   acceptance completes the agreement.
5. Acceptance locks the job and wallets, validates the professional's individual
   `users.delegate_fees` percentage, deducts it from their balance and credits
   `settings.general.app_balance` in the same transaction. Retries cannot debit
   the commission twice. An insufficient balance cannot create a partial booking.
6. Rejecting a quote does not charge commission. Other quotes stay available and
   the server immediately invites the next eligible batch without re-inviting
   rejected professionals. Timed batches continue through `go-services:dispatch`.
7. The configured search window is currently 60 minutes, with batches of 5 every
   120 seconds, up to 100 recipients. Quotes last at most 30 minutes. Expiry is
   explicit; no professional is selected automatically.
8. Cash is paid to the professional. App-wallet payments are held until customer
   completion confirmation, then paid out gross because commission was already
   deducted at agreement. Gateway methods appear only when individually enabled
   and configured; client navigation never confirms payment.

## September 27 readiness corrections

- Register the dispatcher once per minute with overlap protection in the real
  application scheduler. Previously its command existed without a schedule.
- Validate the scheduled task before release activation.
- Go Customer no longer falls back to creating directly assigned professional
  requests when the quotation schema is unavailable. Order history is preserved.
- Go Partner refreshes server capabilities instead of caching an unavailable
  marketplace until app restart. Existing requests remain accessible separately.

The public capabilities endpoint reported `schema_ready=false` and
`enabled=false` on September 27, 2026. These changes are prepared on GitHub;
they do not install production tables, enable financial actions or alter gateway
configuration. Backend main auto-deploys, so this backend change remains in a PR
until a server release is authorized.

An authorized server operator must use `deployment/launch_go_services.sh` after
merging the reviewed backend release. Its checks cover the private backup,
transactional wallets, isolated controller tests, the additive marketplace
migration and the existing server scheduler. It does not enable gateway methods.
Do not use the deploy-only SSH identity to bypass operator-console requirements.

## Verification

- 1,486 money, distance and payment-signature assertions.
- 50 isolated SQLite marketplace assertions, including timed waves, preserving
  other quotes after rejection, individual commissions and retry protection.
- MySQL CI additionally checks concurrent acceptance with one winner.
- Real Laravel schedule inspected locally: exactly one minute task with overlap
  protection; no scheduled command executed.
- Customer and Partner contract/widget checks cover unavailable-state recovery,
  quote rejection, agreement confirmation, stale responses and payment state.
