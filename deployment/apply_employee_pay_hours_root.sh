#!/usr/bin/env bash
set -euo pipefail
umask 077

pay_hours_revision=${1:?Full revision is required}
[[ "$pay_hours_revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
[[ $# -le 2 && ( $# -eq 1 || "$2" == --check ) ]] || exit 1
[[ $(id -u) == 0 ]] || { printf '%s\n' 'Run this installer as root.'; exit 1; }
pay_hours_root=/home/fasakha/public_html
pay_hours_php=/opt/cpanel/ea-php82/root/usr/bin/php
pay_hours_base=14f030e50de11fb83c4bed4a4ee3d2353492f4d5
pay_hours_source=deployment/apply_employee_pay_hours.py
[[ -x "$pay_hours_php" ]] || { printf '%s\n' 'PHP82_EXECUTABLE_REQUIRED'; exit 1; }
command -v python3 >/dev/null

runuser -u fasakha -- git -C "$pay_hours_root" cat-file -e "$pay_hours_revision^{commit}"
if ! runuser -u fasakha -- git -C "$pay_hours_root" cat-file -e "$pay_hours_base^{commit}" 2>/dev/null; then
  runuser -u fasakha -- git -C "$pay_hours_root" fetch \
    --quiet --no-tags --no-prune --no-recurse-submodules --refmap= origin "$pay_hours_base"
fi
runuser -u fasakha -- git -C "$pay_hours_root" merge-base --is-ancestor "$pay_hours_base" "$pay_hours_revision"
pay_hours_blob=$(runuser -u fasakha -- git -C "$pay_hours_root" rev-parse "$pay_hours_revision:$pay_hours_source")
[[ "$pay_hours_blob" =~ ^[0-9a-f]{40}$ ]] || exit 1
pay_hours_tmp=$(mktemp -d /tmp/employee-pay-hours.XXXXXXXX)
trap 'rm -rf -- "$pay_hours_tmp"' EXIT
chmod 0755 "$pay_hours_tmp"
runuser -u fasakha -- git -C "$pay_hours_root" show "$pay_hours_revision:$pay_hours_source" > "$pay_hours_tmp/apply.py"
[[ $(git hash-object --no-filters "$pay_hours_tmp/apply.py") == "$pay_hours_blob" ]] || {
  printf '%s\n' 'EMPLOYEE_PAY_HOURS_INSTALLER_BLOB_MISMATCH'
  exit 1
}
chmod 0644 "$pay_hours_tmp/apply.py"
pay_hours_options=()
if [[ $# == 2 ]]; then pay_hours_options+=(--check); fi
runuser -u fasakha -- python3 "$pay_hours_tmp/apply.py" \
  --root "$pay_hours_root" --revision "$pay_hours_revision" --php "$pay_hours_php" "${pay_hours_options[@]}"
