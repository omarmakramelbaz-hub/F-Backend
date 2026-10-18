#!/usr/bin/env bash
set -euo pipefail
umask 077

fulfillment_revision=${1:?Full revision is required}
[[ "$fulfillment_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
fulfillment_root=/home/fasakha/public_html
fulfillment_php=/opt/cpanel/ea-php82/root/usr/bin/php
fulfillment_base=fe1bce0209b8e56390557522dfa63e94ef9735b3
fulfillment_source=deployment/apply_store_shubra_fulfillment.py
[[ -x "$fulfillment_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$fulfillment_root" cat-file -e "$fulfillment_revision^{commit}"
if ! runuser -u fasakha -- git -C "$fulfillment_root" cat-file -e "$fulfillment_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$fulfillment_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$fulfillment_base"
fi
runuser -u fasakha -- git -C "$fulfillment_root" merge-base --is-ancestor "$fulfillment_base" "$fulfillment_revision"
fulfillment_blob=$(runuser -u fasakha -- git -C "$fulfillment_root" rev-parse "$fulfillment_revision:$fulfillment_source")
[[ "$fulfillment_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
fulfillment_tmp=$(mktemp -d /tmp/store-shubra-fulfillment.XXXXXXXX)
trap 'rm -rf -- "$fulfillment_tmp"' EXIT
chmod 0755 "$fulfillment_tmp"
runuser -u fasakha -- git -C "$fulfillment_root" show "$fulfillment_revision:$fulfillment_source" > "$fulfillment_tmp/apply.py"
[[ $(git hash-object --no-filters "$fulfillment_tmp/apply.py") == "$fulfillment_blob" ]] || {
  printf '%s\n' 'STORE_SHUBRA_FULFILLMENT_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$fulfillment_tmp/apply.py"
fulfillment_options=()
if [[ $# == 2 ]]; then fulfillment_options+=(--check); fi
runuser -u fasakha -- python3 "$fulfillment_tmp/apply.py" \
  --root "$fulfillment_root" --revision "$fulfillment_revision" --php "$fulfillment_php" "${fulfillment_options[@]}"
