#!/usr/bin/env bash
set -euo pipefail
umask 077

basic_revision=${1:?Full revision is required}
[[ "$basic_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
basic_root=/home/fasakha/public_html
basic_php=/opt/cpanel/ea-php82/root/usr/bin/php
basic_base=a1a2764cab407a8bc2a871403dfcdee304bba220
basic_source=deployment/apply_branch_basic_recipes.py
[[ -x "$basic_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$basic_root" cat-file -e "$basic_revision^{commit}"
if ! runuser -u fasakha -- git -C "$basic_root" cat-file -e "$basic_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$basic_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$basic_base"
fi
runuser -u fasakha -- git -C "$basic_root" merge-base --is-ancestor "$basic_base" "$basic_revision"
basic_blob=$(runuser -u fasakha -- git -C "$basic_root" rev-parse "$basic_revision:$basic_source")
[[ "$basic_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
basic_tmp=$(mktemp -d /tmp/branch-basic-recipes.XXXXXXXX)
trap 'rm -rf -- "$basic_tmp"' EXIT
chmod 0755 "$basic_tmp"
runuser -u fasakha -- git -C "$basic_root" show "$basic_revision:$basic_source" > "$basic_tmp/apply.py"
[[ $(git hash-object --no-filters "$basic_tmp/apply.py") == "$basic_blob" ]] || {
  printf '%s\n' 'BASIC_RECIPES_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$basic_tmp/apply.py"
basic_options=()
if [[ $# == 2 ]]; then basic_options+=(--check); fi
runuser -u fasakha -- python3 "$basic_tmp/apply.py" \
  --root "$basic_root" --revision "$basic_revision" --php "$basic_php" "${basic_options[@]}"
