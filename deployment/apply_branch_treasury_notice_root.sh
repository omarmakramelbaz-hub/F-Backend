#!/usr/bin/env bash
set -euo pipefail
umask 077

treasury_revision=${1:?Full revision is required}
[[ "$treasury_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
treasury_root=/home/fasakha/public_html
treasury_php=/opt/cpanel/ea-php82/root/usr/bin/php
treasury_base=15aabf297985dfdd53a62fcc137fffb903dfe11f
treasury_source=deployment/apply_branch_treasury_notice.py
[[ -x "$treasury_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$treasury_root" cat-file -e "$treasury_revision^{commit}"
# The release was fetched by the calling command. Fetch only its pinned base
# object if absent; there are no other release or installer prerequisites.
if ! runuser -u fasakha -- git -C "$treasury_root" cat-file -e "$treasury_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$treasury_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$treasury_base"
fi
runuser -u fasakha -- git -C "$treasury_root" merge-base --is-ancestor "$treasury_base" "$treasury_revision"
treasury_blob=$(runuser -u fasakha -- git -C "$treasury_root" rev-parse "$treasury_revision:$treasury_source")
[[ "$treasury_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
treasury_tmp=$(mktemp -d /tmp/branch-treasury-notice.XXXXXXXX)
trap 'rm -rf -- "$treasury_tmp"' EXIT
chmod 0755 "$treasury_tmp"
runuser -u fasakha -- git -C "$treasury_root" show "$treasury_revision:$treasury_source" > "$treasury_tmp/apply.py"
[[ $(git hash-object --no-filters "$treasury_tmp/apply.py") == "$treasury_blob" ]] || {
  printf '%s\n' 'TREASURY_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$treasury_tmp/apply.py"
treasury_options=()
if [[ $# == 2 ]]; then treasury_options+=(--check); fi
runuser -u fasakha -- python3 "$treasury_tmp/apply.py" \
  --root "$treasury_root" --revision "$treasury_revision" --php "$treasury_php" "${treasury_options[@]}"
