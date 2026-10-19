#!/usr/bin/env bash
set -euo pipefail
umask 077

expense_revision=${1:?Full revision is required}
[[ "$expense_revision" =~ ^[0-9a-f]{40}$ ]]
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]]
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
[[ $(hostname -s) == srv1836524 ]] || { printf '%s\n' 'UNEXPECTED_HOST'; exit 1; }
expense_root=/home/fasakha/public_html
expense_php=/opt/cpanel/ea-php82/root/usr/bin/php
expense_source=deployment/apply_expense_reject_button.py
expense_uid=$(id -u fasakha)
[[ -d "$expense_root" && ! -L "$expense_root" ]]
[[ $(realpath -- "$expense_root") == "$expense_root" ]]
[[ $(stat -c '%u' -- "$expense_root") == "$expense_uid" ]]
[[ -x "$expense_php" ]]
command -v python3 >/dev/null

# The outer launch command fetches the exact release before invoking this wrapper.
runuser -u fasakha -- git -C "$expense_root" cat-file -e "$expense_revision^{commit}"
expense_blob=$(runuser -u fasakha -- git -C "$expense_root" rev-parse "$expense_revision:$expense_source")
[[ "$expense_blob" =~ ^[0-9a-f]{40}$ ]]
expense_tmp=$(mktemp -d /tmp/expense-reject-button.XXXXXXXX)
trap 'rm -rf -- "$expense_tmp"' EXIT
chmod 0755 "$expense_tmp"
runuser -u fasakha -- git -C "$expense_root" show "$expense_revision:$expense_source" > "$expense_tmp/apply.py"
[[ $(git hash-object --no-filters "$expense_tmp/apply.py") == "$expense_blob" ]] || {
  printf '%s\n' 'EXPENSE_REJECT_BUTTON_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$expense_tmp/apply.py"
expense_options=()
if [[ $# == 2 ]]; then expense_options+=(--check); fi
runuser -u fasakha -- python3 "$expense_tmp/apply.py" \
  --root "$expense_root" --revision "$expense_revision" --php "$expense_php" "${expense_options[@]}"
