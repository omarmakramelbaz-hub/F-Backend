#!/usr/bin/env bash
# Authorized operator console, as the application owner. Recovery only.
# Never restores a database, changes file permissions, or updates Git refs.
set -Eeuo pipefail
umask 077
export LC_ALL=C GIT_OPTIONAL_LOCKS=0
unset TAR_OPTIONS GZIP POSIXLY_CORRECT
root=${1:?application root required}
base=${2:?previous reviewed commit required}
target=${3:?failed release commit required}
previous_run=${4:?failed release directory required}
[[ $EUID -ne 0 && $base =~ ^[0-9a-f]{40}$ && $target =~ ^[0-9a-f]{40}$ ]] || { echo 'STOP: run as the application owner with explicit commit IDs.'; exit 1; }
[[ $(realpath "$root") == "$root" && $(realpath "$previous_run") == "$previous_run" && $previous_run == "$(dirname "$root")/erp-install-"* ]] || { echo 'STOP: unexpected paths.'; exit 1; }
cd "$root"
for tool in git php tar flock curl sha256sum; do command -v "$tool" >/dev/null || { echo "STOP: missing $tool"; exit 1; }; done
[[ -d .git && ! -L .git && -f .env && ! -L .env && -f "$previous_run/baseline.json" && -f "$previous_run/url" ]] || { echo 'STOP: application or prior release evidence missing.'; exit 1; }
[[ $(git rev-parse --show-toplevel) == "$root" && $(git rev-parse HEAD) == "$base" ]] || { echo 'STOP: HEAD changed; no recovery attempted.'; exit 1; }
[[ ! -e .git/index.lock && ! -e .git/HEAD.lock && ! -e storage/framework/down ]] || { echo 'STOP: a lock or maintenance marker already exists; review first.'; exit 1; }
exec 9>"$(dirname "$root")/.erp-install.lock"
flock -n 9 || { echo 'STOP: another ERP operation is active.'; exit 1; }
git cat-file -e "$target^{commit}"
git merge-base --is-ancestor "$base" "$target"
# The reported failure wrote the full target index but could not update HEAD.
# Do not generalize this repair to a different partial checkout or local edits.
git diff --cached --quiet "$target" -- || { echo 'STOP: index differs from the failed release; preserve and review.'; exit 1; }
[[ -z $(git ls-files --unmerged) ]] || { echo 'STOP: merge entries exist.'; exit 1; }
changed_text=$(git diff --no-renames --name-only "$base" "$target" --)
mapfile -t changed <<< "$changed_text"
[[ -n $changed_text ]] || { echo 'STOP: release has no changes.'; exit 1; }
declare -A allowed=()
present=(.git/HEAD .git/index .git/logs/HEAD)
absent=()
for path in "${changed[@]}"; do
    case "$path" in
      app/Http/Controllers/Erp/*|app/Http/Middleware/ErpAccess.php|app/Models/Erp/*|app/Services/Erp/*|app/Providers/RouteServiceProvider.php|config/auth.php|config/erp.php|resources/views/erp/*|resources/views/admin/layouts/menu.blade.php|routes/erp.php|public/erp-assets/*|docs/ERP_*|tests/erp_runtime/*|.devcontainer/*|.github/workflows/erp-tests.yml|deployment/erp_backup.php|deployment/erp_install.sh) ;;
      database/migrations/2026_09_30_180000_create_erp_foundation.php|database/migrations/2026_09_30_193000_create_erp_operations.php) ;;
      *) echo 'STOP: release contains a path outside ERP scope.'; exit 1;;
    esac
    [[ $(realpath -m -- "$path") == "$root/$path" ]] || { echo 'STOP: symbolic path requires review.'; exit 1; }
    entry=$(git ls-tree "$target" -- "$path")
    [[ $entry == 100644\ blob* || $entry == 100755\ blob* ]] || { echo 'STOP: unsupported release entry.'; exit 1; }
    allowed["$path"]=1
    if [[ -e "$path" || -L "$path" ]]; then
        [[ -f "$path" && ! -L "$path" && -r "$path" && -w "$(dirname "$path")" && -x "$(dirname "$path")" ]] || { echo "STOP: recovery access needs review: $path"; exit 1; }
        current=$(git hash-object --no-filters -- "$path")
        desired=$(git rev-parse "$target:$path")
        old=$(git rev-parse --verify "$base:$path" 2>/dev/null || true)
        [[ $current == "$desired" || $current == "$old" ]] || { echo "STOP: unrecognized content preserved: $path"; exit 1; }
        present+=("$path")
    else
        ! git cat-file -e "$base:$path" 2>/dev/null || { echo "STOP: an existing base file is missing: $path"; exit 1; }
        absent+=("$path")
    fi
done
unstaged=$(git diff --no-renames --name-only --)
while IFS= read -r path; do
    [[ -z $path || ${allowed[$path]+yes} == yes ]] || { echo 'STOP: unrelated local changes exist.'; exit 1; }
done <<< "$unstaged"
for path in .git/HEAD .git/index .git/logs/HEAD; do
    [[ -f $path && ! -L $path && -r $path ]] || { echo 'STOP: Git metadata cannot be safely preserved.'; exit 1; }
done
[[ $(realpath .git/logs) == "$root/.git/logs" ]] || { echo 'STOP: symbolic Git log path.'; exit 1; }
run=$(mktemp -d "$(dirname "$root")/erp-recovery-$(date -u +%Y%m%dT%H%M%S)-XXXXXX")
log="$run/private.log"
touch "$log"
printf 'RECOVERY_DIRECTORY=%s\n' "$run"
phase=preserve-partial-state
maintenance=0
finish() {
    code=$?
    trap - EXIT INT TERM HUP
    echo "RECOVERY_STOPPED_AT=$phase"
    if [[ $maintenance == 1 ]]; then echo 'MAINTENANCE_LEFT_FOR_OPERATOR_REVIEW'; fi
    printf 'PRIVATE_LOG=%s\n' "$log"
    [[ $code != 0 ]] || code=1
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
quiet() { "$@" >> "$log" 2>&1; }
printf '%s\0' "${present[@]}" > "$run/present.list"
printf '%s\0' "${changed[@]}" > "$run/restore.list"
git diff --binary --cached "$base" -- > "$run/index.patch"
git diff --binary -- > "$run/worktree.patch"
quiet tar --create --file="$run/partial-code.tar" --no-recursion --null --files-from="$run/present.list"
quiet tar --compare --file="$run/partial-code.tar"
sha256sum "$run/partial-code.tar" > "$run/SHA256SUMS"
env_before=$(sha256sum .env)
url=$(cat "$previous_run/url")
[[ $url == https://* && $url != *$'\n'* && $url != *$'\r'* ]] || { echo 'STOP: unexpected application URL.'; exit 1; }
echo 'PARTIAL_CODE_SAVED; NO_DATABASE_BACKUP_OR_RESTORE_RUN'
phase=maintenance
umask 022
quiet php artisan down --retry=60
maintenance=1
phase=restore-reviewed-files
# Recheck the target index immediately before the narrow restore.
quiet git diff --cached --quiet "$target" --
quiet tar --compare --file="$run/partial-code.tar"
for path in "${absent[@]}"; do [[ ! -e "$path" && ! -L "$path" ]]; done
quiet git -c core.hooksPath=/dev/null --literal-pathspecs restore --source="$base" --staged --worktree -- "${changed[@]}"
phase=verify-restored-files
[[ $(git rev-parse HEAD) == "$base" ]]
quiet git diff --cached --quiet "$base" --
quiet git diff --quiet --
[[ $(sha256sum .env) == "$env_before" ]]
for path in "${changed[@]}"; do
    if git cat-file -e "$base:$path" 2>/dev/null; then
        [[ $(git hash-object --no-filters -- "$path") == "$(git rev-parse "$base:$path")" ]]
    else
        [[ ! -e "$path" && ! -L "$path" ]]
    fi
done
echo 'PREVIOUS_TRACKED_CODE_AND_INDEX_VERIFIED'
phase=clear-code-caches
quiet php artisan config:clear
quiet php artisan route:clear
quiet php artisan view:clear
phase=reopen-application
quiet php artisan up
maintenance=0
[[ ! -e storage/framework/down ]]
echo 'MAINTENANCE_FILE_ABSENT'
phase=public-login-check
status=$(curl --silent --show-error --proto '=https' --connect-timeout 10 --max-time 30 -o /dev/null -w '%{http_code}' "${url%/}/admin/login" 2>>"$log")
printf 'ADMIN_LOGIN_HTTP=%s\n' "$status"
[[ $status == 200 ]]
[[ -z $(git --no-optional-locks status --porcelain --untracked-files=no) ]]
umask 077
printf 'base=%s\nfailed_target=%s\ncompleted_utc=%s\n' "$base" "$target" "$(date -u +%FT%TZ)" > "$run/recovery.ok"
trap - EXIT INT TERM HUP
echo 'PREVIOUS_CODE_RECOVERED; ERP_NOT_DEPLOYED; NO_DATABASE_RESTORE'
echo '=== Permission metadata for the blocked deployment paths ==='
for path in .github .github/workflows deployment docs .git .git/HEAD .git/logs .git/logs/HEAD; do
    if [[ -e "$path" || -L "$path" ]]; then
        stat -c '%A %U:%G %n' -- "$path"
        if [[ -w "$path" ]]; then echo 'APP_USER_WRITABLE=yes'; else echo 'APP_USER_WRITABLE=no'; fi
    fi
done
printf 'RECOVERY_RECEIPT=%s/recovery.ok\n' "$run"
echo 'DO_NOT_RETRY_THE_OLD_INSTALLER; PERMISSION_AND_ROLLBACK_GUARDS_NEED_REPAIR'
