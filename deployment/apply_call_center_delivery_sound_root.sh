#!/usr/bin/env bash
set -Eeuo pipefail
# Apply only the five reviewed notification files, preserving the server's branch/history.
test "$(id -u)" = 0 || { echo 'Run as root.' >&2; exit 1; }
release="${1:?Pass the reviewed release SHA}"
project="${2:-/home/fasakha/public_html}"
owner="${3:-fasakha}"
[[ "$release" =~ ^[0-9a-f]{40}$ && "$project" = /* && "$owner" =~ ^[a-z_][a-z0-9_-]*$ ]]
test "$(realpath "$project")" = "$project" && test ! -L "$project" && test ! -L "$project/.git"
test -d "$project/.git" && test -f "$project/artisan" && test -f "$project/vendor/autoload.php" && test -f "$project/.env"
test "$(id -u "$owner")" != 0
php_binary="$(command -v php)"
git_owner() { runuser -u "$owner" -- git -C "$project" "$@"; }
artisan_owner() { runuser -u "$owner" -- bash -c 'cd "$1"; "$2" artisan "$3"' _ "$project" "$php_binary" "$1"; }
exec 9>"$(dirname "$project")/.delivery-sound-release.lock"
flock -n 9 || { echo 'Another delivery sound update is running.' >&2; exit 1; }
test -z "$(git_owner status --porcelain --untracked-files=no)" || { echo 'Save tracked server changes first; nothing changed.' >&2; exit 1; }
git_owner fetch --no-tags --no-prune --no-recurse-submodules --refmap= origin refs/heads/codex/call-center-delivery-sound-20261009
test "$(git_owner rev-parse FETCH_HEAD)" = "$release" || { echo 'Reviewed release changed; nothing changed.' >&2; exit 1; }
paths=(app/Services/Dashboard/PosBranchPrinting.php public/dashboard/js/branch-print-receiver.js resources/lang/ar/phone_orders.php resources/lang/en/phone_orders.php resources/views/admin/pos_service/receiver.blade.php)
installed=0
for relative in "${paths[@]}"; do
    target="$project/$relative"
    test -f "$target" && test ! -L "$target" && test "$(realpath "$target")" = "$target"
    current="$(git_owner hash-object "$target")"
    wanted="$(git_owner rev-parse "$release:$relative")"
    original="$(git_owner rev-parse "$release^:$relative")"
    if test "$current" = "$wanted"; then installed=$((installed+1))
    else test "$current" = "$original" || { echo "Source differs: $relative; nothing changed." >&2; exit 1; }; fi
done
if test "$installed" = "${#paths[@]}"; then artisan_owner view:clear; echo 'DELIVERY SOUND READY (already installed)'; exit 0; fi
test "$installed" = 0 || { echo 'Mixed source versions; nothing changed.' >&2; exit 1; }
runuser -u "$owner" -- bash -c 'cd "$1"; "$2" artisan tinker --execute='\''if (!\Illuminate\Support\Facades\Schema::hasTable("pos_branch_print_jobs") || !\Illuminate\Support\Facades\Schema::hasColumn("users", "owner_resturant_id") || !\Illuminate\Support\Facades\Route::has("phone-orders.print-jobs")) { throw new \RuntimeException("Branch receiver is not installed"); } echo "BRANCH RECEIVER VERIFIED".PHP_EOL;'\''' _ "$project" "$php_binary"
work="$(mktemp -d /tmp/delivery-sound.XXXXXXXX)"
chown "$owner:$owner" "$work"
trap 'rm -rf "$work"' EXIT
git_owner diff --binary "$release^" "$release" -- "${paths[@]}" > "$work/update.patch"
chown "$owner:$owner" "$work/update.patch"
git_owner apply --check "$work/update.patch"
previous="$(git_owner rev-parse HEAD)"
checkpoint="backup/before-delivery-sound-$(date -u +%Y%m%dT%H%M%S%N)-${previous:0:8}"
git_owner branch "$checkpoint" "$previous"
echo "Saved code checkpoint: $checkpoint ($previous)"
for relative in "${paths[@]}"; do
    target="$project/$relative"
    chown --no-dereference "$owner:$owner" "$target"; chmod u+rw "$target"
    parent="$(dirname "$target")"
    chown --no-dereference "$owner:$owner" "$parent"; chmod u+rwx "$parent"
done
changed=1
rollback() {
    failure=$?; trap - ERR
    if test "$changed" = 1; then
        git_owner restore --source="$previous" --staged --worktree -- "${paths[@]}" || echo 'Check the saved checkpoint: restoration failed.' >&2
        artisan_owner view:clear || true
    fi
    echo "Delivery sound activation failed. Previous source checkpoint: $checkpoint" >&2
    exit "$failure"
}
trap rollback ERR
git_owner apply "$work/update.patch"
for relative in app/Services/Dashboard/PosBranchPrinting.php resources/lang/ar/phone_orders.php resources/lang/en/phone_orders.php; do
    runuser -u "$owner" -- "$php_binary" -l "$project/$relative"
done
for relative in "${paths[@]}"; do
    test "$(git_owner hash-object "$project/$relative")" = "$(git_owner rev-parse "$release:$relative")"
done
artisan_owner view:clear
git_owner add -- "${paths[@]}"
git_owner -c user.name=Codex -c user.email=codex@openai.com commit -m 'Enable branch delivery arrival sound for call-center orders'
changed=0
trap - ERR
echo 'DELIVERY SOUND READY'
