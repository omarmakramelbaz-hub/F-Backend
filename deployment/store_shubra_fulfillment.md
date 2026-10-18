# Fasakhansta store fulfillment through Shubra

Completed Fasakhansta application orders from retail stores fulfill against Shubra
(`f:301`). Their source restaurant, storefront prices, customer receipt and order
identity remain intact. Physical branches and the separate `gs:` Go store system
keep their existing behavior. POS checkout is outside this release.

Eligible sources are direct children of restaurant 301, excluding the fixed
physical branches, plus the reviewed `FasakhanstaDeliveryOrigin::CAIRO_ORIGINS`
identities whose legacy parent is empty/zero and whose registered name matches.
A store with another actual parent is never reassigned.

Inventory translates the source SKU to a unique Shubra SKU by shared product
identity, then exact normalized name when there is no shared product link.
Portions translate by their owning product feature name. The fresh Shubra recipe
or direct balance performs the deduction, and ingredient audit snapshots retain
the source store/SKU/feature. Missing or ambiguous SKU/portion links, missing
recipes/direct stock, and deleted source products reject completion atomically.
Fix that catalog or recipe link before completing the affected order.

Home sales and shift application sources attribute each eligible order to Shubra
once. Existing cash rules remain: vendor-collected cash enters the expected
drawer net of delivery; electronic and delegate-collected proceeds remain outside
the physical drawer. Prior `app`/`app_cash` claims in any branch and prior stock
deductions prevent replay. The release does not move historical balances, rewrite
closed snapshots, or replay completed orders. It does not change shift closing
reset/authorization/printing behavior.

## Deployment

Fetch the exact reviewed branch and full revision. Extract
`deployment/apply_store_shubra_fulfillment_root.sh` at that revision, verify its
blob hash, then run as root on `srv1836524`:

```bash
bash "$installer" "$revision" --check
bash "$installer" "$revision"
```

The wrapper uses `/home/fasakha/public_html` and cPanel PHP 8.2. It executes the
installer as `fasakha`. The installer checks its base ancestry and five-file
manifest, merges unrelated live changes, stages and lints all candidates, and
checks Shubra and eligible source metadata in a read-only transaction. Conflicts
stop before source changes. It keeps a private source backup and guarded rollback
receipt. No migrations or application data writes are performed by installation.

Expected markers: `STORE_SHUBRA_FULFILLMENT_DATABASE_OK`, then
`STORE_SHUBRA_FULFILLMENT_PREFLIGHT_OK`, and finally
`STORE_SHUBRA_FULFILLMENT_OK files=5; BACKUP=...` (or `already_current=1`).

## Validation

Executed using an isolated Illuminate 8 SQLite runtime and PHP 8.3:

- `tests/fasakhansta_store_fulfillment_test.php`: 48 policy/SKU/portion checks.
- `tests/store_shubra_inventory_test.php`: 15 actual inventory/ledger checks.
- `tests/desktop_pos_runtime/store-shubra-accounting.php`: 21 actual reporting,
  cash, claims, date and permission checks.
- `tests/store_shubra_fulfillment_installer_test.py`: 19 installer checks,
  including real read-only database probes, conflict and rollback behavior.
- Existing `tests/dashboard_stock_shift_all_branches_test.php` passed.
- PHP parsing, Bash syntax, and `git diff --check` passed.

The two updated Laravel Feature test files are retained as full-application
regressions; the complete Laravel/PHPUnit runtime was unavailable here, so those
Feature tests were not executed. The known Mahalla live source variant merges
cleanly without overwriting its unrelated changes. Production installation and a
real completed order still require the server-side run.
