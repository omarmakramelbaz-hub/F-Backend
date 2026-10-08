#!/usr/bin/env bash
set -euo pipefail
# Bootstrap from the pinned release; source edits and Laravel run as the app owner.
test "$(id -u)" = 0 || { echo 'Run this wrapper as root.'; exit 1; }
release="${1:?Pass the reviewed attendance release SHA}"
[[ "$release" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid release SHA'; exit 1; }
project=/home/fasakha/public_html
owner=fasakha
test -d "$project/.git"
test ! -L "$project" && test ! -L "$project/.git"
test "$(realpath "$project")" = "$project"
id "$owner" >/dev/null
runuser -u "$owner" -- git -C "$project" fetch --no-tags --no-prune --no-recurse-submodules --refmap= origin refs/heads/codex/employee-attendance-live-20261008
test "$(runuser -u "$owner" -- git -C "$project" rev-parse FETCH_HEAD)" = "$release"
previous="$(runuser -u "$owner" -- git -C "$project" rev-parse HEAD)"
test "$previous" = cedd72355a853d99ea0152f0e4091e3b59716126 || test "$previous" = "$release" || { echo 'Server checkpoint changed; no source changed.'; exit 1; }
test -z "$(runuser -u "$owner" -- git -C "$project" status --porcelain --untracked-files=no)" || { echo 'Save local tracked changes first.'; exit 1; }
# Validate all reviewed source paths before repairing ownership of source files only.
paths=()
while IFS= read -r -d '' relative; do
    [[ "$relative" != /* && "$relative" != *'..'* ]] || exit 1
    target="$project/$relative"
    test ! -L "$target"
    if test -e "$target"; then
        test -f "$target" && test ! -L "$target"
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
for target in "${paths[@]}"; do
    chown --no-dereference "$owner:$owner" "$target"
    if test -d "$target"; then chmod u+rwx "$target"; else chmod u+rw "$target"; fi
done
installer_directory="$(mktemp -d)"
trap 'rm -rf -- "$installer_directory"' EXIT
runuser -u "$owner" -- git -C "$project" show "$release:deployment/apply_employee_attendance.sh" > "$installer_directory/apply.sh"
chown "$owner:$owner" "$installer_directory" "$installer_directory/apply.sh"
chmod 700 "$installer_directory"
chmod 600 "$installer_directory/apply.sh"
runuser -u "$owner" -- bash -s -- "$project" "$installer_directory/apply.sh" "$release" <<'APP_OWNER'
set -euo pipefail
cd "$1"
bash "$2" "$3"
APP_OWNER
