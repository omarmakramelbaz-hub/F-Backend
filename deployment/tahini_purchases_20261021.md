# Tahini purchase material for all branches

The owner requested a **طحينة** expense choice for every Fasakhansta branch so stock could be linked to the tahini plate. The deployed expense service uses branch-specific custom labels and a protected registry for material purchases. A fresh custom label cannot be created without a branch. The preceding global-label installer therefore stopped during service validation and did not complete.

The deployed service was identified by its SHA256, `1bf213f622129c9a2402378b2cdfae7f2fb30cb9ce18da924740ca772f8515d9`, and its exact source was reviewed. This release follows its existing purchase registry: **purchase_tahini → طحينة**. The registry exposes the purchase choice to all 32 restaurant branches and supplies the existing expense form's dynamic stock-category map. No 32 custom category copies are needed and the branch-category policy is preserved.

The source patch changes only three anchored sections in the live `ExpenseStockPurchases.php`: the purchase-material constant, the normalized tahini aliases, and the material-list validation message. All other live source bytes remain intact. A staged candidate and metadata are prepared first; the live file is replaced atomically only if its bytes and ownership still match that preparation. Its original bytes, owner and permissions are backed up. Repeating an installed patch does not rewrite the file or change its timestamp.

The stock ingredient is **طحينة**, measured in **kg**. The existing linked-purchase workflow requires kilograms. A matching exact-name kilogram ingredient is reused; duplicate or ambiguous legacy tahini definitions stop for review. No ingredient is renamed or converted.

Installation creates no expense payment, inventory receipt, till entry, movement, opening balance or menu recipe. Future tahini expenses use the existing positive-kilogram validation and transaction-bound receipt logic. Saving a purchase adds its recorded quantity to that branch's stock; an expense rejection/reversal follows the existing exact-receipt reversal path. Historical expenses are never backfilled.

The root wrapper first stages and checks the candidate with the deployed application. It registers the ingredient before publishing the new purchase choice. A fresh final process then loads the actual deployed service and verifies the purchase choice and stock link for all 32 branches. Success is `TAHINI_CATALOG_READY`. If source publication is interrupted after ingredient preparation, a retry reuses the ingredient; no financial records are fabricated.

The earlier `storage/app/tahini-catalog-20261021/before.json` remains byte-for-byte unchanged. A private copy is retained alongside this release's immutable before-records and receipts under `storage/app/tahini-purchases-20261021/` and `/home/fasakha/tahini-purchase-backups/`. Schema changes, application-wide checkouts and configuration replacements are not part of this release.

The plate's EGP 30 price and photograph are unchanged. Its recipe still needs the actual grams of purchased tahini consumed per plate before automatic sale deductions can be configured.
