#!/usr/bin/env bash
set -euo pipefail
umask 077

revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
picker_root=/home/fasakha/public_html
picker_php=/opt/cpanel/ea-php82/root/usr/bin/php
picker_source=deployment/apply_pos_preparation_picker.py
[[ -x "$picker_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
picker_tmp=$(mktemp -d /tmp/pos-preparation-picker.XXXXXXXX)
trap 'rm -rf -- "$picker_tmp"' EXIT
chmod 0755 "$picker_tmp"

runuser -u fasakha -- git -C "$picker_root" cat-file -e "$revision^{commit}"
picker_blob=$(runuser -u fasakha -- git -C "$picker_root" rev-parse "$revision:$picker_source")
[[ "$picker_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$picker_root" show "$revision:$picker_source" > "$picker_tmp/apply.py"
[[ $(git hash-object --no-filters "$picker_tmp/apply.py") = "$picker_blob" ]] || {
  printf '%s\n' 'INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$picker_tmp/apply.py"
runuser -u fasakha -- python3 "$picker_tmp/apply.py" \
  --root "$picker_root" --revision "$revision" --php "$picker_php"
