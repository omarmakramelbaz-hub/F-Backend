#!/usr/bin/env bash
# Five-file ERP role update. Run locally as root or the non-root .env owner.
# Usage: bash erp_update_roles.sh apply /absolute/application/root
#        bash erp_update_roles.sh rollback /absolute/application/root /absolute/receipt-directory
# The Git checkout deliberately remains at BASE with five modified working files.
# No fetch, reset, clean, migrations, database operations, environment or ACL edits.
set -Eeuo pipefail
umask 077
export LC_ALL=C
readonly BASE=20ea8dde5ab8c934a4e0b87b92841f9ee054c340
readonly TARGET=02bcc205fb86402b2f10510bb526a33c7ae1b3e7
readonly -a FILES=(
  app/Http/Controllers/Erp/AuthController.php
  app/Services/Erp/Actor.php
  resources/views/erp/account-form.blade.php
  resources/views/erp/accounts.blade.php
  resources/views/erp/layout.blade.php
)
readonly -a BEFORE=(
  ed72c9cf16fc96969633e1469333f56fc7e696e103b829d4bba2ae28630c40e8
  08f09070fb30841fd8854e44a9484507c29fc3d65ee40cefbb825bcf4f14cad3
  c6f4849b5618affa954634e01f84b7c9156fbd726503309590af16d8bf5131d8
  56d80455d6b35aa14c7f29277b96d20bd0a114260e48a75673cb0278e0217e80
  f38301516fad18aca4d63176c9eca6843cb2ffadec78e3451bfcc706219f6238
)
readonly -a AFTER=(
  cb1a53d9154a3d2f6fc9870c3ae9edf11faff224081ef579ca7789465c7e4318
  738791c192aea58730b5eac585effbf5d0ffc6d7d376e0e98174eb3600ea576b
  5093b1ca5f5eb020e309234fe9a355e515f5784a9eb70fa84d06b8d2bcde9085
  714f6b18710c942956aad192924bd6db0fb381f88e3ed8d2663e505c34f429ed
  09a1c96fbec80b662e9fd09b5e42e66d20123f60f4c3193b19acb182e34ee62f
)
stop() { echo "STOP: $*" >&2; exit 1; }
[[ $# == 2 || $# == 3 ]] || stop 'Expected apply ROOT or rollback ROOT RECEIPT_DIRECTORY.'
action=$1; root=$2; run=${3:-}
[[ $action == apply && $# == 2 || $action == rollback && $# == 3 ]] || stop 'Invalid action or arguments.'
for tool in git php flock realpath stat sha256sum cp mv mktemp; do
  command -v "$tool" >/dev/null || stop "Missing required tool: $tool"
done
# Canonical spelling and an lstat-style walk reject symlinks at every component,
# including the root itself and any ancestor. Missing final components are allowed.
safe_path() {
  local p=$1 part current=''
  [[ $p == /* && $p != *$'\n'* && $p != *$'\r'* && $(realpath -m -- "$p") == "$p" ]] || return 1
  IFS=/ read -r -a parts <<< "$p"
  for part in "${parts[@]}"; do
    [[ -n $part ]] || continue
    current+="/$part"
    [[ ! -L $current ]] || return 1
  done
}
regular() { safe_path "$1" && [[ -f $1 && $(stat -c %h -- "$1") == 1 ]]; }
hash() { local result; result=$(sha256sum -- "$1") || return; printf '%s\n' "${result%% *}"; }
metadata() { stat -c '%u:%g:%a:%y' -- "$1"; }
safe_path "$root" && [[ -d $root && $root != / ]] || stop 'Unsafe application root.'
cd -- "$root"
# Do not inherit alternate Git work trees/indexes or trigger fsmonitor/hooks.
for key in GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_CONFIG_COUNT; do
  [[ ! -v $key ]] || stop "Unset $key before running this operator script."
done
git_read() { git --no-optional-locks -c safe.directory="$root" -c core.fsmonitor=false -c diff.autoRefreshIndex=false -c core.hooksPath=/dev/null "$@"; }
[[ -d .git ]] && safe_path "$root/.git" && regular "$root/.git/index" && regular "$root/.git/HEAD" || stop 'A plain, nonsymlink Git checkout is required.'
[[ $(git_read rev-parse --show-toplevel) == "$root" ]] || stop 'Application root must be the Git worktree root.'
for p in .env artisan vendor/autoload.php; do regular "$root/$p" || stop "Unsafe or missing $p"; done
for p in storage/framework storage/framework/views bootstrap/cache; do
  safe_path "$root/$p" && [[ -d $root/$p ]] || stop "Unsafe or missing $p"
done
app_uid=$(stat -c %u -- .env)
app_user=$(stat -c %U -- .env)
[[ $app_uid != 0 && $app_user != UNKNOWN && ($EUID == 0 || $EUID == "$app_uid") ]] || stop 'Run as root or the non-root .env owner.'
if [[ $EUID == 0 ]]; then command -v runuser >/dev/null || stop 'Root execution needs runuser.'; fi
# Only artisan runs under the application identity. It never needs private backup access.
artisan() {
  if [[ $EUID == 0 ]]; then runuser -u "$app_user" -- php artisan "$@" >>"$run/private.log" 2>&1
  else php artisan "$@" >>"$run/private.log" 2>&1; fi
}
check_checkout() {
  [[ $(git_read rev-parse HEAD) == "$BASE" ]] || return 1
  git_read diff --cached --no-ext-diff --quiet "$BASE" -- || return 1
  # Ignore flags could otherwise hide local changes from the cleanliness check.
  local entries
  entries=$(git_read ls-files -v) || return 1
  [[ ! $entries =~ (^|$'\n')[a-zS] ]] || return 1
  local status line file allowed
  status=$(git_read status --porcelain --untracked-files=no) || return 1
  if [[ $action == apply ]]; then
    [[ -z $status ]] || return 1
  elif [[ -n $status ]]; then
    while IFS= read -r line; do
      allowed=0
      for file in "${FILES[@]}"; do [[ $line != " M $file" ]] || allowed=1; done
      [[ $allowed == 1 ]] || return 1
    done <<< "$status"
  fi
}
check_files() {
  local kind=$1 i expected
  for i in "${!FILES[@]}"; do
    regular "$root/${FILES[i]}" || return 1
    expected=${BEFORE[i]}; [[ $kind != after ]] || expected=${AFTER[i]}
    [[ $(hash "$root/${FILES[i]}") == "$expected" ]] || return 1
  done
}
check_checkout || stop 'Wrong HEAD, staged changes, hidden files, or unrelated tracked edits; nothing overwritten.'
check_files "$([[ $action == apply ]] && echo before || echo after)" || stop 'The five files do not match the exact expected hashes or safe file types.'
[[ ! -e storage/framework/down && ! -L storage/framework/down && ! -e storage/framework/maintenance.php && ! -L storage/framework/maintenance.php ]] || stop 'Application is already in maintenance; left unchanged.'

parent=$(dirname -- "$root")
backups="$parent/erp-role-backups"
safe_path "$backups" || stop 'Unsafe backup parent.'
if [[ ! -e $backups ]]; then mkdir -m 700 -- "$backups"; fi
[[ -d $backups && $(stat -c %u:%a -- "$backups") == "$EUID:700" ]] || stop 'Backup parent must be owned by this operator and mode 700.'
lock="$backups/deployment.lock"
safe_path "$lock" || stop 'Unsafe deployment lock path.'
[[ ! -e $lock && ! -L $lock ]] || { regular "$lock" && [[ $(stat -c %u:%a -- "$lock") == "$EUID:600" ]]; } || stop 'Unsafe deployment lock.'
exec 9>>"$lock"
flock -n 9 || stop 'Another role update or rollback is running.'
check_checkout && check_files "$([[ $action == apply ]] && echo before || echo after)" || stop 'Checkout changed before lock acquisition.'
[[ ! -e storage/framework/down && ! -L storage/framework/down ]] || stop 'Maintenance started concurrently.'

git_read cat-file -e "$BASE^{commit}"
git_read cat-file -e "$TARGET^{commit}"
if [[ $action == apply ]]; then
  run=$(mktemp -d "$backups/roles-$(date -u +%Y%m%dT%H%M%S)-XXXXXX")
  mkdir -m 700 -- "$run/original" "$run/target"
  printf '%s\n' "$root" > "$run/root"
  printf '%s\n' "$BASE" > "$run/base"
  printf '%s\n' "$TARGET" > "$run/target-commit"
  hash .env > "$run/environment.sha256"
  metadata .env > "$run/environment.metadata"
  hash .git/index > "$run/index.sha256"
  hash .git/HEAD > "$run/head.sha256"
  metadata .git/HEAD > "$run/head.metadata"
  : > "$run/original.metadata"
  for i in "${!FILES[@]}"; do
    p=${FILES[i]}
    cp --preserve=all -- "$root/$p" "$run/original/$i"
    [[ $(hash "$run/original/$i") == "${BEFORE[i]}" && $(metadata "$run/original/$i") == "$(metadata "$root/$p")" ]] || stop 'Backup content or metadata mismatch.'
    metadata "$run/original/$i" >> "$run/original.metadata"
    cp --preserve=all -- "$run/original/$i" "$run/target/$i"
    git_read show "$TARGET:$p" > "$run/target/$i"
    [[ $(hash "$run/target/$i") == "${AFTER[i]}" ]] || stop 'Pinned target content mismatch.'
    php -l "$run/target/$i" >> "$run/private.log" 2>&1
  done
  printf 'prepared\n' > "$run/state"
else
  safe_path "$run" && [[ $run == "$backups/roles-"* && ${run#"$backups/"} != */* && -d $run && $(stat -c %u:%a -- "$run") == "$EUID:700" ]] || stop 'Unsafe or foreign receipt directory.'
  for p in root base target-commit environment.sha256 environment.metadata index.sha256 head.sha256 head.metadata original.metadata state private.log; do
    regular "$run/$p" && [[ $(stat -c %u -- "$run/$p") == "$EUID" ]] || stop 'Unsafe or incomplete receipt.'
  done
  [[ $(cat "$run/root") == "$root" && $(cat "$run/base") == "$BASE" && $(cat "$run/target-commit") == "$TARGET" && $(cat "$run/state") == applied ]] || stop 'Stale or mismatched receipt.'
  mapfile -t original_metadata < "$run/original.metadata"
  [[ ${#original_metadata[@]} == ${#FILES[@]} ]] || stop 'Incomplete backup metadata.'
  for i in "${!FILES[@]}"; do
    regular "$run/original/$i" && [[ $(hash "$run/original/$i") == "${BEFORE[i]}" && $(metadata "$run/original/$i") == "${original_metadata[i]}" ]] || stop 'Backup content or metadata was changed.'
    regular "$run/target/$i" && [[ $(hash "$run/target/$i") == "${AFTER[i]}" && $(metadata "$root/${FILES[i]}") == "$(metadata "$run/target/$i")" ]] || stop 'Deployed file metadata or target receipt changed.'
    php -l "$run/original/$i" >> "$run/private.log" 2>&1
  done
fi
printf 'RECEIPT_DIRECTORY=%s\n' "$run"
unchanged_context() {
  regular "$root/.env" && regular "$root/.git/index" && regular "$root/.git/HEAD" && check_checkout && [[ $(hash .git/HEAD) == "$(cat "$run/head.sha256")" && $(metadata .git/HEAD) == "$(cat "$run/head.metadata")" && $(hash .git/index) == "$(cat "$run/index.sha256")" && $(hash .env) == "$(cat "$run/environment.sha256")" && $(metadata .env) == "$(cat "$run/environment.metadata")" ]]
}
# This check is also used after writing the five files, when their intentional
# working-tree differences must be excluded without changing HEAD or the index.
unchanged_after() { local action=rollback; unchanged_context; }
unchanged_context || stop 'Git index, environment, or checkout changed since backup.'
check_files "$([[ $action == apply ]] && echo before || echo after)" || stop 'Files changed during staging.'
[[ ! -e storage/framework/down && ! -L storage/framework/down ]] || stop 'Maintenance started during staging.'

mapfile -t original_metadata < "$run/original.metadata"
prior_state=$(cat "$run/state")
check_payload() {
  local kind=$1 i source
  check_files "$kind" || return 1
  for i in "${!FILES[@]}"; do
    source="$run/original/$i"; [[ $kind != after ]] || source="$run/target/$i"
    regular "$source" && [[ $(metadata "$root/${FILES[i]}") == "$(metadata "$source")" ]] || return 1
  done
}
maintenance=0; writes=0; success=0; temp=''; phase=maintenance
# A same-directory temporary plus rename avoids partial PHP files. cp retains
# owner, mode, timestamps and extended attributes. No existing untracked path is used.
replace_file() {
  local source=$1 destination=$2
  regular "$source" && regular "$destination" || return 1
  temp=$(mktemp "$(dirname -- "$destination")/.erp-role-update-XXXXXX") || return 1
  cp --preserve=all -- "$source" "$temp" || return 1
  [[ $(hash "$temp") == "$(hash "$source")" && $(metadata "$temp") == "$(metadata "$source")" ]] || return 1
  regular "$destination" || return 1
  mv -fT -- "$temp" "$destination" || return 1
  temp=''
}
restore_originals() {
  local i current
  # Never overwrite an intervening edit, even during automatic recovery.
  for i in "${!FILES[@]}"; do
    regular "$root/${FILES[i]}" && regular "$run/original/$i" || return 1
    current=$(hash "$root/${FILES[i]}") || return 1
    [[ $current == "${BEFORE[i]}" || $current == "${AFTER[i]}" ]] || return 1
    [[ $(hash "$run/original/$i") == "${BEFORE[i]}" && $(metadata "$run/original/$i") == "${original_metadata[i]}" ]] || return 1
    if [[ $current == "${BEFORE[i]}" ]]; then
      [[ $(metadata "$root/${FILES[i]}") == "${original_metadata[i]}" ]] || return 1
    else
      regular "$run/target/$i" && [[ $(hash "$run/target/$i") == "${AFTER[i]}" && $(metadata "$root/${FILES[i]}") == "$(metadata "$run/target/$i")" ]] || return 1
    fi
  done
  for i in "${!FILES[@]}"; do replace_file "$run/original/$i" "$root/${FILES[i]}" || return 1; done
  check_files before
}
keep_maintenance() {
  if regular "$root/storage/framework/down"; then return 0; fi
  [[ ! -e $root/storage/framework/down && ! -L $root/storage/framework/down ]] || return 1
  if regular "$run/maintenance.down"; then
    cp --preserve=all -- "$run/maintenance.down" "$root/storage/framework/down" || return 1
  else artisan down --no-interaction || return 1; fi
  regular "$root/storage/framework/down"
}
finish() {
  local code=$? recovered=1 expected=before
  if [[ $writes == 0 && $action == rollback ]]; then expected=after; fi
  trap - EXIT INT TERM HUP
  set +e
  [[ -z $temp ]] || rm -f -- "$temp"
  [[ $success == 0 ]] || return 0
  echo "ERP_ROLE_UPDATE_STOPPED_AT=$phase" >&2
  if [[ $writes == 1 ]]; then
    # Resume can fail after removing the marker. Never recover code in traffic.
    keep_maintenance && unchanged_after || recovered=0
    if [[ $recovered == 1 ]]; then restore_originals || recovered=0; fi
  fi
  [[ -z $temp ]] || rm -f -- "$temp"
  if [[ $maintenance == 1 ]]; then
    if [[ $recovered == 1 && $writes == 1 ]]; then artisan view:clear --no-interaction || recovered=0; fi
    if [[ $recovered == 1 ]]; then
      unchanged_after && check_payload "$expected" || recovered=0
      if [[ $recovered == 1 ]]; then artisan up --no-interaction || recovered=0; fi
      [[ ! -e storage/framework/down && ! -L storage/framework/down ]] || recovered=0
      unchanged_after && check_payload "$expected" || recovered=0
    fi
    if [[ $recovered == 0 ]]; then
      keep_maintenance || echo 'CRITICAL: MAINTENANCE COULD NOT BE VERIFIED; REMOVE APPLICATION FROM TRAFFIC.' >&2
      printf 'manual-intervention\n' > "$run/state"
      echo 'MANUAL_INTERVENTION_REQUIRED; MAINTENANCE_RETAINED_WHEN_POSSIBLE; DO_NOT_RETRY_OR_RESTORE_DATABASE.' >&2
    else
      if [[ $writes == 1 ]]; then
        printf 'recovered\n' > "$run/state"
        echo 'ORIGINAL_FIVE_FILES_RESTORED; APPLICATION_OUT_OF_MAINTENANCE.' >&2
      else
        printf '%s\n' "$prior_state" > "$run/state"
        echo 'DEPLOYED_FILES_UNCHANGED; APPLICATION_OUT_OF_MAINTENANCE.' >&2
      fi
    fi
  fi
  printf 'RECEIPT_DIRECTORY=%s\n' "$run" >&2
  [[ $code != 0 ]] || code=1
  exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
maintenance=1
artisan down --no-interaction
regular "$root/storage/framework/down" || stop 'Maintenance command did not create its expected file.'
cp --preserve=all -- "$root/storage/framework/down" "$run/maintenance.down"
phase=pre-copy-verification
unchanged_context && check_payload "$([[ $action == apply ]] && echo before || echo after)" || stop 'Checkout or environment changed during maintenance entry.'
phase=copy
writes=1
if [[ $action == apply ]]; then
  for i in "${!FILES[@]}"; do
    unchanged_after || stop 'Checkout or environment changed during deployment.'
    [[ $(hash "$root/${FILES[i]}") == "${BEFORE[i]}" && $(metadata "$root/${FILES[i]}") == "${original_metadata[i]}" ]] || stop 'File changed during deployment.'
    replace_file "$run/target/$i" "$root/${FILES[i]}"
  done
  check_files after
else
  restore_originals
fi
phase=view-cache
artisan view:clear --no-interaction
phase=verification
unchanged_after && check_payload "$([[ $action == apply ]] && echo after || echo before)" || stop 'Unexpected Git, tracked-file, or environment change.'
phase=resume
artisan up --no-interaction
[[ ! -e storage/framework/down && ! -L storage/framework/down ]] || stop 'Maintenance did not end.'
unchanged_after && check_payload "$([[ $action == apply ]] && echo after || echo before)" || stop 'Final checkout verification failed.'
if [[ $action == apply ]]; then
  printf 'applied\n' > "$run/state"
  echo "ERP_ROLE_FIVE_FILE_OVERLAY_APPLIED; GIT_HEAD_REMAINS=$BASE; CONTENT_SOURCE=$TARGET"
  printf 'ROLLBACK_COMMAND=bash %q rollback %q %q\n' "$0" "$root" "$run"
else
  printf 'rolled-back\n' > "$run/state"
  echo 'ERP_ROLE_ORIGINAL_FIVE_FILES_RESTORED; GIT_HEAD_AND_INDEX_UNCHANGED.'
fi
success=1
