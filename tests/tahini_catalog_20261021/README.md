# Tahini shared-catalog checks

Run with PHP 8.0+ and `pdo_sqlite`/`mbstring` enabled:

```sh
TAHINI_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/tahini_catalog_20261021/run.php
```

If `TAHINI_TEST_AUTOLOAD` is omitted, the suite uses the repository's `vendor/autoload.php`. Dependencies must include Illuminate database, events, validation and translation.

The suite uses a real in-memory SQLite database, actual expense/inventory migrations, the actual `ExpenseCategories` service, and actual `TakeawayAccess` persisted-owner checks. A minimal Eloquent `App\Models\User` fixture avoids unrelated application authentication/media traits; the primary owner still comes from the real users table. A test SQLite connection injects failed COMMIT acknowledgements after/before the real commit, and real SQLite triggers inject category/ingredient failures.

Coverage includes read-only check; one shared active expense category and one explicit-unit ingredient for all 32 branches; fixed service idempotency key; unchanged balances, movements, recipes, financial entries and GO rows; repeat preservation; normalized Arabic aliases; conflicting units; duplicate records; missing schema; changed branches; persisted-owner authority; atomic rollback; immutable first backup; and commit recovery. No ingredient quantity, recipe, purchase or financial transaction is fabricated.

No production environment, network, messages or external services are accessed. This does not replace the server preflight or full Laravel/MariaDB integration.
