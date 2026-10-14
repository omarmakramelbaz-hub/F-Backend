# Order alert and customer restaurant menu repair

This release repairs the shared dashboard order sound and the customer restaurant product response. It changes only these application paths:

- `resources/views/admin/layouts/footer.blade.php`
- `public/dashboard/js/order-board.js`
- `public/dashboard/js/branch-print-receiver.js`
- `public/dashboard/js/dashboard-order-alerts.js` (new shared sound engine)
- `app/Http/Resources/Api/Vendor/ResturantProductResource.php`
- `app/Services/Dashboard/PosBranchPrinting.php`
- `app/Services/Dashboard/OrderBoardService.php`
- `app/Http/Controllers/Dashboard/OrderBoardController.php`

The print feed's latest ticket now excludes canceled or unsent drafts, preventing a persistent sound for an order that the branch cannot act on. The dashboard feed supplies actor-scoped authoritative pending alert keys across its existing order sources, independent of visible board filters or pagination. Off-board polling requests a lightweight `alerts_only=1` response instead of rendering full board cards. The installer uses baseline `adc9e46a86df569bd6dab15e9f4302fc1c55aa91`. Existing files receive uniquely anchored patches rather than complete replacement. Unrelated live Meta messaging, order editor and dashboard changes survive. A changed or ambiguous patch anchor stops deployment before publishing any file, including a prefix/tail edit within a reviewed boundary hunk. The new sound engine cannot overwrite a different pre-existing file with the same name.

Use the full reviewed release SHA from branch `codex/order-alert-menu-fix-20261014`. The launcher and Python installer must both be read from that immutable release and verified against their Git blob IDs before executing them as root. The launcher also verifies the installer blob, and the installer verifies every source blob it reads. Deployment is restricted to `/home/fasakha/public_html`, the `fasakha` application account, and PHP `/opt/cpanel/ea-php82/root/usr/bin/php`.

Run the pinned launcher first with `RELEASE_SHA --check`. This validates live patch anchors, permissions, staged PHP syntax, and Blade compilation without publishing application files, removing caches, creating deployment locks or creating backups. If the deployment's reviewed source files are group writable, `--check --repair-permissions` permits only leaves owned by `fasakha` in its primary group. The actual deployment with `--repair-permissions` removes their group-write bit atomically. It does not change unrelated sources, directories or cache permissions; world-write, special permission bits, other owners/groups and symlinks are refused.

Run the same launcher with `RELEASE_SHA` (and the same explicit permission option if required) to apply. It first keeps a root-only backup under `/home/fasakha/order-alert-menu-backups/`. It publishes dependencies before activating the footer, then invalidates only the compiled footer template when its source changed. It does not run migrations, database writes, route/config cache commands or a broad `view:clear`. A previously installed release is safe to rerun.

Failure restores the backed-up sources and compiled footer where no concurrent operator change occurred. `ORDER_ALERT_MENU_ROLLBACK_RESTORED` confirms the automatic restoration. `ORDER_ALERT_MENU_ROLLBACK_NEEDS_REVIEW` preserves an intervening source/cache edit for operator review instead of overwriting it. The backup manifest records previous bytes, ownership and modes. For a later manual rollback, compare each live source against this release before restoring the corresponding backup entry; preserve concurrent changes, restore the recorded ownership/mode atomically, remove only a newly introduced sound engine that still matches this release, and invalidate the compiled footer. A whole-repository reset would discard other deployed work and is outside this rollout.

Validation:

- Run `python3 -m unittest discover -s tests/deployment -p 'test_order_alert_menu_installer.py'` for surgical overlays, collisions, strict/opt-in permissions, read-only preflight, per-view cache handling, backups and failure restoration.
- Run `node tests/dashboard_order_alerts_test.cjs` for sound rejection recovery, retries, pending-order tracking, off-board polling, navigation restoration and acknowledgment.
- Run `RESTAURANT_TEST_VENDOR=/path/to/isolated-laravel8-vendor php tests/restaurant_menu_quantity_runtime.php` for the actual resource/Eloquent response regression. It uses an in-memory test database and verifies the installed application's optional integer hint for whole/null quantities while preserving exact stored decimal weights and the additive fractional response. This release does not modify stored order quantities.
- Run `ORDER_BOARD_TEST_VENDOR=/path/to/isolated-laravel8-vendor php tests/order_board_items_runtime.php` to confirm the existing order editor still handles weighted menu items and branch isolation.
- Run `BRANCH_PRINT_TEST_VENDOR=/path/to/isolated-laravel8-vendor php tests/branch_print_alert_runtime.php` and `ORDER_BOARD_TEST_VENDOR=/path/to/isolated-laravel8-vendor php tests/order_alert_keys_runtime.php`. These exercise real SQLite queries, receiver and actor scope, all pending orders beyond pagination, and draft/cancelled/handled exclusions without database writes.
- Run `node tests/browser/order-alert-smoke.cjs` with Playwright available (or `CODEX_PRIMARY_RUNTIME_NODE_MODULES` set), using `PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` if needed. This checks actual WAV playback under strict autoplay, user-gesture recovery, and independent acknowledgements.
- After deployment, refresh the dashboard and interact once with its sound control if the browser requires it. Create a new application or call-center delivery order for the receiving account, confirm sound starts, and confirm it continues until the pending orders are handled. Keep browser/device sound enabled.
- Open a restaurant in the customer application after a previous order, and confirm product data loads for both whole and fractional quantities. The legacy `latest_order_qty` field stays a nullable integer; `latest_order_quantity` retains fractional weights for clients that support them.

Automated sound tests verify state transitions and browser rejection recovery. Production device playback and the installed customer's restaurant screen still require a check on those devices.
