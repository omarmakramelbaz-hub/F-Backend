# Raw goods and menu recipes

`/admin/branch-stock` now has **goods/balances** and **menu recipes** tabs. Goods are independent of the restaurant menu. The additive ingredient migration seeds exactly the owner's 23 requested goods: four feseekh grades, three renga types, local sardines, seven packaged cans, onion/pepper/tomato/lemon, bread, chips, Pepsi and water. Fish/vegetables use kilograms; cans/bread/snacks/drinks use pieces. Incoming pieces must be whole. Gram quantities are available in recipes and convert to integer millionths of a kilogram. No ingredients, prices or recipes are inferred from dish names.

## Setup and authorization

Branch accounts can receive goods and view their recipes/balances. Persisted administrative and restaurant-owner accounts may edit recipes within their existing branch scope. Central administrators choose the branch. Each menu SKU is linked to its ingredients and exact quantities per one sold unit or kilogram. This also covers direct sales (e.g. a Pepsi SKU mapped to one Pepsi stock item). Each feature/size has an explicit recipe; a quarter meal can consume 250 g of fish and one bread without incorrectly quartering the bread. Cleaning/packing price variants reuse the recipe for the same size. All amounts must be entered by management; no guessed production recipes are seeded.

Once a SKU has any recipe, ordering an unconfigured size requires that size's recipe to be entered. A SKU with no recipe remains sellable during setup, visibly labelled **الوصفة غير مسجلة**, and its completed sale is recorded in the unmatched-sales review instead of silently inventing a stock movement. The goods page shows the pending menu count and recent unmatched sales. Management must finish recipes and reconcile these sales for complete stock coverage.

## Sales and audit

Paid takeaway, dining and phone sales deduct the sum of their ingredients once, inside the existing sale transaction. Drafts, kitchen sends and payment-bill printing do not deduct. New POS quotes freeze recipe ID/revision/components; persisted dining/phone bills keep that snapshot even if the live recipe changes before collection. Edited/repriced bills obtain a new recipe snapshot. Pre-upgrade unpaid bills without a snapshot resolve the current recipe once on settlement. Application orders resolve recipes at completion in the shared completion transaction.

Ingredient movements aggregate shared components across all lines/sizes before rounding to the nearest micro-unit. Negative stock is retained and highlighted rather than blocking trading. Menu badges show estimated producible base units from the least available component; they are not reservations. All ingredient changes roll back if payment settlement fails. The immutable sale snapshot records each recipe/revision, deduction and any unmapped SKU. A unique branch/source/order record and movement-source uniqueness protect retries. Recipe writes use branch locks, optimistic revisions and the shared actor/UUID recovery ledger.

Receipts have supplier/notes and the last 30 movements. Purchase payments are entered separately in branch expenses; receiving quantity does not move cash.

## Existing data and rollout

Legacy `branch_stock` and `branch_stock_movements` are retained without conversion or deletion. Nonzero prior SKU balances are visible in **أرصدة النظام السابق للمراجعة**. Their names cannot safely identify raw ingredients, so they do not become ingredient balances or continue separate SKU deductions. Reconcile physically and enter verified raw opening balances in the new goods list. Existing completed invoices are never backfilled. Before migration, the old SKU service remains compatible; after migration, the new service is used throughout.

The pinned dashboard installer applies `2026_10_04_210000_create_branch_inventory_recipes.php`, compiles views and checks all five new tables/routes/assets. Reload browser tabs after deployment. Reverting code after receiving new ingredients requires accounting reconciliation; the prior code does not understand the new ingredient ledger.
