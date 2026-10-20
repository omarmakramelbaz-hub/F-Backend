# Tahini purchases integration regression

Run with PHP 8.0+, `pdo_sqlite`, `mbstring`, and `tokenizer`:

```sh
TAHINI_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/tahini_purchases_20261021/run.php
```

When `TAHINI_TEST_AUTOLOAD` is absent, the suite uses the repository's `vendor/autoload.php`. Dependencies include Illuminate database, events, validation and translation. PHP `proc_open` must be available so the actual source patch helper can run against a temporary project; its child process loads `tokenizer.so` from the parent's configured extension directory.

Fixtures contain service and migration files from the associated ERP Git source snapshot of 21 October 2026, with local copy-integrity SHA256 checksums in `fixtures/source-checksums.json`. The `ExpenseCategories` file hash was independently confirmed by the production diagnostic; companion fixture files were not individually measured on the server. They include the confirmed branch-scoped category implementation that produced the live error. The test proves the former payload passes its first validator but then fails specifically on the required branch, without SQL writes. This prevents reverting to the older shared-create service used by the earlier tests.

The real patch helper prepares and publishes its narrowly targeted registry change inside a temporary project. The actual scoped category service then exposes the protected canonical `purchase_tahini` choice in all 32 branch catalogs. The actual integrated runner's check, prepare and finalize phases are exercised against real SQLite and the installed fixture class. Preparation creates one raw ingredient named `طحينة` in kg; it does not record purchases, money, inventory quantities, recipes or category rows. The original failed-attempt backup is retained byte-for-byte.

Tests also cover repeat preservation; deletion protection; kilogram quantity normalization; a future expense receipt, retry, exact reversal and reversal retry through actual `ExpenseStockPurchases` and `BranchInventory`; conflicting existing units; changed branches; blocked category visibility; historical tahini aliases; and ingredient-insert rollback using a real SQLite trigger. Expense rows created for the future-operation test are fixtures, not installation behavior.

All services and migrations are real source fixtures. Only `App\Models\User` is a minimal Eloquent fixture model that omits unrelated authentication/media traits; persisted owner authorization still runs through the associated access-service fixture. No network, production environment or external messages are accessed. This is not a full Laravel/MariaDB or browser UI test, and it does not execute the production wrapper's cross-process deployment orchestration.
