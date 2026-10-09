#!/usr/bin/env bash
set -euo pipefail
# Apply the reviewed code-only rollover release, retaining every attendance row.
test "$(id -u)" = 0 || { echo 'Run this wrapper as root.'; exit 1; }
release="${1:?Pass the reviewed 40-character release SHA}"
branch="${2:-codex/employee-attendance-live-20261008}"
[[ "$release" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release SHA'; exit 1; }
case "$branch" in
    codex/employee-attendance-live-20261008|codex/full-dashboard-refresh-20261008) ;;
    *) echo 'Use the reviewed attendance or dashboard release branch.'; exit 1 ;;
esac
project=/home/fasakha/public_html
owner=fasakha
test -d "$project/.git"
test ! -L "$project" && test ! -L "$project/.git"
test "$(realpath "$project")" = "$project"
test -f "$project/vendor/autoload.php" && test -f "$project/.env"
id "$owner" >/dev/null
runuser -u "$owner" -- git -C "$project" fetch --no-tags --no-prune --no-recurse-submodules --refmap= origin "refs/heads/$branch"
test "$(runuser -u "$owner" -- git -C "$project" rev-parse FETCH_HEAD)" = "$release" || { echo 'Release changed; no source changed.'; exit 1; }
previous="$(runuser -u "$owner" -- git -C "$project" rev-parse HEAD)"
runuser -u "$owner" -- git -C "$project" merge-base --is-ancestor "$previous" "$release" || { echo 'Server version diverged; no source changed.'; exit 1; }
test -z "$(runuser -u "$owner" -- git -C "$project" status --porcelain --untracked-files=no)" || { echo 'Save local tracked changes first.'; exit 1; }
paths=()
while IFS= read -r -d '' relative; do
    case "$relative" in
        public/dashboard/js/branch-operations.js|resources/views/admin/branch_operations/index.blade.php|tests/browser/employee-attendance-rollover.cjs|tests/desktop_pos_runtime/attendance.php|deployment/apply_attendance_rollover_root.sh) ;;
        *) echo "Server is behind the reviewed rollover baseline: $relative. No source changed."; exit 1 ;;
    esac
    target="$project/$relative"
    test ! -L "$target"
    if test -e "$target"; then
        test -f "$target"
        runuser -u "$owner" -- git -C "$project" ls-files --error-unmatch -- "$relative" >/dev/null
        paths+=("$target")
    fi
    parent="$(dirname "$target")"
    while [[ "$parent" == "$project" || "$parent" == "$project/"* ]]; do
        test ! -L "$parent"
        if test -e "$parent"; then test -d "$parent"; paths+=("$parent"); fi
        test "$parent" != "$project" || break
        parent="$(dirname "$parent")"
    done
done < <(runuser -u "$owner" -- git -C "$project" diff --name-only -z "$previous" "$release")
for relative in bootstrap/cache storage/framework/views; do
    target="$project/$relative"
    test -d "$target" && test ! -L "$target"
    test "$(realpath "$target")" = "$target"
    paths+=("$target")
done
if test "$previous" != "$release"; then
    backup="backup/before-attendance-rollover-$(date -u +%Y%m%dT%H%M%S%NZ)-${previous:0:8}"
    runuser -u "$owner" -- git -C "$project" branch "$backup" "$previous"
    echo "Saved code checkpoint: $backup ($previous)"
fi
for target in "${paths[@]}"; do
    chown --no-dereference "$owner:$owner" "$target"
    if test -d "$target"; then chmod u+rwx "$target"; else chmod u+rw "$target"; fi
done
runuser -u "$owner" -- bash -s -- "$project" "$release" <<'APP_OWNER'
set -euo pipefail
cd "$1"
git merge --ff-only "$2"
php artisan view:clear
php artisan tinker --execute='if (\App\Services\Dashboard\OperatingDay::START_HOUR !== 6) { throw new \RuntimeException("Operating day cutoff missing"); } echo "ATTENDANCE ROLLOVER READY: ".\App\Services\Dashboard\OperatingDay::date()." (06:00 to 06:00 Cairo)".PHP_EOL;'
APP_OWNER
