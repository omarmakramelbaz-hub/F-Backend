# Purchasing, manufacturing and accounting

This extends the `/erp` foundation on the same development branch. It does not deploy or merge to `main`, mutate existing application orders, move bank funds, or change GO/payment integrations.

## Operational cycle

1. Add a supplier and record their invoice number/date, receiving warehouse and up to 30 distinct item lines. Review the calculated invoice total and confirm physical receipt of every line.
2. The complete invoice posts atomically: inventory receipt documents/entries, purchase lines and balanced journals debit the relevant inventory account and credit that supplier's payable. Duplicate supplier invoice numbers are rejected. A failed line rolls back every line and every accounting entry. Supplier payments are allocated to the supplier account, not individual invoices.
3. Create an immutable recipe version with a finished output item, expected yield and raw/packaging/other item inputs. The output cannot be an input. There is no recursive explosion of sub-recipes.
4. Post a batch with the warehouse, number of recipes and actual output. Consumption is the recipe quantity multiplied by the factor. Fractional pieces and quantities requiring finer precision than 0.001 are rejected. Any shortage rolls back the entire batch.
5. Consumed quantities use the same moving-average valuation as other stock issues. Their full cost transfers to the finished output, including normal yield differences. Quantities and values are recorded in immutable stock documents; the batch shows expected versus actual output. This is a one-step actual-cost material conversion, not multi-stage work in progress. Labor, overhead, abnormal-loss allocation, expiry tracking and lot-level recalls are not modeled.
6. Treasury records actual owner funding, operating expenses, supplier part/full payments and full payment of approved payroll statements. It never sends payments to a bank or wallet. Supplier payments cannot exceed recorded debt; expenses/payments cannot overdraw cash; payroll cannot be paid twice.

## Accounting start and chart

The second migration creates the chart, posting tables and an **uninitialized** accounting state. No balances are invented and no production data are seeded. Before stock or payroll finalization resumes, the owner must open **الحسابات والكاش**, reconcile the values shown and initialize once:

- Debit each stock category with its existing ERP balance, preserving branch dimensions.
- Credit approved historical ERP payroll statements as unpaid liabilities. The owner must verify that they are actually unpaid; cash paid outside ERP before this release must be reconciled before initialization.
- Debit central cash with the actual amount entered by the owner; default input is zero.
- Balance the opening entry to the opening-balance clearing account. Historical inventory/payroll is not labeled current-period profit/expense.

New movements create journals in the same database transaction as the operational documents. Entries are posted on the current Cairo calendar date. Supplier invoice dates and payroll months remain document references; they do not backdate the ledger. Every journal's debits equal its credits, uses integer piasters and has a unique source. There is no generic journal-edit HTTP endpoint.

| Code | Account / use |
| --- | --- |
| 1100 | Central cash; no separate bank or branch cash ledgers yet |
| 1200 / 1210 / 1220 | Raw / finished / packaging inventory |
| 2100 | Supplier payable, with supplier and receiving-branch dimensions |
| 2190 | Non-invoice stock receipts pending financial reconciliation |
| 2195 | Manual advance repayments pending reconciliation to the external advance record |
| 2200 | Approved unpaid payroll |
| 3100 | Opening-balance clearing |
| 3200 | Owner cash funding |
| 4900 | Count increases |
| 5100 / 5300 | Waste / count shortages |
| 5200 | Payroll expense after bonuses/deductions, before advance repayment |
| 5400 | Operating expenses |

Transfers preserve inventory value and branch attribution; production transfers value between inventory categories. Approved payroll debits wage expense, credits net payable and credits the advance-repayment clearing account where applicable. This clearing account is explicitly **not** a complete employee-loan subledger. Payroll payment requires both finance and payroll permissions. Cash remains centrally tracked; the trial balance is global, not a branch balance sheet.

The screen reports posted balances and a trial balance, **not comprehensive profit**. Sales, taxes, bank reconciliation, financial-period closing, manual adjustment/reversal workflows and statutory financial statements are not implemented. Direct receipts and manual advance repayments need subsequent reconciliation. Do not use ledger totals as comprehensive accounts before those flows are connected.

## Permissions

- `purchasing.manage`: suppliers, purchase history/debt; receipt additionally requires `inventory.manage`.
- `production.manage`: view recipes and post batches in authorized warehouses; additionally requires `inventory.manage`. Recipe creation is central-role only; managers may execute recipes in their branch.
- `finance.manage`: central treasury and ledger. Initialization remains owner-only, regardless of financial capability.
- New administrative admin accounts default to all operational capabilities; new branch manager accounts include production capability. **Existing accounts retain their saved permissions**. The owner must explicitly enable the new capabilities where appropriate; role changes still reset the form to the chosen role's defaults.
- A branch manager cannot obtain purchasing, finance, payroll or branch administration through forged capability arrays. Financial/purchase audit events are filtered by capability.

All financial/stock writers acquire one short-lived ledger-state row lock before other locks. This deliberately serializes these operations to keep initialization snapshots, debt/cash checks and multi-line transactions consistent. It trades peak throughput for correctness in this first operational release; row-lock concurrency tests cover stock duplication, competing withdrawals, supplier overpayment and cash overdraft on MySQL.

## Activation for a future approved release

The existing main workflow auto-deploys and migrates. Keep this draft PR unmerged until a production release is explicitly requested and prepared. On a staging copy with the real Laravel dependencies/schema:

```sh
php artisan migrate --path=database/migrations/2026_09_30_180000_create_erp_foundation.php --force
php artisan migrate --path=database/migrations/2026_09_30_193000_create_erp_operations.php --force
```

Enable the ERP feature and verify the owner ID as described in `ERP_FOUNDATION.md`. Clear application configuration/view caches with the normal deployment process. Enroll branches, create items and reconcile opening data. Initialize the ledger using the owner account, then grant the administrative admin the new operational permissions. Test one purchase, one production batch, one supplier payment and payroll payment in staging. No credentials, suppliers, branch registrations or production business records are seeded by this code.

Both migrations preserve posted history; rollback refuses to drop operational tables containing business data. Disable ERP access through its feature flag when required and use a reviewed backup/recovery plan for posted history.

## Verification

`tests/erp_runtime` now shares an isolated fixture base and runs the foundation plus operations suites. The tests cover money/quantity conservation, atomic failure, repeat submissions, duplicate invoice/payroll protection, effective permissions, initialization, and real competing MySQL writers. Chrome CI verifies eleven desktop and nine mobile views plus stock, role, invoice-row, total-preview and cash-form controls. Screenshots are retained as the `erp-ui-review` artifact. Fixtures are not customer or production data.

## Next connection

POS/KDS sales and completed app-order consumption still require explicit menu-to-item/recipe mappings, cancellation/refund rules, and prevention of double consumption between prepared inventory and direct recipe consumption. This release does not assume those mappings or deduct live application orders automatically.
