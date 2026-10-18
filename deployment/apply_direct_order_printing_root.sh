#!/usr/bin/env bash
set -euo pipefail
umask 077

revision=${1:?Full revision is required}
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $(id -u) = 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
direct_print_root=/home/fasakha/public_html
direct_print_php=/opt/cpanel/ea-php82/root/usr/bin/php
direct_print_source=deployment/apply_pos_direct_print_policy.py
[[ -x "$direct_print_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null
direct_print_tmp=$(mktemp -d /tmp/direct-order-printing.XXXXXXXX)
trap 'rm -rf -- "$direct_print_tmp"' EXIT
chmod 0755 "$direct_print_tmp"

runuser -u fasakha -- git -C "$direct_print_root" cat-file -e "$revision^{commit}"
direct_print_blob=$(runuser -u fasakha -- git -C "$direct_print_root" rev-parse "$revision:$direct_print_source")
[[ "$direct_print_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$direct_print_root" show "$revision:$direct_print_source" > "$direct_print_tmp/apply.py"
[[ $(git hash-object --no-filters "$direct_print_tmp/apply.py") = "$direct_print_blob" ]] || {
  printf '%s\n' 'INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$direct_print_tmp/apply.py"
runuser -u fasakha -- python3 "$direct_print_tmp/apply.py" \
  --root "$direct_print_root" --revision "$revision" --php "$direct_print_php" --check
runuser -u fasakha -- python3 "$direct_print_tmp/apply.py" \
  --root "$direct_print_root" --revision "$revision" --php "$direct_print_php"
