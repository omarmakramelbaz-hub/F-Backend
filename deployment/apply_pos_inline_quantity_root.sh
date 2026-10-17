#!/usr/bin/env bash
set -euo pipefail
umask 077
revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
quantity_root=/home/fasakha/public_html
quantity_php=/opt/cpanel/ea-php82/root/usr/bin/php
quantity_source=deployment/apply_pos_inline_quantity.py
[[ -x "$quantity_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
quantity_tmp=$(mktemp -d /tmp/pos-inline-quantity.XXXXXXXX)
trap 'rm -rf -- "$quantity_tmp"' EXIT
chmod 0755 "$quantity_tmp"

runuser -u fasakha -- git -C "$quantity_root" cat-file -e "$revision^{commit}"
for quantity_base in 74a0070907d9ffbaa0f3b5aa7b6082bc1c345878 4d20f13dc3dd2b64d63660d09ad88553c7f3affe; do
  if ! runuser -u fasakha -- git -C "$quantity_root" cat-file -e "$quantity_base^{commit}" 2>/dev/null; then
    runuser -u fasakha -- git -C "$quantity_root" fetch \
      --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$quantity_base"
    [[ $(runuser -u fasakha -- git -C "$quantity_root" rev-parse FETCH_HEAD) = "$quantity_base" ]] || exit 1
  fi
  [[ $(runuser -u fasakha -- git -C "$quantity_root" rev-parse "$quantity_base^{commit}") = "$quantity_base" ]] || exit 1
done
quantity_blob=$(runuser -u fasakha -- git -C "$quantity_root" rev-parse "$revision:$quantity_source")
[[ "$quantity_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$quantity_root" show "$revision:$quantity_source" > "$quantity_tmp/apply.py"
[[ $(git hash-object --no-filters "$quantity_tmp/apply.py") = "$quantity_blob" ]] || {
  printf '%s\n' 'INLINE_QUANTITY_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$quantity_tmp/apply.py"
runuser -u fasakha -- python3 "$quantity_tmp/apply.py" \
  --root "$quantity_root" --revision "$revision" --php "$quantity_php"
