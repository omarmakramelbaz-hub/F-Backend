# Delivery handoff and combined collection

Apply `2026_10_06_130000_create_phone_delivery_batches.php` through the pinned root installer after branch operations. The additive tables record company handoff, an immutable combined receipt and its constituent paid orders. Existing invoices and balances are not backfilled.

The saved-delivery view has three independent, paginated columns (20 orders each):

- **Preparing:** unpaid new/preparing orders. Choosing an active company for the same branch records its name/contact snapshot and moves the order to the courier column. This is an operational handoff: issued invoice customer, cart, quote, original company and bill-lock fields stay byte-for-byte unchanged. Company counts/filtering use the handoff when present. It does not send another preparation print.
- **With courier:** unpaid out-for-delivery orders, including legacy finished-but-uncollected orders. Check multiple orders from the same company for one courier. Selection is cleared when switching courier pages/branches, and changed selected orders are deselected on refresh. An optional courier name is retained on the receipt. A legacy order without any company remains collectible individually through Details.
- **Finished orders:** collected orders and clearly marked cancelled orders. Batch members expose their combined receipt for read-only reprinting.

Finish opens a review with each order's saved total, the combined total, courier name, payment method and actual-collection confirmation. The server rechecks branch authorization, stage, revision, quote hash, company consistency and tender/total. Under the branch lock, all constituent shared POS settlements, stock deductions, till movements, membership rows and the batch snapshot commit together. Failure of any settlement rolls all of them back. No app wallet/gateway/carrier transfer is initiated. Gross order amounts are collected; existing shift reconciliation separately excludes delivery fees from branch proceeds.

An actor/branch command UUID recovers the immutable batch after a lost response. Reusing it with different input fails. A new key cannot collect a paid ticket; membership is unique per ticket. Print GET only reads a scoped saved snapshot and never collects. Cash overpayment/change are shown once on the combined receipt; each constituent sale records its exact total so change cannot inflate the till. Inactive companies can still have their already-assigned orders collected.

After confirmed batch collection, the client calls the shared background printer once with an 80 mm receipt showing order numbers, individual values, total, company/courier, cashier and payment details. A printer failure does not reverse collection; use the combined-receipt button in Finished orders. Silent physical printing still uses the existing configured branch Chrome launcher/default printer. Browser invocation tests do not certify physical paper output.

Regression coverage includes immutable issued invoices, branch/company isolation, stale/tampered/mixed/duplicate selections, atomic rollback after a later checkout failure, exact replay/recovery, company counts, pagination, receipt snapshot/reprint and no extra till movement. Actual-controller browser QA covers lost handoff/batch replies, no second POST on recovery, multi-selection and print/reprint.
