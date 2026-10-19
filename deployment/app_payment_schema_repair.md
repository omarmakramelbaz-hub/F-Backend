# App payment amount schema repair

The customer app cannot return a Paymob checkout link when `payments.total_price`
is missing. The checkout controller saves the amount sent to Paymob in this
column for both wallet top-ups and online order payments. The original table
migration incorrectly used `change()` inside `Schema::create`; the measured
production schema from 2026-10-08 confirms the column is absent.

This repair adds a nullable unsigned `DECIMAL(15,2)` amount column only when it is
missing. Existing payment records receive `NULL`, because their gateway amount
is unknown. Existing compatible amount columns are left intact. It does not
infer historical payment amounts, credit wallets, change payment states, delete
pending attempts, contact Paymob, replace application files, or run unrelated
migrations. The new corrective migration supports future normal deployments;
the original create migration also removes the erroneous `change()`.

## Production

Fetch the published repair branch in `/home/fasakha/public_html` as `fasakha`.
Extract `deployment/apply_app_payment_schema_root.sh` from its exact commit and
check its Git blob hash before executing it as root:

```sh
bash /path/to/extracted/apply.sh FULL_COMMIT_SHA --check
bash /path/to/extracted/apply.sh FULL_COMMIT_SHA --apply
```

The installer verifies the server, path, owner, PHP executable and payload blobs.
The helper checks the existing table and required checkout columns, uses the
live Laravel database connection, and serializes repair runs with a database
advisory lock. Its output contains schema metadata, without customer data or
gateway credentials. A database lock timeout fails rather than waiting forever.
Check mode exits `0` when ready, `2` when the amount column needs repair, and `1`
when a preflight check refuses the operation.

After application, retry a wallet top-up in the customer app and confirm that
the Paymob checkout page opens. Complete any payment only as part of the
owner's normal payment workflow. SQL verification alone cannot establish that
the external payment provider is operational.

## Validation

Focused tests run against a disposable MariaDB database. They cover the absent
column, existing historical records, exact new amount inserts, repeat runs,
compatible existing columns, and incompatible schema rejection. No production
payment is created by the tests.

```sh
python3 tests/payment_schema/native-run.py MARIADB_EXTRACTED_ROOT PHP_EXECUTABLE ILLUMINATE_AUTOLOAD_PHP
```

The test PHP executable needs `pdo_mysql` and `pdo_sqlite`. The test database
binds only to loopback, uses a temporary data directory, and is stopped and
removed after the run.
