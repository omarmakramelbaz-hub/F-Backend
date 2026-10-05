# Unified restaurant home overview

`/admin/dashboard` combines application activity, completed sales, branch operations and raw goods. `/admin/dashboard/overview` is its private, no-store JSON endpoint. Both authorize the persisted actor through `TakeawayAccess`; branch query parameters never broaden that actor's restaurant scope. The existing sidebar and business workflows stay in place. GO-only accounts get an explicit no-restaurant state rather than other restaurants' figures.

## Figures and dates

- Cairo calendar presets: today, trailing seven days, month to date and custom periods of up to 366 inclusive days. Comparisons use the immediately preceding equal-length period.
- POS sales are completed `takeaway_orders`, grouped by their frozen business date and channel. Dining/phone drafts and paid service tickets are not counted again as receipts.
- Completed restaurant application orders use their completion clock in UTC, or the inventory sale timestamp in UTC. Older records without either use `orders.updated_at` in the application's legacy timezone. The UI discloses the historical limitation. Application totals follow the existing cart updated-total / quantity, tax, delivery and current service-fee convention; these are not new immutable historical invoices.
- Expenses use currently approved entries by `occurred_on`; pending and voided expenses are excluded. Net shown is explicitly sales minus delivery and approved expenses, before cost of goods and taxes payable, not accounting profit.
- The chart uses hourly points for one day, daily points through 62 days, then monthly points. It has a data table and no external chart dependency. Cancelled orders and application order/customer acquisition counts use creation dates; completed-sale counts use the accounting/completion dates above.
- Live stages and recorded branch opening status ignore the sales date filter. Alerts report known negative/zero inventory, app orders open for 90 minutes, pending expenses, unconfirmed print jobs after five minutes and menu items without recipes. No online-device status, actual printer outage or estimated stock threshold is invented.
- Stock is the current ingredient balance, not a historical stock valuation. Units are never summed together. Coverage is explicit when only some branches have opening balances; an untracked ingredient is not labelled depleted. A negative balance in one branch remains visible even if the combined balance is positive.

## Cash and permissions

The user explicitly requested on 2026-10-05 that the owner may see drawer cash. Only persisted **primary admin user 1** (Fasakhaninja), with no restaurant-bound admin scope, receives `owner_drawer`. `BranchShiftClosing::ownerBalances` independently enforces the same restriction and reuses the existing shift calculation: opening/recorded till movement plus branch-collected app cash, less cash delivery. This is expected book cash, not the cashier's physical cash count, and it is current regardless of the sales period. The owner's dashboard shows the selected-branch total and each branch's balance.

Cashiers and all other accounts receive no drawer amounts. Non-management accounts also receive no aggregate financial sales, expense, chart or comparison values; their overview presents order counts and goods balances. Existing blind shift-closing responses and receipt printing are unchanged.

## UI and release

The layout uses existing branding/sidebar, white panels, orange controls, six main indicators, sales chart, channel ring, operations alerts, searchable 23-item inventory, latest orders, app metrics and branch comparisons. Arabic/English and mobile are supported. Refresh runs once a minute while visible, aborts outdated requests, cleans up on SPA exit and never displays old branch data under a newly selected branch after a failed request.

No new schema is required. The pinned installer validates the new route, service and static assets alongside the existing runtime checks. Enter real stock openings and recipes through the goods module; the overview does not seed business data.
