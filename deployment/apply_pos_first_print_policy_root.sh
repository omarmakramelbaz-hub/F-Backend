#!/usr/bin/env bash
set -euo pipefail
umask 077

revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
first_print_root=/home/fasakha/public_html
first_print_php=/opt/cpanel/ea-php82/root/usr/bin/php
first_print_source=deployment/apply_pos_first_print_policy.py
[[ -x "$first_print_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
first_print_tmp=$(mktemp -d /tmp/pos-first-print-policy.XXXXXXXX)
trap 'rm -rf -- "$first_print_tmp"' EXIT
chmod 0755 "$first_print_tmp"

runuser -u fasakha -- git -C "$first_print_root" cat-file -e "$revision^{commit}"
first_print_blob=$(runuser -u fasakha -- git -C "$first_print_root" rev-parse "$revision:$first_print_source")
[[ "$first_print_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$first_print_root" show "$revision:$first_print_source" > "$first_print_tmp/apply.py"
[[ $(git hash-object --no-filters "$first_print_tmp/apply.py") = "$first_print_blob" ]] || {
  printf '%s\n' 'INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$first_print_tmp/apply.py"
runuser -u fasakha -- python3 "$first_print_tmp/apply.py" \
  --root "$first_print_root" --revision "$revision" --php "$first_print_php"
