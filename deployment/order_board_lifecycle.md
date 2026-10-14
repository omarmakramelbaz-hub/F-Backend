# Application order lifecycle repair

This focused release uses production baseline `9210f7a2d5c4016a09130ba6551ee9b708300f82` and changes these application paths:

- `app/Services/Dashboard/BranchInventory.php`
- `app/Http/Controllers/CheckoutController.php`
- `app/Services/Dashboard/GoStoreBoardActions.php`
- `app/Services/Dashboard/OrderBoardClock.php`
- `app/Observers/OrderBoardClockObserver.php`
- `app/Services/Dashboard/OrderBoardService.php`
- `app/Http/Controllers/Dashboard/OrderBoardController.php`
- `public/dashboard/css/order-board.css`
- `resources/views/admin/orders/board_card.blade.php`
- `resources/views/admin/orders/board_detail.blade.php`
- `app/Services/Dashboard/LegacyOrderCancellation.php` (new)

The inventory snapshot matches a customer's real `kilo` feature ID to the same SKU's recipe base variant `0` when that exact feature has no dedicated variant. It preserves the selected feature and the order's weight; it does not borrow another product's feature or invent a recipe. Order cards and details display persisted size and preparation selections, including full/base preparation only for products that offer it. Long product names truncate independently while the full selected-option line remains visible underneath. Cancellation receives a red action style in the details view.

Authorized accepted orders can be canceled throughout preparation, including courier search or assignment, until actual handoff. The board and details view expose the same action and confirmation. Cancellation retains branch/customer authorization, stale-state checks, row locks and existing payment evidence, cleans up only that order's delivery invitations, and sends the assigned courier's notice after commit. The current-order checkout callback also guards activation so a late or repeated payment callback cannot reset an accepted, dispatched or closed order to new/pending. Its runtime settlement follows the existing payment type and recorded ledger; deployment itself does not invoke cancellation, payment/refund operations, stock consumption or any database mutation.

The requested automatic timing is **45 minutes from acceptance to the courier phase, then 90 minutes from the actual courier-phase entry to completion**. Acceptance time stays fixed. An authorized manual handoff starts its own 90-minute courier window without resetting the acceptance time, and retries do not reset that first courier timestamp. A late scheduler run moves an overdue preparing order to the courier phase and then gives it a fresh 90-minute window. Already-dispatched clocks missing a courier timestamp also receive a fresh window. Canceled/finished orders close their clocks; pickup orders retain their manual flow. Existing payment safeguards and assigned cash-courier exceptions remain in effect.

Use the full reviewed release SHA supplied for this batch on branch `codex/order-alert-menu-fix-20261014`. Extract and verify `deployment/apply_order_board_lifecycle_root.sh` from that immutable release before executing it as root. The launcher verifies the Python installer blob, and the installer verifies each baseline/release source blob. It is restricted to `/home/fasakha/public_html`, the `fasakha` application account, and PHP `/opt/cpanel/ea-php82/root/usr/bin/php`.

Run `RELEASE_SHA --check` first. Preflight reads the live source, validates uniquely anchored eight-line patches, lints staged PHP, and compiles staged Blade in memory. It publishes no application file, changes no cache, and creates neither backups nor an application deployment lock. An overlapping/ambiguous live edit or a different pre-existing new service causes refusal before publication. Existing unrelated dashboard and desktop changes survive surgical patching.

If reviewed leaves are group writable, use the existing explicit `--repair-permissions` option with preflight and apply. It accepts that group-write exception only for source leaves owned by `fasakha` in its primary group. Apply removes their group-write bit atomically and preserves original ownership/modes in the backup. Symlinks, world-write/special modes and unsafe owners/groups are refused. Compiled-view caches remain subject to strict permissions.

Apply with the same pinned launcher and `RELEASE_SHA`, keeping the explicit permission option if required. The new cancellation service is published before consumers; the checkout callback guard and supporting inventory/store/clock changes precede the board service and controller, and the CSS precedes the card template. A root-only backup and manifest with target blob IDs are stored under `/home/fasakha/order-board-lifecycle-backups/`. Only the changed card/details templates' compiled caches are invalidated. No migrations, scheduler runs, route/config cache commands or broad view-cache clearing are performed.

After publication, the published Blade is compiled in memory and source snapshots are checked again. Failure automatically restores changed leaves and the invalidated compiled view where no intervening operator edit occurred. `ORDER_BOARD_LIFECYCLE_ROLLBACK_RESTORED` confirms restoration. `ORDER_BOARD_LIFECYCLE_ROLLBACK_NEEDS_REVIEW` preserves a concurrent source/cache edit for review; use the root-only backup's bytes and manifest to restore only the affected leaves after comparison. The installer can be rerun safely for an already installed release.

Installer and isolated behavior checks (use a separate Laravel 8 vendor directory):

```bash
python3 -m unittest discover -s tests/deployment -p 'test_order_board_lifecycle_installer.py'
bash -n deployment/apply_order_board_lifecycle_root.sh
ORDER_BOARD_TEST_VENDOR=/path/to/isolated-laravel8-vendor php tests/order_board_inventory_runtime.php
ORDER_BOARD_TEST_VENDOR=/path/to/isolated-laravel8-vendor php tests/order_board_options_runtime.php
ORDER_CANCEL_TEST_VENDOR=/path/to/isolated-laravel8-vendor php tests/order_board_cancel_runtime.php
ORDER_BOARD_TEST_VENDOR=/path/to/isolated-laravel8-vendor php tests/order_board_clock_intervals_runtime.php
ORDER_BOARD_TEST_VENDOR=/path/to/isolated-laravel8-vendor php tests/order_board_checkout_guard_runtime.php
```

These regressions use isolated fixtures and do not connect to production. Existing wallet transfer-reference and verified payment columns are required for prepaid cancellation; this rollout creates no new schema. After deployment, refresh the board and verify an authorized preparing order offers cancellation in both card and details. Confirm its selected size/preparation labels, completing a kilogram-feature item consumes its own configured recipe, and new acceptance/manual-dispatch clocks follow the 45-plus-90 policy on the production scheduler.
