#!/usr/bin/env bash
set -euo pipefail
umask 077

view_revision=${1:?Full revision is required}
[[ "$view_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 && $(hostname -s) == srv1836524 ]] || {
  printf '%s\n' 'Run this installer as root on srv1836524.'
  exit 1
}
view_root=/home/fasakha/public_html
view_php=/opt/cpanel/ea-php82/root/usr/bin/php
view_source=deployment/apply_branch_view_directives.py
[[ -x "$view_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
runuser -u fasakha -- git -C "$view_root" cat-file -e "$view_revision^{commit}"
view_blob=$(runuser -u fasakha -- git -C "$view_root" rev-parse "$view_revision:$view_source")
[[ "$view_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
view_tmp=$(mktemp -d /tmp/branch-view-directives.XXXXXXXX)
trap 'rm -rf -- "$view_tmp"' EXIT
chmod 0755 "$view_tmp"
runuser -u fasakha -- git -C "$view_root" show "$view_revision:$view_source" > "$view_tmp/apply.py"
[[ $(git hash-object --no-filters "$view_tmp/apply.py") == "$view_blob" ]] || {
  printf '%s\n' 'BRANCH_VIEW_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$view_tmp/apply.py"
view_options=()
if [[ $# == 2 ]]; then view_options+=(--check); fi
runuser -u fasakha -- python3 "$view_tmp/apply.py" \
  --root "$view_root" --revision "$view_revision" --php "$view_php" "${view_options[@]}"
