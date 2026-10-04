# Branch goods receipts and stock

`/admin/branch-stock` appears immediately after branch expenses. The dropdown lists the selected restaurant branch's existing menu SKUs, with a search and 100-item pages. Branch staff see their authorized branch; central administrative accounts and the owner can choose among their authorized restaurant branches. GO inventory is unchanged.

First receipt starts tracking that branch/SKU in kilograms (`kg`) or whole pieces (`piece`). Further receipts must use the same base unit. Incoming kilograms accept three decimal places; balances use integer millionths so quarter and half portions remain exact. The POS menu displays the current tracked balance and selects its corresponding sale unit. Untracked items retain existing sales behavior.

Receipts are additive and recorded in a movement history. They do not move money: record any purchase payment separately under expenses. There is no automatic migration of historical sales or opening stock.

Completed takeaway, dining and phone sales deduct once inside the sale transaction. Drafts, kitchen sends and bill printing do not deduct. Restaurant application orders deduct once in the shared completion transaction, including scheduled completion. Kilogram half/quarter options consume the matching fraction; piece items consume whole quantities. App cart quantities are the source of units; monetary price adjustments do not imply a quantity change. Negative balances are permitted and visible for reconciliation, without disabling the menu item.

Receipts use the shared actor/branch UUID command ledger; the browser freezes the payload before sending and recovers it after a lost response. A unique movement source plus branch/stock locks prevents replay duplication. Failed sale settlement rolls back its stock movement. Access checks use persisted branch ownership, and all stock lists and receipts are branch scoped.

The additive `2026_10_04_190000_create_branch_stock.php` migration is included in `apply_order_board.sh`; runtime checks verify both tables and the three routes. Deploy through the pinned dashboard root installer. Browser reload is required for the new menu badges and page assets.
