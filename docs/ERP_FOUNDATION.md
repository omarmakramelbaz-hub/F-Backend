# Fasakhansta ERP foundation

This document describes the ERP foundation in the Laravel backend. The same branch now also includes purchasing, production and accounting; see [ERP_OPERATIONS.md](ERP_OPERATIONS.md) for those workflows, ledger initialization and the second required migration. `F-admin` is a WebView wrapper; the dashboard and new `/erp` workspace belong here. Existing marketplace, GO, restaurant, payment, and order mutation flows remain unchanged.

## Delivered workflows

- Arabic RTL workspace with local CSS/JS, responsive navigation, real scoped totals, and empty states.
- Explicit enrollment of existing restaurants as ERP branches, branch activation, an automatically created branch warehouse, and central/additional warehouses.
- Separate ERP staff authentication. The configured existing platform owner creates administrative admin and branch manager accounts. These plus the configured owner are the only three account types. No seeded passwords or default staff credentials.
- Item catalog: raw material, finished goods, packaging; kilograms to three decimal places and whole pieces.
- Opening balance, receipts, direct confirmed transfers, waste, and count corrections. Every posted document creates an immutable ledger and audit entry. Corrections are new documented movements, not edits/deletes of posted history.
- Employee profiles, manual daily attendance, salary rates with effective month, manual bonuses/deductions/advance repayments, printable monthly statements and finalization.
- Read-only four-column monitoring of existing app orders for enrolled restaurants.

## Roles and boundaries

| Role | Branch scope | Default capabilities |
| --- | --- | --- |
| Owner | All enrolled branches and central warehouses | All ERP actions, including creating accounts and assigning permissions |
| Administrative admin (أدمن إداري) | All enrolled branches and central warehouses | Branches, stock, purchasing, production, finance, employees, payroll, order monitoring, audit |
| Branch manager | One assigned active branch | Stock/production in that branch, employees/attendance, orders, branch audit |

The owner may reduce or extend administrative admin capabilities. Account administration is always owner-only. Branch managers cannot manage branch enrollment, purchasing, finance or payroll even if these capabilities are submitted manually. Branch filters and IDs are checked on the server; central warehouses cannot be accessed by branch managers. Inactive staff sessions and revoked permissions are checked on every request. Staff login never grants legacy admin/GO permissions. Payroll/account audit details require their corresponding capabilities.

### Retired roles and existing accounts

`deputy_manager` remains the stable internal ID for **أدمن إداري**; it does not create a fourth account type. `inventory_manager`, `hr_manager`, and any unknown staff roles cannot be created, assigned, or authenticated, and existing sessions using them lose ERP access on their next request. Saved permissions never override the role allowlist. The configured legacy owner retains full access; staff cannot be assigned `owner`.

No migration deletes or promotes existing accounts. Their records, saved permissions, and audit history remain intact. The owner-only accounts screen identifies unsupported roles and requires an explicit selection of a supported role, permissions and (for branch managers) an active branch before saving. It never silently selects the administrative admin role for these records. Before rollout, the owner must inspect that screen or run a read-only query against `erp_users` for roles outside `deputy_manager`/`branch_manager`, then decide individually whether an account should remain blocked or be reassigned. No production database inspection is implied by the test fixtures.

## Ledger and payroll semantics

Quantities use integer thousandths and money uses integer piasters. Incoming cost is quantity × entered unit cost, rounded once to piasters. Outgoing inventory uses moving average cost; full depletion consumes all residual cost. Transfers conserve both quantity and value, lock balances in ascending warehouse order, and commit both legs atomically. Request keys prevent duplicate submissions and reject reuse with different content. Negative balances and fractional pieces are rejected. A count must include the displayed expected quantity; a concurrent change rejects the stale count.

Transfers here record shipment **and confirmed physical receipt together**. Goods-in-transit and separate dispatch/receipt approvals are not yet modeled. Catalog items do not automatically produce rows in every warehouse; stock warnings cover balances with recorded movements. Posting limits are 100,000 units per quantity input, EGP 1,000,000 per cost/amount input, 100,000 units per balance, and EGP 100,000,000 per balance.

Payroll statements are **accrual calculations, not cash payments**. Attendance does not automatically deduct wages. Partial hiring months, unpaid leave, inactivity, bonuses, deductions, and advance repayments require a reviewed manual adjustment. An inactive employee remains available for historical statements and final settlement. There is no outstanding-loan ledger. Finalization freezes salary/adjustments/attendance for that employee-month; later effective salary rates preserve old statements. Employee profile edits cannot overwrite the hire date or salary history. Payroll is centrally managed; closed statements retain their recorded branch.

## Installation and activation

The feature defaults to **off**. No schema creation runs during web requests. The migration only creates `erp_*` tables and never auto-enrolls restaurants or rewrites existing tables.

**The existing `main` push workflow deploys to production and runs all pending migrations. Keep the development PR unmerged until the release is approved.** Disabling the feature does not prevent that existing deployment workflow from applying migrations after a future merge.

After backup and a staging review using the real application dependencies and schema:

1. Apply the isolated migration in the approved target environment:

   ```sh
   php artisan migrate --path=database/migrations/2026_09_30_180000_create_erp_foundation.php --force
   php artisan migrate --path=database/migrations/2026_09_30_193000_create_erp_operations.php --force
   ```

2. Set `ERP_ENABLED=true` and `ERP_OWNER_USER_ID=<verified legacy admin user ID>` in that environment. The compatibility default owner ID is `1`; explicitly verify and configure the real owner's ID before enabling. This user must have `account_type=admin` or `super_admin`. Use HTTPS and secure Laravel session cookies.
3. Clear/rebuild configuration and view caches using the existing deployment procedure.
4. Sign in as that owner at the existing `/admin/login`, then open `/erp` (also linked in the admin sidebar). Enroll only Fasakhansta branches; external marketplace/GO restaurants are excluded unless an authorized central user deliberately enrolls them.
5. Under **الحسابات والصلاحيات**, create the named administrative admin account with a unique email and password of at least 12 characters. The role defaults to **أدمن إداري** and all operational capabilities. Staff sign in at `/erp/login`.
6. Review the item catalog and existing balances. The owner must initialize the ledger in **الحسابات والكاش** as described in `ERP_OPERATIONS.md` before posting stock or approving payroll. Then enter reconciled opening quantities/costs and add employees and salary rates before daily operations. Test the real owner and one staff login in staging.

To disable access, set `ERP_ENABLED=false` and clear cached configuration. Preserve ERP tables and history. The migration's `down()` refuses to drop tables containing audited business history; use a reviewed backup/recovery procedure instead of a destructive rollback.

## Verification

The isolated Laravel 8 Testbench runtime exercises real routes, middleware, session auth, Blade views, validation, services and the new migration. It uses a minimal legacy schema fixture and does not boot the entire application's external integrations.

```sh
cd tests/erp_runtime
composer install --no-interaction --prefer-dist
php vendor/bin/phpunit
```

CI runs SQLite and MySQL 8. It checks owner/administrative-admin/branch authorization, live permission revocation, login throttling, all rendered screens, unrelated restaurant exclusion, branch and employee setup, integer valuation, transfer conservation, duplicate retries, insufficient stock rollback, stale counts, salary effective dates, immutable statements, sensitive audit filtering, and rollback protection. MySQL additionally exercises competing stock writers. For local MySQL testing only, use database `erp_test`, set `ERP_TEST_MYSQL=1` and `ERP_TEST_MYSQL_PASSWORD`; **the test suite empties that test database**.

The test runtime inherits Laravel 8 compatibility constraints; its Composer audit setting allows installing that legacy version for tests only. No production dependency changes are introduced. Full dependency modernization is outside this feature.

## Later ERP phases

Purchasing, supplier payments, production recipes/batches, cash movements and a balanced accounting ledger are now implemented in the operations extension. Remaining phases: automatic consumption from app/POS sales, POS/KDS operational mutations, in-transit inventory approvals, a complete employee-advance ledger, tax handling, period closing and comprehensive profit reporting. The existing vendor-proceeds figure is not presented as profit.
