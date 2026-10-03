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

rollback() {
    local failed_status=$?
    trap - ERR
    git reset --hard "$current_sha"
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
php artisan route:clear
php artisan view:clear
php artisan view:cache
test -s public/dashboard/branding/fasakhansta-logo.png
test -s public/dashboard/js/order-board.js
test -s public/dashboard/js/order-board-menu.js
test -s public/dashboard/js/dashboard-navigation.js
test -s public/dashboard/branding/fasakhansta-logo-transparent.png
trap - ERR
echo "ORDER BOARD READY: $release_sha"
echo "Previous code snapshot: $backup_name"
# No migrations, settings changes, customer application releases, or database rollback.
