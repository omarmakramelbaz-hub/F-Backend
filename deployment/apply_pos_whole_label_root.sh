#!/usr/bin/env bash
set -euo pipefail
umask 077

revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
whole_label_root=/home/fasakha/public_html
whole_label_php=/opt/cpanel/ea-php82/root/usr/bin/php
whole_label_source=deployment/apply_pos_whole_label.py
[[ -x "$whole_label_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
whole_label_tmp=$(mktemp -d /tmp/pos-whole-label.XXXXXXXX)
trap 'rm -rf -- "$whole_label_tmp"' EXIT
chmod 0755 "$whole_label_tmp"

runuser -u fasakha -- git -C "$whole_label_root" cat-file -e "$revision^{commit}"
whole_label_blob=$(runuser -u fasakha -- git -C "$whole_label_root" rev-parse "$revision:$whole_label_source")
[[ "$whole_label_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$whole_label_root" show "$revision:$whole_label_source" > "$whole_label_tmp/apply.py"
[[ $(git hash-object --no-filters "$whole_label_tmp/apply.py") = "$whole_label_blob" ]] || {
  printf '%s\n' 'INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$whole_label_tmp/apply.py"
runuser -u fasakha -- python3 "$whole_label_tmp/apply.py" \
  --root "$whole_label_root" --revision "$revision" --php "$whole_label_php"
