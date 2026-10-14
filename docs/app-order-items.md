# Application order item editing

The application order card includes **تعديل الأصناف**. Authorized staff can change a selected line's weight or quantity, remove it, and add items from that order's own branch. The editor shows updated totals before saving. Existing lines retain their saved prices and options; new lines use the branch's current catalog. Quantities support three decimal places (for example, `0.850` in the actual-weight field is 850 grams, including half/quarter sizes). Products sold by the piece require whole numbers.

Edits are limited to cash orders before collection and dispatch. Delivered, completed, paid online, and unsupported service orders cannot be edited here. Branch permissions, current order state, payment status, stock validation, and the submitted revision are checked again under database locks. A competing staff edit returns a conflict and requires reopening the editor.

## Release installation

The reviewed release is on `codex/app-order-item-editor-20261014`. Use its complete reviewed commit SHA, fetched into the existing server repository. Run the root wrapper with that SHA; do not run a checkout, merge, or reset on the production working tree.

```bash
bash /path/to/verified/apply_app_order_items_root.sh FULL_REVIEWED_RELEASE_SHA --check
bash /path/to/verified/apply_app_order_items_root.sh FULL_REVIEWED_RELEASE_SHA
```

If existing cPanel source files are writable by the application's group, use the explicit repair option:

```bash
bash /path/to/verified/apply_app_order_items_root.sh FULL_REVIEWED_RELEASE_SHA --check --repair-permissions
bash /path/to/verified/apply_app_order_items_root.sh FULL_REVIEWED_RELEASE_SHA --repair-permissions
```

The default remains strict. `--repair-permissions` accepts group-write only on the sixteen reviewed source leaves when the owner and group exactly match the resolved `fasakha` account and its primary group. It rejects world-write, special permission bits, foreign owners/groups for this exception, and symlink paths. Every patch, PHP syntax check, and staged Blade compilation must pass before publication. The installer then atomically publishes the reviewed bytes with only group-write removed (`0775` becomes `0755`, `0664` becomes `0644`). An already-matching file is republished when its mode needs repair. It does not run recursive `chmod` or touch unrelated files or cache permissions. Hardlink aliases retain their original bytes and permissions; backups record the original owner/group/mode, and a failed installation restores that metadata. Combining the option with `--check` reports proposed repairs without changing source content, permissions, or inodes. Both flag orders are accepted.

The wrapper is fixed to `/home/fasakha/public_html`, application user `fasakha`, and PHP `/opt/cpanel/ea-php82/root/usr/bin/php`. It extracts the installer from the supplied immutable Git commit and verifies the helper's Git blob before running it.

The installer reads the reviewed files directly from that commit. It applies unique, eight-line context edits to existing files against base `4a81f073c134d762fdb31afa8e5dda99f978192e`; it does not replace their unrelated live customizations. In particular, the newer `OperatingDay` 06:00 Cairo filter in `OrderBoardService` is preserved. Conflicting or ambiguous context stops installation before publication. New files must be absent or exactly equal to the reviewed release. The `--check` option only validates source anchors, PHP syntax, staged Blade compilation, and basic runtime readiness; it does not migrate or publish files.

The migration `2026_10_14_190000_preserve_app_order_item_weights.php` widens an integer `carts.qty` column to a fractional decimal while preserving its existing nullability and default. It runs before the editor becomes visible. Related API quantity casts retain fractional quantities. The installer does not change environment settings, application configuration, queues, unrelated routes, navigation, or Git HEAD.

Publication uses atomic file replacements, preserves existing owners/modes, and retains a private physical backup under `/home/fasakha/app-order-items-backups/`. Atomic replacement preserves existing hardlink aliases and release backups. The installer refuses symlink paths and concurrent source changes. It clears compiled views, refreshes the route cache only if one was already in use, and verifies the new authenticated routes and decimal quantity storage.

Success prints `APP_ORDER_ITEMS_READY` with the release SHA and backup location. A runtime failure restores installed code and route cache from the backup and prints `APP_ORDER_ITEMS_ROLLBACK_RESTORED`. Additive decimal column widening and its migration record remain in place so fractional quantities are never rounded away. `APP_ORDER_ITEMS_ROLLBACK_NEEDS_REVIEW` means a concurrent operator edit prevented complete automatic restoration; the installer preserves that newer edit and the backup for review.

## Validation

```bash
python3 -m unittest discover -s tests/deployment -p 'test_app_order_items_installer.py'
ORDER_BOARD_TEST_VENDOR=/path/to/test-runtime/vendor php tests/order_board_items_runtime.php
php tests/app_order_quantity_migration_test.php
node tests/browser/order-board-items.cjs
```

The deployment tests cover preserving unrelated live edits, refusing patch conflicts/new-file collisions/symlinks, preflight without publication, hardlink backup preservation, successful backups, and restoration after a failed runtime check. Permission tests cover strict defaults, the exact trusted owner/group exception, whole-batch and mode-only repairs, rejection of world-write/special bits/foreign groups, unchanged aliases/unrelated paths, read-only preflight, original mode restoration, concurrent operator changes, and wrapper flag combinations. Backend tests exercise authorization, editable state, fractional quantities, catalog/line integrity, conflicts, repricing, and atomic persistence.

After installation, open an eligible cash application order and verify a weight edit, removal, and new item, then reopen its details and receipt. A previously open editor must reject saving after another staff member changes the same order. Completed and paid orders must not expose the editing action.
