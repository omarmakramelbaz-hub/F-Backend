#!/usr/bin/env bash
set -euo pipefail
umask 077

revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
[[ $# = 1 || ( $# = 2 && $2 = --check ) ]] || exit 1
menu_layout_root=/home/fasakha/public_html
menu_layout_php=/opt/cpanel/ea-php82/root/usr/bin/php
menu_layout_base=4189747822205224ced35b7980abe511af534540
menu_layout_source=deployment/apply_phone_delivery_menu_layout.py
[[ -x "$menu_layout_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
menu_layout_tmp=$(mktemp -d /tmp/phone-delivery-menu-layout.XXXXXXXX)
trap 'rm -rf -- "$menu_layout_tmp"' EXIT
chmod 0755 "$menu_layout_tmp"

runuser -u fasakha -- git -C "$menu_layout_root" cat-file -e "$revision^{commit}"
runuser -u fasakha -- git -C "$menu_layout_root" merge-base --is-ancestor "$menu_layout_base" "$revision"
menu_layout_blob=$(runuser -u fasakha -- git -C "$menu_layout_root" rev-parse "$revision:$menu_layout_source")
[[ "$menu_layout_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$menu_layout_root" show "$revision:$menu_layout_source" > "$menu_layout_tmp/apply.py"
[[ $(git hash-object --no-filters "$menu_layout_tmp/apply.py") = "$menu_layout_blob" ]] || {
  printf '%s\n' 'PHONE_DELIVERY_MENU_LAYOUT_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$menu_layout_tmp/apply.py"
menu_layout_options=()
if [[ ${2:-} = --check ]]; then
  menu_layout_options+=(--check)
fi
runuser -u fasakha -- python3 "$menu_layout_tmp/apply.py" \
  --root "$menu_layout_root" --revision "$revision" --php "$menu_layout_php" "${menu_layout_options[@]}"
