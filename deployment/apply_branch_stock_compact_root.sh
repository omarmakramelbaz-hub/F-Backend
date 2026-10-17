#!/usr/bin/env bash
set -euo pipefail
umask 077

stock_revision=${1:?Full revision is required}
[[ "$stock_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
stock_root=/home/fasakha/public_html
stock_php=/opt/cpanel/ea-php82/root/usr/bin/php
stock_base=c15094916fee7aefb123de069547111310f17afb
stock_source=deployment/apply_branch_stock_compact.py
[[ -x "$stock_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$stock_root" cat-file -e "$stock_revision^{commit}"
# The release was fetched by the calling command. Fetch only its pinned base
# object if absent; there are no other release or installer prerequisites.
if ! runuser -u fasakha -- git -C "$stock_root" cat-file -e "$stock_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$stock_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$stock_base"
fi
runuser -u fasakha -- git -C "$stock_root" merge-base --is-ancestor "$stock_base" "$stock_revision"
stock_blob=$(runuser -u fasakha -- git -C "$stock_root" rev-parse "$stock_revision:$stock_source")
[[ "$stock_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
stock_tmp=$(mktemp -d /tmp/branch-stock-compact.XXXXXXXX)
trap 'rm -rf -- "$stock_tmp"' EXIT
chmod 0755 "$stock_tmp"
runuser -u fasakha -- git -C "$stock_root" show "$stock_revision:$stock_source" > "$stock_tmp/apply.py"
[[ $(git hash-object --no-filters "$stock_tmp/apply.py") == "$stock_blob" ]] || {
  printf '%s\n' 'STOCK_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$stock_tmp/apply.py"
stock_options=()
if [[ $# == 2 ]]; then stock_options+=(--check); fi
runuser -u fasakha -- python3 "$stock_tmp/apply.py" \
  --root "$stock_root" --revision "$stock_revision" --php "$stock_php" "${stock_options[@]}"
