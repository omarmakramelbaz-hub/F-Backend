#!/usr/bin/env bash
set -euo pipefail
umask 077

branches_revision=${1:?Full revision is required}
[[ "$branches_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
branches_root=/home/fasakha/public_html
branches_php=/opt/cpanel/ea-php82/root/usr/bin/php
branches_base=754bf038af50dcade0d43f2f6b537a5b155de4d0
branches_source=deployment/apply_dashboard_all_branches.py
[[ -x "$branches_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$branches_root" cat-file -e "$branches_revision^{commit}"
if ! runuser -u fasakha -- git -C "$branches_root" cat-file -e "$branches_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$branches_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$branches_base"
fi
runuser -u fasakha -- git -C "$branches_root" merge-base --is-ancestor "$branches_base" "$branches_revision"
branches_blob=$(runuser -u fasakha -- git -C "$branches_root" rev-parse "$branches_revision:$branches_source")
[[ "$branches_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
branches_tmp=$(mktemp -d /tmp/dashboard-all-branches.XXXXXXXX)
trap 'rm -rf -- "$branches_tmp"' EXIT
chmod 0755 "$branches_tmp"
runuser -u fasakha -- git -C "$branches_root" show "$branches_revision:$branches_source" > "$branches_tmp/apply.py"
[[ $(git hash-object --no-filters "$branches_tmp/apply.py") == "$branches_blob" ]] || {
  printf '%s\n' 'DASHBOARD_ALL_BRANCHES_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$branches_tmp/apply.py"
branches_options=()
if [[ $# == 2 ]]; then branches_options+=(--check); fi
runuser -u fasakha -- python3 "$branches_tmp/apply.py" \
  --root "$branches_root" --revision "$branches_revision" --php "$branches_php" "${branches_options[@]}"
