# Incremental dashboard presentation release

This release starts from `9984a1f6ee065ced2fcc5eefcec5bd2f599914df`, the confirmed saved-customer branch update. Production receives incremental file changes, so its live checkout can contain later integrations outside this reference tree. The installer does not require a particular live Git HEAD and does not reset or switch the checkout.

The root entry point targets `srv1836524`, `/home/fasakha/public_html`, and executes application changes as `fasakha` using the existing cPanel PHP 8.2 executable. The separate main-branch deployment workflow is not used by this release.

## Runtime changes

Five PHP files receive eight existing-method replacements and two presentation helpers. Address writes use validated fields and server-assigned ownership. Recovery requests keep their existing endpoint and request-field contracts while validating verification input and consuming successful recovery state. Notification construction uses the existing administrator user model.

Delivery cards batch cashier names, branch-scoped customer revisions, and the viewer's reprint check for the rows being returned. Each poll reads the database again. Orders, financial balances, stock, delivery transitions, pagination and completion timestamps keep their existing contracts.

| Isolated fixture | Previous metadata queries | Batched metadata queries |
|---|---:|---:|
| 20 mixed paid/unpaid cards | 49 | 3 |

The fixture verifies an exact payload match and a 93.88% reduction in these metadata queries. It also verifies current cashier names, customer revisions and persisted permissions on a subsequent poll. This is a query-count measurement in SQLite, not a production response-time measurement.

## Guarded application

The root entry point checks the expected host, application root, account and payload Git blob hashes. The Python installer checks the complete release payload hashes and fingerprints the selected PHP methods using the PHP tokenizer. Comments and whitespace may differ; an unrecognized method implementation stops the entire preflight.

`--check` builds and lints the proposed files without changing the application or creating backup receipts. Applying the same release again is a no-op. Unrelated methods and integrations remain in the live files. New presenter helpers are checked for name collisions and exact repeat-application fingerprints.

Before any file is published, originals and metadata are saved outside the web root under `/home/fasakha/dashboard-guarded-method-backups/`. Each write uses an atomic replacement and checks for concurrent changes. A failure triggers reverse-order restoration of the attempted files. If another process changes a published file, the installer preserves that edit and records `rollback_review_required` in its private receipt.

The release does not run migrations, replace dependency metadata or built assets, flush caches, or reload OPcache/PHP workers. Production response-time changes must be measured after the server applies the release and executes the updated PHP code under its existing worker configuration.

## Local verification

```sh
composer install --no-scripts --no-plugins
vendor/bin/phpunit tests/Feature/AuthAddressSafetyTest.php
composer install --working-dir=tests/desktop_pos_runtime --no-scripts --no-plugins
CRM_TEST_AUTOLOAD="$PWD/tests/desktop_pos_runtime/vendor/autoload.php" php tests/phone_board_presentation_batch_test.php
CRM_TEST_AUTOLOAD="$PWD/tests/desktop_pos_runtime/vendor/autoload.php" php tests/phone_delivery_completion_time_test.php
php tests/notification_dispatch_unit.php
python -m unittest tests/test_dashboard_guarded_methods.py -q
```

The SQLite fixtures do not bootstrap production configuration or send messages. Installer tests use temporary application copies and cover dry-run, successful installation, idempotence, outside-method preservation, unknown-method conflicts, helper collisions, payload tampering, symlinks, a publish failure, a read failure immediately after rename, a final receipt failure, and concurrent-edit handling before verification and during rollback. CI runs these checks on PHP 8.2 and 8.3.
