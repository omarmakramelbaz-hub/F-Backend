# Branch shift closing

The pinned installer applies `2026_10_04_100000_create_branch_shift_closings.php`. The new menu entry is available to accounts authorized for restaurant branches; central administrators select a branch, and branch accounts retain their persisted scope. GO marketplace stores are not included in this restaurant report.

## Counting and printing

The printed closing reports collected dining, takeaway and phone-delivery invoices plus completed restaurant app orders. Each channel shows gross sales; the total deducts delivery fees and approved expenses. The screen shows the cash-count form and closing history only, with no sales or expense totals. Pending expenses are counted in a warning, not deducted. The cashier enters actual **branch cash after separating courier delivery fees**, then closes and prints.

The normal page, history, close/recovery responses and till/expense APIs for every account do not expose the expected drawer balance or the variance. Only the scoped print document for a saved closing contains **الكاش الفعلي**, **النقدية**, and **الفرق — عجز / زيادة / مطابق**. Its financial paper content is hidden in screen media. The thermal layout uses the actual paper width (58 or 80 mm), with fixed table columns, isolated LTR amounts and no fixed 78 mm content that can clip on narrower paper. Financial managers retain movement/settings actions, but receive no till balances either. This is a blind-count interface, not protection against someone inspecting a print document's source.

Closing creates an immutable record. It does **not** withdraw, transfer or reset money in the existing till. Reprinting uses that record and cannot create another closing. The first period starts at midnight in Cairo on the day the screen is first closed; subsequent periods start at the preceding closing. Opening cash for the first period is reconstructed from the POS till and today's cash movements. Subsequent openings carry forward the previous actual count, so a recorded shortage is not repeatedly charged to later shifts. Record any actual handover/withdrawal using the existing authorized cash workflow.

## Cash and sales are separate

- Expected branch cash equals opening cash + POS till movement + newly recognized app cash collected by the branch − POS delivery fees collected in cash.
- Approved cash expenses are already present in till movement and are not subtracted twice. Non-cash expenses affect net sales, but not cash. An explicit expense cancellation reverses its amount in the period when the cancellation is recorded.
- Card, wallet and other electronic tenders do not become drawer cash. App cash is recognized only when the existing order records `payment_type=cash` and `transfer_price_by=vendor`. Delegate collections stay outside the branch drawer. Do not also enter the same app collection as a manual POS cash deposit.
- App sales and later app cash settlement have separate unique source claims, allowing later vendor collection without counting the same sale twice. Existing app totals follow the current cart/tax/service-fee convention and are frozen in the closing snapshot. The legacy app schema has no immutable completion timestamp; initial eligibility therefore uses completed status and `updated_at` since activation. Historical app settlements before activation are not inferred as POS opening cash.

Branches, closing commands and source claims are isolated server-side. Close commands use branch locking, a snapshot review token, the previous closing ID and actor-scoped idempotency keys. If counted data changed, the operator must refresh and recount. After a lost response the UI recovers the original command before retrying it. Payment/application providers are not invoked by closing.

## Verification and rollout

`DashboardBranchShiftTest` covers all four channels, mixed tenders, electronic/delegate exclusion, expense approval/refund, later app collection, carry-forward, fixed historical receipts, branch isolation, blind responses, duplicate commands and changed-data rejection. The hosted workflow includes this suite on SQLite and MySQL. Browser QA uses the actual Laravel controllers and persisted SQLite records to check blind entry, lost-response recovery, a single closing/print invocation, reprinting, cashier till masking and mobile layout. Physical paper output still requires the branch printer and the existing direct-print Chrome launcher.

On the branch device, verify the cash handover/opening convention, a known delivery fee and cash/non-cash expense; count, close and compare the paper with the physical cash. Keep the server update pinned to the reviewed release SHA.
