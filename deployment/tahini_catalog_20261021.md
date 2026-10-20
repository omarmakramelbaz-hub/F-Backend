# Shared tahini expense category and stock ingredient

Owner request, 21 October 2026: add a tahini expense choice for every Fasakhansta branch so the tahini plate can be linked to inventory.

Expense labels and recipe ingredients are separate shared catalogs. This release adds or reuses one active expense category named **طحينة** and one inventory ingredient with the same name. The shared lists make both choices available across all 32 reviewed restaurant branches; no per-branch copies are necessary. Existing Arabic spelling variants are matched without renaming historical records. Ambiguous duplicates or an existing ingredient with a different unit stop the operation.

The caller explicitly supplies `--ingredient-unit kg` or `--ingredient-unit piece`. Kilograms represent bulk tahini, with gram quantities available in recipes. Pieces represent ready-made portions. The unit must follow the actual purchasing and stock practice; the installer never changes an existing ingredient's unit. No per-plate quantity is inferred from the menu price.

Adding the choices does not record a purchase or expense payment, change the till, receive inventory, create opening balances, create inventory movements, or save recipes. Missing branch balances retain the existing zero-until-receipt behavior. The tahini plate's name, EGP 30 price and image remain unchanged. A recipe can subsequently select this ingredient after management supplies the quantity consumed per plate.

The staged runner boots the existing Laravel application under `fasakha`. Expense category creation/reactivation goes through the deployed `ExpenseCategories::save()` service as persisted primary owner 1, using a fixed audited request UUID. The ingredient and category changes share a transaction with the owner lock. Existing matching active categories and ingredients are reused without timestamp changes. Private before-records and completion receipts allow retries to retain the original backup.

The root wrapper extracts only the standalone runner from an exact release commit. It does not replace application files, run migrations or modify configuration. It previews the schema, branch scope, owner authorization, catalog identities and unit before applying. Backups are kept in `/home/fasakha/tahini-catalog-backups/`; private release receipts are under `storage/app/tahini-catalog-20261021/`.

Run `deployment/apply_tahini_catalog_root.sh --release <reviewed SHA> --ingredient-unit kg --check`, then use `--apply` for activation. Success is `TAHINI_CATALOG_READY`. Refresh the expense and goods/recipe pages afterward. Actual server activation is performed by the operator; local verification does not imply production data has changed.
