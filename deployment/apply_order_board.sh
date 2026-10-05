#!/usr/bin/env bash
set -euo pipefail

# Run as the application owner, from its existing checkout, with a pinned release SHA.
release_sha="${1:?Pass the reviewed 40-character release commit SHA}"
[[ "$release_sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release SHA'; exit 1; }
project_path="$(git rev-parse --show-toplevel)"
cd "$project_path"
test -f artisan
test -f vendor/autoload.php
test -f .env
command -v php >/dev/null

if test -n "$(git status --porcelain --untracked-files=no)"; then
    echo 'Tracked server files have local changes. Save/reconcile them before applying this release.'
    exit 1
fi

git fetch --no-tags origin refs/heads/codex/unified-app-orders-20261003
test "$(git rev-parse FETCH_HEAD)" = "$release_sha" || { echo 'Release branch changed; use its current reviewed SHA.'; exit 1; }
current_sha="$(git rev-parse HEAD)"
git merge-base --is-ancestor "$current_sha" "$release_sha" || { echo 'Server version diverged; no files changed.'; exit 1; }

backup_name="backup/before-order-board-$(date -u +%Y%m%dT%H%M%SZ)-${current_sha:0:8}"
git branch "$backup_name" "$current_sha"
echo "Saved code snapshot: $backup_name ($current_sha)"
runtime_backup="$(dirname "$project_path")/order-board-release-backups/$(date -u +%Y%m%dT%H%M%SZ)-${current_sha:0:8}"
old_umask="$(umask)"
umask 077
mkdir -p "$runtime_backup"
config_cached=0
if test -f bootstrap/cache/config.php; then
    test ! -L bootstrap/cache/config.php
    cp bootstrap/cache/config.php "$runtime_backup/config.php"
    config_cached=1
fi
umask "$old_umask"
echo "Saved runtime configuration snapshot: $runtime_backup"

rollback() {
    local failed_status=$?
    trap - ERR
    git reset --hard "$current_sha"
    if test "$config_cached" = 1; then
        cp "$runtime_backup/config.php" bootstrap/cache/config.php
    else
        rm -f bootstrap/cache/config.php
    fi
    php artisan route:clear || true
    php artisan view:clear || true
    echo "Release checks failed. Code restored to $current_sha."
    exit "$failed_status"
}
trap rollback ERR
git merge --ff-only "$release_sha"
php -l app/Http/Controllers/Dashboard/OrderBoardController.php
php -l app/Services/Dashboard/OrderBoardService.php
php -l app/Services/Dashboard/GoStoreBoardActions.php
php -l app/Services/Dashboard/BestEffortOrderMail.php
php -l app/Services/Dashboard/OrderBoardMenu.php
php -l app/Http/Controllers/Dashboard/OrderBoardMenuController.php
php -l app/Http/Controllers/Api/V1/Vendor/OrderController.php
php -l app/Services/Dashboard/OrderBoardClock.php
php -l app/Services/Dashboard/LegacyOrderCompletion.php
php -l app/Services/Payments/LegacyPaymentFailure.php
php -l app/Services/Dashboard/SupportFirestore.php
php -l app/Services/Dashboard/SupportInbox.php
php -l app/Http/Controllers/Dashboard/DashboardInboxController.php
php -l app/Http/Controllers/Dashboard/FcmNotificationsController.php
php -l app/Http/Controllers/Dashboard/TakeawayController.php
php -l app/Http/Controllers/Dashboard/PosServiceController.php
php -l app/Http/Controllers/Dashboard/DineInController.php
php -l app/Http/Controllers/Dashboard/PhoneOrdersController.php
php -l app/Http/Controllers/Dashboard/BranchOrdersController.php
php -l app/Services/Dashboard/PosServiceTicket.php
php -l app/Services/Dashboard/PosServiceTable.php
php -l app/Services/Dashboard/BranchShiftClosing.php
php -l app/Http/Controllers/Dashboard/BranchShiftClosingController.php
php -l app/Services/Dashboard/BranchExpenses.php
php -l app/Services/Dashboard/BranchOperations.php
php -l app/Services/Dashboard/BranchStock.php
php -l app/Services/Dashboard/BranchInventory.php
php -l app/Services/Dashboard/HomeOverview.php
php -l app/Http/Controllers/Dashboard/HomeController.php
php -l app/Http/Controllers/Dashboard/BranchStockController.php
php -l app/Services/Dashboard/BranchPayroll.php
php -l app/Services/Dashboard/BranchCustomers.php
php -l app/Services/Dashboard/DeliveryCompanies.php
php -l app/Services/Dashboard/PhoneDelivery.php
php -l app/Services/Dashboard/PhoneMapProvider.php
php -l app/Http/Controllers/Dashboard/BranchOperationsController.php
php -l app/Http/Controllers/Dashboard/BranchExpensesController.php
php -l app/Http/Controllers/Dashboard/PrintSettingsController.php
php -l app/Services/Dashboard/PosServicePhone.php
php -l app/Services/Dashboard/PosBranchPrinting.php
php -l app/Services/Dashboard/TakeawayAccess.php
php -l app/Services/Dashboard/TakeawayCatalog.php
php -l app/Services/Dashboard/TakeawayService.php
php -l app/Services/Dashboard/OrderProviderDelivery.php
php -l app/Services/Dashboard/DashboardPushSender.php
php -l app/Http/Traits/FcmFirebase.php
php artisan migrate --force --path=database/migrations/2026_10_03_060000_create_order_board_clocks.php
php artisan migrate --force --path=database/migrations/2026_10_03_140000_create_takeaway_pos.php
php artisan migrate --force --path=database/migrations/2026_10_03_150000_create_pos_service_tickets.php
php artisan migrate --force --path=database/migrations/2026_10_04_000001_create_pos_branch_print_jobs.php
php artisan migrate --force --path=database/migrations/2026_10_04_030000_create_branch_expenses.php
php artisan migrate --force --path=database/migrations/2026_10_04_060000_lock_pos_service_bills.php
php artisan migrate --force --path=database/migrations/2026_10_04_080000_create_branch_operations.php
php artisan migrate --force --path=database/migrations/2026_10_04_190000_create_branch_stock.php
php artisan migrate --force --path=database/migrations/2026_10_04_210000_create_branch_inventory_recipes.php
php artisan migrate --force --path=database/migrations/2026_10_04_100000_create_branch_shift_closings.php
# Preserve the existing cache mode: legacy controllers read env() directly.
if test "$config_cached" = 1; then php artisan config:cache; else php artisan config:clear; fi
php artisan route:clear
php artisan view:clear
php artisan view:cache
test -s public/dashboard/branding/fasakhansta-logo.png
test -s public/dashboard/js/order-board.js
test -s public/dashboard/js/order-board-menu.js
test -s public/dashboard/js/dashboard-navigation.js
test -s public/dashboard/js/dashboard-spa.js
test -s public/dashboard/css/dashboard-spa.css
test -s public/dashboard/js/dashboard-inbox.js
test -s public/dashboard/js/dashboard-support-chat.js
test -s public/dashboard/js/dashboard-print.js
test -s public/dashboard/js/dashboard-invoice-details.js
test -s public/dashboard/js/dashboard-location-picker.js
test -s public/dashboard/js/branch-shifts.js
test -s public/dashboard/css/branch-shifts.css
test -s public/dashboard/js/branch-stock.js
test -s public/dashboard/js/branch-recipes.js
test -s public/dashboard/js/home-overview.js
test -s public/dashboard/css/home-overview.css
test -s public/dashboard/css/branch-stock.css
test -s public/dashboard/js/branch-operations.js
test -s public/dashboard/css/branch-operations.css
test -s public/dashboard/vendor/leaflet/leaflet.js
test -s public/dashboard/vendor/leaflet/leaflet.css
test -s public/dashboard/js/takeaway-pos.js
test -s public/dashboard/css/takeaway-pos.css
test -s public/dashboard/js/dining-pos.js
test -s public/dashboard/css/dining-pos.css
test -s public/dashboard/js/phone-orders.js
test -s public/dashboard/js/phone-address-search.js
test -s public/dashboard/js/branch-print-receiver.js
test -s public/dashboard/css/phone-orders.css
test -s public/dashboard/css/branch-orders.css
test -s public/dashboard/branding/fasakhansta-logo-transparent.png
php artisan order-board:advance --help >/dev/null
test -s public/dashboard/js/branch-expenses.js
test -s public/dashboard/js/print-settings.js
php deployment/check_dashboard_runtime.php
trap - ERR
echo "ORDER BOARD READY: $release_sha"
echo "Previous code snapshot: $backup_name"
# Only additive clock/POS/service-ticket/expense migrations are installed; no historical order backfill,
# payment settings changes, customer releases, or destructive schema rollback.
