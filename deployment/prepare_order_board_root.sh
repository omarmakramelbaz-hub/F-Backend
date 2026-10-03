#!/usr/bin/env bash
set -euo pipefail

# Repair only this release's paths, then run its reviewed installer as the app owner.
readonly project_path=/home/fasakha/public_html
readonly app_owner=fasakha
readonly base_sha=601ced3d0e074b2d942a076209e076ec3448cc09
readonly release_sha=7d6dafae472753774ec6c8ccecac15574e88e642
readonly previous_release_sha=26af5a54ec81c08295f339b34b47800016684dab

fail() { echo "$*" >&2; exit 1; }
as_owner() { runuser -u "$app_owner" -- "$@"; }
git_as_owner() { as_owner git -C "$project_path" "$@"; }

[[ "$(id -u)" = 0 ]] || fail 'Run this helper from the root terminal.'
cd -- "$project_path"
[[ "$(pwd -P)" = "$project_path" ]] || fail 'The checkout path contains a symlink.'
[[ -d .git && ! -L .git ]] || fail 'Expected a real .git directory.'
[[ "$(git_as_owner rev-parse HEAD)" = "$base_sha" ]] || fail 'Server HEAD changed; no repair applied.'
[[ -z "$(git_as_owner status --porcelain --untracked-files=no)" ]] || fail 'Tracked files changed; no repair applied.'
git_as_owner cat-file -e "$release_sha^{commit}"
git_as_owner cat-file -e "$previous_release_sha^{commit}"

temporary_dir="$(mktemp -d)"
trap 'rm -rf -- "$temporary_dir"' EXIT
git_as_owner diff --name-only -z "$base_sha" "$release_sha" > "$temporary_dir/changed"
git_as_owner diff --diff-filter=A --name-only -z "$base_sha" "$release_sha" > "$temporary_dir/added"

declare -A repair_paths=()
changed_count=0
validate_path() {
    local relative="$1" component candidate="$project_path"
    local -a components=()
    [[ -n "$relative" && "$relative" != /* ]] || fail "Invalid release path: $relative"
    IFS=/ read -r -a components <<< "$relative"
    repair_paths["$project_path"]=1
    for component in "${components[@]}"; do
        [[ -n "$component" && "$component" != . && "$component" != .. ]] || fail "Invalid release path: $relative"
        candidate="$candidate/$component"
        [[ ! -L "$candidate" ]] || fail "Symlink found; no repair applied: $candidate"
        if [[ -e "$candidate" ]]; then
            [[ -d "$candidate" || -f "$candidate" ]] || fail "Unsupported file type: $candidate"
            repair_paths["$candidate"]=1
        fi
    done
}

while IFS= read -r -d '' relative; do
    validate_path "$relative"
    changed_count=$((changed_count + 1))
done < "$temporary_dir/changed"
[[ "$changed_count" = 30 ]] || fail 'Unexpected release path count; no repair applied.'

# These two cache directories are the only additional ownership repairs.
for relative in bootstrap/cache storage/framework/views; do
    validate_path "$relative"
    [[ -d "$project_path/$relative" ]] || fail "Missing cache directory: $relative"
done
# Do not change ownership of unrelated storage/bootstrap ancestors.
unset 'repair_paths['"$project_path/bootstrap"']'
unset 'repair_paths['"$project_path/storage"']'
unset 'repair_paths['"$project_path/storage/framework"']'

app_group="$(id -gn "$app_owner")"
chown --no-dereference -- "$app_owner:$app_group" "${!repair_paths[@]}"
for candidate in "${!repair_paths[@]}"; do
    if [[ -d "$candidate" ]]; then
        as_owner test -w "$candidate" && as_owner test -x "$candidate" || fail "Application owner cannot write directory: $candidate"
    fi
done

# A failed checkout can leave new release files untracked after reset --hard.
# Preserve only exact release copies; never clean unrelated or differing files.
leftovers=()
while IFS= read -r -d '' relative; do
    [[ -e "$project_path/$relative" ]] || continue
    [[ -f "$project_path/$relative" ]] || fail "Unexpected added-path directory: $relative"
    if git_as_owner ls-files --error-unmatch -- "$relative" >/dev/null 2>&1; then
        fail "Added release path is already tracked: $relative"
    fi
    git_as_owner check-ignore --quiet -- "$relative" && fail "Added release path is ignored; preserve it manually: $relative"
    expected_blob="$(git_as_owner rev-parse "$release_sha:$relative")"
    previous_blob="$(git_as_owner rev-parse --verify "$previous_release_sha:$relative" 2>/dev/null || true)"
    actual_blob="$(git_as_owner hash-object --no-filters -- "$relative")"
    [[ "$actual_blob" = "$expected_blob" || ( -n "$previous_blob" && "$actual_blob" = "$previous_blob" ) ]] || fail "Untracked file differs from reviewed releases; preserved: $relative"
    leftovers+=("$relative")
done < "$temporary_dir/added"

if [[ "${#leftovers[@]}" -gt 0 ]]; then
    git_as_owner -c user.name='Order board deployment' -c user.email='deployment@localhost' stash push --include-untracked -m "order-board checkout leftovers $(date -u +%Y%m%dT%H%M%SZ)" -- "${leftovers[@]}"
    echo "Saved matching checkout leftovers: $(git_as_owner rev-parse refs/stash)"
    for relative in "${leftovers[@]}"; do
        [[ ! -e "$project_path/$relative" ]] || fail "Checkout leftover still present: $relative"
    done
fi

installer="$temporary_dir/apply_order_board.sh"
git_as_owner show "$release_sha:deployment/apply_order_board.sh" > "$installer"
chown --no-dereference -- "$app_owner:$app_group" "$temporary_dir" "$installer"
as_owner bash "$installer" "$release_sha"
