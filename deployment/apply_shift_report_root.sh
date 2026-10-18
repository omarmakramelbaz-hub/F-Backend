#!/usr/bin/env bash
set -euo pipefail
umask 077
shift_revision=${1:?Full revision is required}
[[ "$shift_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 && $(hostname -s) == srv1836524 ]] || exit 1
shift_root=/home/fasakha/public_html
shift_php=/opt/cpanel/ea-php82/root/usr/bin/php
[[ -x "$shift_php" ]] || exit 1
shift_tmp=$(mktemp -d /tmp/shift-report-install.XXXXXXXX)
trap 'rm -rf -- "$shift_tmp"' EXIT
chmod 0755 "$shift_tmp"
shift_source=deployment/apply_shift_report.py
shift_blob=$(runuser -u fasakha -- git -C "$shift_root" rev-parse "$shift_revision:$shift_source")
[[ "$shift_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
runuser -u fasakha -- git -C "$shift_root" show "$shift_revision:$shift_source" > "$shift_tmp/apply.py"
[[ $(git hash-object --no-filters "$shift_tmp/apply.py") == "$shift_blob" ]] || exit 1
chmod 0644 "$shift_tmp/apply.py"
shift_options=()
if [[ $# == 2 ]]; then shift_options+=(--check); fi
runuser -u fasakha -- python3 "$shift_tmp/apply.py" --root "$shift_root" --revision "$shift_revision" --php "$shift_php" "${shift_options[@]}"
