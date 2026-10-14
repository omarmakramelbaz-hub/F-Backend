# Application order editor POS followup

This release upgrades the installed application-order editor to a branch catalog with categories, item images, an invoice, and a weight keypad. It also permits users already authorized to see an eligible preparing order to edit its items while preserving branch scope, cash/payment checks, and order-state locks.

This is an incremental upgrade from the installed release `adc9e46a86df569bd6dab15e9f4302fc1c55aa91`. Release `8068c05546be28f6a6394d10557b815047ee430f` has the same feature source files and is compatible. New deployments must first use the original application-item installer. The followup requires existing authenticated item GET/POST routes, the installed editor files, fractional quantity storage, and the existing `public/dashboard/css/takeaway-pos.css` stylesheet readable by the application user. That stylesheet is checked as a read-only prerequisite and is not replaced. The followup does not rerun migrations or change original controllers, routes, catalog services, payment configuration, queues, or environment settings.

The dedicated installer and root wrapper are `deployment/install_app_order_items_pos.py` and `deployment/apply_app_order_items_pos_root.sh`. Fetch the reviewed commit, verify its full SHA and the extracted wrapper's Git blob, then run:

```bash
bash /path/to/verified/apply_app_order_items_pos_root.sh FULL_REVIEWED_RELEASE_SHA --check
bash /path/to/verified/apply_app_order_items_pos_root.sh FULL_REVIEWED_RELEASE_SHA
```

If reviewed source leaves still have trusted application-group write permissions, add `--repair-permissions`; it can be combined with `--check` in either order. The default remains strict. The explicit exception only accepts group-write for regular source files owned by the resolved `fasakha` account and its primary group. World-write, special bits, foreign owners/groups for this exception, and symlinks remain blocked. Publication removes only group-write using atomic replacement, preserving any hardlink aliases and their permissions. The original owners and modes are recorded and restored if verification fails.

Exactly seven existing files are eligible for publication:

- `app/Services/Dashboard/OrderBoardItems.php`
- `public/dashboard/js/order-board-items.js`
- `public/dashboard/css/order-board-items.css`
- `resources/views/admin/orders/board_items.blade.php`
- `resources/views/admin/orders/board.blade.php`
- `resources/lang/ar/order_board.php`
- `resources/lang/en/order_board.php`

Each update is a unique-context patch from the pinned installed release to the reviewed new version. Unrelated live edits are retained; conflicts stop before publication. Already-installed changes are accepted idempotently. Source-file existence, all patch contexts, PHP syntax, staged Blade compilation, routes, class resolution, and quantity storage are checked before any source is replaced. The original application-item installer and its original foundation files are not changed by this workflow.

The installer shares the original install lock, writes a private backup under `/home/fasakha/app-order-items-pos-backups/`, publishes backend/assets before the revised views, clears compiled views, and verifies runtime readiness again. Route/configuration caches and the database schema are untouched. Success prints `APP_ORDER_ITEMS_POS_READY`; automatic restoration prints `APP_ORDER_ITEMS_POS_ROLLBACK_RESTORED`. A concurrent operator change is preserved and reported as `APP_ORDER_ITEMS_POS_ROLLBACK_NEEDS_REVIEW` while other unchanged leaves are restored.

```bash
python3 -m unittest discover -s tests/deployment -p 'test_app_order_items*installer.py'
```
