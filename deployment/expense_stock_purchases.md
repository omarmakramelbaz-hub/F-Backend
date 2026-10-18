# Expense purchases and ingredient stock

The release adds six shared expense categories for pepper, tomato, lemon, onion, oil, and vinegar. These categories require a positive kilogram quantity. Saving one of these expenses adds the same quantity to that branch's ingredient balance in the expense transaction, with an immutable link to the receipt movement. Approval does not add it again. Rejecting or voiding the expense creates one compensating stock movement. Old expense records and balances are not backfilled. The owner-managed category vocabulary is shared by all branches, including Mahalla.

The new migration creates only `expense_inventory_receipts` and adds missing kilogram ingredient identities. Existing ingredient identities must be unambiguous and use kilograms. The receipt table and new ingredient identities remain on a migration rollback because audit rows and recipes may reference them. Desktop schema/bootstrap exports include the receipt table.

## Deployment

Fetch the pinned release branch without checking it out. Verify the wrapper bytes against their blob from the pinned commit. Run the wrapper as root with the full release revision, first with `--check`, then without it. The wrapper runs the installer as `fasakha` with cPanel PHP 8.2.

`apply_expense_stock_purchases.py` checks the scoped release manifest, compiles staged PHP and Blade with the live runtime, checks JavaScript syntax when Node is available, and reads required schema/engine state. Existing expense, ingredient, stock, till, and lock tables must use InnoDB. The receipt table must have a unique expense ID. The check mode changes no application files or database rows.

The installer applies only reviewed hunks to existing files and rejects overlapping live changes. New source paths must be absent or have exactly the reviewed bytes. It publishes the dormant helper and migration, runs only `artisan migrate --force --path=database/migrations/2026_10_18_090000_link_expense_inventory_purchases.php`, then publishes the callers. It neither runs other migrations nor changes application configuration.

Private backups and a JSON receipt are saved beside the application in `expense-stock-purchases-backups`. A failure restores only files whose installed bytes still match this release; concurrent changes are left for review. Database schema or seeded ingredient rows can remain after a migration attempt, even when all code is restored. No automatic database rollback or deletion of stock/audit rows is attempted. The receipt reports that possibility explicitly.

## Verification

`python3 tests/test_expense_stock_purchases_install.py` uses private real Git repositories and mocked database calls. It covers check-only behavior, targeted preservation, conflicts, new-file collisions, metadata, reruns, publication order, scoped migration failure, rollback after migration, concurrent changes, symlink refusal, and schema reply validation. Business integration coverage is in `tests/expense_stock_integration_test.php`.

Final local verification: 206 real-service SQLite integration checks, 63 stock-ledger checks, 13 installer tests, and 12 DOM checks of the actual expense script passed. PHP, JavaScript, and shell syntax checks passed. Full Playwright visual checks could not run in the local environment because a Chromium binary was unavailable; the browser test is included for a browser-enabled environment. Live MySQL concurrency and server execution are not represented by the SQLite suite.
