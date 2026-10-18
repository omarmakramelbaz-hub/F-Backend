#!/usr/bin/env bash
set -euo pipefail
umask 077

home_revision=${1:?Full revision is required}
[[ "$home_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
home_root=/home/fasakha/public_html
home_php=/opt/cpanel/ea-php82/root/usr/bin/php
home_base=d7b0f1cb122251adc34fb62f9a0be7a203660ffc
home_source=deployment/apply_home_stock_minimum.py
[[ -x "$home_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$home_root" cat-file -e "$home_revision^{commit}"
# The release was fetched by the calling command. Fetch only its pinned base
# object if absent; there are no other release or installer prerequisites.
if ! runuser -u fasakha -- git -C "$home_root" cat-file -e "$home_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$home_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$home_base"
fi
runuser -u fasakha -- git -C "$home_root" merge-base --is-ancestor "$home_base" "$home_revision"
home_blob=$(runuser -u fasakha -- git -C "$home_root" rev-parse "$home_revision:$home_source")
[[ "$home_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
home_tmp=$(mktemp -d /tmp/home-stock-minimum.XXXXXXXX)
trap 'rm -rf -- "$home_tmp"' EXIT
chmod 0755 "$home_tmp"
runuser -u fasakha -- git -C "$home_root" show "$home_revision:$home_source" > "$home_tmp/apply.py"
[[ $(git hash-object --no-filters "$home_tmp/apply.py") == "$home_blob" ]] || {
  printf '%s\n' 'HOME_STOCK_MINIMUM_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$home_tmp/apply.py"
home_options=()
if [[ $# == 2 ]]; then home_options+=(--check); fi
runuser -u fasakha -- python3 "$home_tmp/apply.py" \
  --root "$home_root" --revision "$home_revision" --php "$home_php" "${home_options[@]}"
